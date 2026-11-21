<?php

namespace Tests\Feature;

use App\Jobs\DisconnectSession;
use App\Models\AuditLog;
use App\Models\LearningAttempt;
use App\Models\LearningWindow;
use App\Models\MacWhitelist;
use App\Models\NasClient;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use App\Nas\RouterOs\ApiConnector;
use App\Nas\RouterOs\Menus;
use App\Nas\RouterOs\RouterOsException;
use App\Support\LocalInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Unit\RouterOs\FakeApiConnection;

/**
 * Re-authentication by the portal and learning window of the MAC whitelist (decision 2026-11-21, v0.33.0):
 * own router script in the API access block and the router state, running it through the read-only API access,
 * the triggers, and RADIUS during and after a learning window.
 */
class RouterReauthLearningTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:20';

    private const PIN = 'sha256//AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    private const KEY = 'guest-portal:api:reauth';

    private Site $site;

    private NasClient $nas;

    private User $admin;

    /** @var list<array{0: string, 1: array}> REST requests to the fake router (URL path, body) */
    private array $requests = [];

    /** Rows of /system script on the fake REST router. */
    private array $scripts = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.com']);
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot-a.guest.example.com'], 'radius_address' => '10.0.0.10']]);
        $this->nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
        $this->admin = User::factory()->admin()->create();
        Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);
    }

    private function admin(?User $user = null): static
    {
        return $this->onZone('admin')->actingAs($user ?? $this->admin);
    }

    private function enable(bool $on = true): void
    {
        $this->admin()->post("/admin/sites/{$this->site->id}/setup/reauth", $on ? ['router_reauth' => '1'] : [])->assertRedirect();
        $this->site->refresh();
    }

    private function configureApi(string $type = 'rest'): void
    {
        $this->nas->fill(['api_host' => '10.0.1.1', 'api_type' => $type, 'api_user' => 'portal-ro', 'api_password' => 'Test pass 1',
            'api_pin' => self::PIN])->save();
        $this->nas->refresh();
    }

    /** Fake REST router: /system script print answers $scripts, /system/script/run is recorded. */
    private function fakeRest(array $scripts): void
    {
        $this->scripts = $scripts;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->requests[] = [$path, $request->data()];

            return match ($path) {
                '/rest/system/script/print' => Http::response($this->scripts),
                '/rest/system/script/run' => Http::response([]),
                default => Http::response(['error' => 400, 'detail' => 'unexpected'], 400),
            };
        });
    }

    private function runs(): array
    {
        return array_values(array_map(fn ($r) => $r[1]['.id'] ?? null, array_filter($this->requests, fn ($r) => $r[0] === '/rest/system/script/run')));
    }

    private function radius(string $endpoint, array $attributes, ?NasClient $nas = null): TestResponse
    {
        $attributes['FreeRADIUS-Client-Shortname'] = ($nas ?? $this->nas)->shortname();
        $body = array_map(fn ($v) => ['type' => 'string', 'value' => [(string) $v]], $attributes);

        return $this->onZone('internal')
            ->withHeader('Authorization', 'Basic '.base64_encode('radius:test-internal-token'))
            ->postJson('/internal/radius/'.$endpoint, $body);
    }

    private function macAuth(string $mac, ?NasClient $nas = null): TestResponse
    {
        return $this->radius('authorize', ['User-Name' => $mac, 'User-Password' => '', 'Calling-Station-Id' => $mac], $nas);
    }

    private function start(string $mac, string $sessionId, string $ip = '10.5.50.30'): void
    {
        $this->radius('accounting', ['Acct-Status-Type' => 'Start', 'Acct-Session-Id' => $sessionId, 'User-Name' => $mac,
            'Calling-Station-Id' => $mac, 'Framed-IP-Address' => $ip])->assertNoContent();
    }

    private function openWindow(int $minutes = 15): TestResponse
    {
        return $this->admin()->post('/admin/whitelist-learning', ['site_id' => $this->site->id, 'minutes' => $minutes]);
    }

    public function test_access_block_adds_the_script_only_while_switched_on_and_the_uninstall_removes_it(): void
    {
        $driver = $this->site->driver();
        $off = $driver->apiAccessCommands($this->site, $this->nas);
        $this->assertStringContainsString('/system script remove [find where name=guest-portal-reauth && comment="'.self::KEY.'"]', $off);
        $this->assertStringNotContainsString('/system script add', $off);
        $this->assertArrayNotHasKey(self::KEY, $driver->managedObjects($this->site)['api']);

        $this->enable();
        $this->assertTrue($this->site->routerReauth());
        $on = $driver->apiAccessCommands($this->site, $this->nas);
        $this->assertStringContainsString('/system script add name=guest-portal-reauth policy=read,write dont-require-permissions=yes'
            .' source="/ip hotspot host remove [find where server=guest-portal && !authorized && !bypassed]" comment="'.self::KEY.'"', $on);
        $this->assertStringContainsString('/system script set [find where name=guest-portal-reauth && comment="'.self::KEY.'"] policy=read,write', $on);
        $this->assertStringNotContainsString('/system script remove', $on);
        // Inside the guarded unit (aborts with the placeholder left), the API group stays read-only
        $this->assertLessThan(strpos($on, '/system script add'), strpos($on, ':error'));
        $this->assertLessThan(strrpos($on, '}'), strpos($on, '/system script add'));
        $this->assertStringContainsString('/user group add name=guest-portal-ro policy=read,api,rest-api comment=', $on);
        $this->assertSame(['path' => '/system script', 'name' => 'guest-portal-reauth'], $driver->managedObjects($this->site)['api'][self::KEY]);
        // Never "provision", nothing of others
        $this->assertDoesNotMatchRegularExpression('/\bprovision\b/', $on);

        $uninstall = $driver->setupCommands($this->site, [])['uninstall']['uninstall'];
        $this->assertStringContainsString('/system script remove [find where name=guest-portal-reauth && comment="'.self::KEY.'"]', $uninstall);

        $this->enable(false);
        $this->assertFalse($this->site->routerReauth());
        $this->assertSame([false, true], AuditLog::query()->where('action', 'site.setup_reauth')->orderBy('id')->get()
            ->map(fn ($a) => $a->data['enabled'])->reverse()->values()->all());
        // The setting is admin only
        $this->admin(User::factory()->create())->post("/admin/sites/{$this->site->id}/setup/reauth", ['router_reauth' => '1'])->assertForbidden();
        $page = $this->admin()->get("/admin/sites/{$this->site->id}/setup")->assertOk()->getContent();
        $this->assertStringContainsString('data-router-reauth', $page);
    }

    public function test_router_state_compares_the_script_with_the_setting(): void
    {
        $driver = $this->site->driver();
        $state = fn (array $rows) => ['menus' => ['/system script' => ['rows' => $rows]]];
        $own = ['.id' => '*5', 'name' => 'guest-portal-reauth', 'policy' => 'read,write', 'dont-require-permissions' => 'true', 'comment' => self::KEY];

        // Off: absent is fine, present is a deviation of the API block
        $this->assertArrayNotHasKey(self::KEY, $driver->compareState($this->site, $state([]))['api']);
        $item = $driver->compareState($this->site, $state([$own]))['api'][self::KEY];
        $this->assertSame('differs', $item['status']);

        $this->enable();
        $this->assertSame('missing', $driver->compareState($this->site, $state([]))['api'][self::KEY]['status']);
        $this->assertSame('present', $driver->compareState($this->site, $state([$own]))['api'][self::KEY]['status']);
        $item = $driver->compareState($this->site, $state([['dont-require-permissions' => 'false', 'policy' => 'read,write,test'] + $own]))['api'][self::KEY];
        $this->assertSame('differs', $item['status']);
        $this->assertEqualsCanonicalizing(['dont-require-permissions', 'policy'], array_keys($item['diff']));
        // A script of others with that name (other comment) is not the own one
        $this->assertSame('missing', $driver->compareState($this->site, $state([['comment' => 'foreign'] + $own]))['api'][self::KEY]['status']);
    }

    public function test_reauth_button_runs_only_the_own_script_through_rest(): void
    {
        $entry = MacWhitelist::create(['mac' => self::MAC, 'comment' => 'Printer', 'active' => true]);
        $this->enable();
        $this->configureApi();
        $this->fakeRest([['.id' => '*5', 'name' => 'guest-portal-reauth', 'comment' => self::KEY, 'dont-require-permissions' => 'true']]);

        $this->admin()->post("/admin/whitelist/{$entry->id}/reauth")->assertRedirect()
            ->assertSessionHas('reauth', fn ($r) => $r['queued'] === ['Site A / Router A'] && $r['manual'] === []);
        $this->assertSame(['*5'], $this->runs());
        // Only a print of the own script name and the run: nothing else is sent
        $this->assertSame(['/rest/system/script/print', '/rest/system/script/run'], array_column($this->requests, 0));
        $this->assertSame(['name=guest-portal-reauth'], $this->requests[0][1]['.query']);
        $audit = AuditLog::query()->where('action', 'router.reauth')->sole();
        $this->assertTrue($audit->data['ok']);
        $this->assertSame(['site' => 'site-a', 'name' => 'Router A', 'reason' => 'whitelist', 'mac' => self::MAC, 'ok' => true], $audit->data);
        $this->assertSame($this->admin->id, $audit->user_id);

        // Manager may press it too
        $this->admin(User::factory()->create())->post("/admin/whitelist/{$entry->id}/reauth")->assertRedirect();
        $this->assertCount(2, $this->runs());

        // Script of others with the same name (other comment, or without dont-require-permissions): never run
        foreach ([['comment' => 'foreign'], ['dont-require-permissions' => 'false']] as $i => $change) {
            $this->requests = [];
            $this->fakeRest([$change + ['.id' => '*7', 'name' => 'guest-portal-reauth', 'comment' => self::KEY, 'dont-require-permissions' => 'true']]);
            $this->admin()->post("/admin/whitelist/{$entry->id}/reauth")->assertRedirect();
            $this->assertSame([], $this->runs(), (string) $i);
            $last = AuditLog::query()->where('action', 'router.reauth')->latest('id')->first();
            $this->assertFalse($last->data['ok']);
            $this->assertSame('config', $last->data['error']);
        }

        // Inactive entry: nothing to re-authenticate
        $entry->update(['active' => false]);
        $this->requests = [];
        $this->admin()->post("/admin/whitelist/{$entry->id}/reauth")->assertSessionHasErrors('reauth');
        $this->assertSame([], $this->requests);
    }

    public function test_saving_an_entry_triggers_the_reauth_or_shows_the_command(): void
    {
        $this->configureApi();
        $this->fakeRest([['.id' => '*5', 'name' => 'guest-portal-reauth', 'comment' => self::KEY, 'dont-require-permissions' => 'true']]);

        // Feature off: no API call, the command for the MAC is shown
        $this->admin()->post('/admin/whitelist', ['mac' => self::MAC, 'comment' => 'Printer', 'active' => '1', 'site_ids' => [$this->site->id]])
            ->assertRedirect()->assertSessionHas('reauth', fn ($r) => $r['queued'] === [] && $r['manual'] === ['Site A / Router A']
                && $r['command'] === '/ip hotspot host remove [find where server=guest-portal && mac-address='.self::MAC.']');
        $this->assertSame([], $this->requests);
        $entry = MacWhitelist::sole();
        $page = $this->admin()->from('/admin/whitelist')->followingRedirects()->post("/admin/whitelist/{$entry->id}/reauth")->assertOk()->getContent();
        $this->assertStringContainsString('data-reauth-manual', $page);
        $this->assertStringContainsString(e('/ip hotspot host remove [find where server=guest-portal && mac-address='.self::MAC.']'), $page);

        // Feature on: update and activation run the script; deactivation does not
        $this->enable();
        $this->admin()->put("/admin/whitelist/{$entry->id}", ['mac' => self::MAC, 'comment' => 'Printer 2', 'active' => '1'])->assertRedirect();
        $this->assertCount(1, $this->runs());
        $this->admin()->post("/admin/whitelist/{$entry->id}/toggle")->assertRedirect();
        $this->assertCount(1, $this->runs());
        $this->admin()->post("/admin/whitelist/{$entry->id}/toggle")->assertRedirect();
        $this->assertCount(2, $this->runs());

        // Router without API access: command instead
        $this->nas->forceFill(['api_host' => null])->save();
        $this->admin()->post("/admin/whitelist/{$entry->id}/reauth")
            ->assertSessionHas('reauth', fn ($r) => $r['queued'] === [] && $r['manual'] === ['Site A / Router A']);
        $this->assertCount(2, $this->runs());
    }

    public function test_reauth_through_api_ssl(): void
    {
        $this->enable();
        $this->configureApi('api-ssl');
        $router = FakeApiConnection::router('portal-ro', 'Test pass 1', ['/system script' => [
            ['name' => 'guest-portal-reauth', 'comment' => self::KEY, 'dont-require-permissions' => 'true']]]);
        $fake = new FakeApiConnection(function (array $s) use ($router) {
            return ($s[0] ?? '') === '/system/script/run' ? [['!done']] : $router($s);
        }, self::PIN);
        $this->app->instance(ApiConnector::class, FakeApiConnection::connector($fake));
        $entry = MacWhitelist::create(['mac' => self::MAC, 'comment' => 'Printer', 'active' => true]);

        $this->admin()->post("/admin/whitelist/{$entry->id}/reauth")->assertRedirect();
        $this->assertSame(['/login', '/system/script/print', '/system/script/run'], array_map(fn ($s) => $s[0], $fake->received));
        $this->assertSame(['/system/script/run', '=.id=*1'], end($fake->received));
        $this->assertTrue(AuditLog::query()->where('action', 'router.reauth')->sole()->data['ok']);
        // A refused run (no permission) is an error of kind "denied"
        $denied = new FakeApiConnection(function (array $s) use ($router) {
            return ($s[0] ?? '') === '/system/script/run' ? [['!trap', '=message=not enough permissions (9)'], ['!done']] : $router($s);
        }, self::PIN);
        $this->app->instance(ApiConnector::class, FakeApiConnection::connector($denied));
        $this->admin()->post("/admin/whitelist/{$entry->id}/reauth")->assertRedirect();
        $this->assertSame('denied', AuditLog::query()->where('action', 'router.reauth')->latest('id')->first()->data['error']);
    }

    public function test_learning_window_accepts_records_and_takes_over_devices(): void
    {
        Queue::fake([DisconnectSession::class]);
        // Closed: unknown MACs are rejected
        $this->macAuth(self::MAC)->assertStatus(401);

        $this->openWindow(10)->assertRedirect()->assertSessionHas('reauth', fn ($r) => $r['manual'] === ['Site A / Router A']
            && $r['command'] === '/ip hotspot host remove [find where server=guest-portal && !authorized && !bypassed]');
        $this->openWindow(10)->assertSessionHasErrors('site_id');
        $this->admin()->post('/admin/whitelist-learning', ['site_id' => $this->site->id, 'minutes' => 7])->assertSessionHasErrors('minutes');
        $window = LearningWindow::sole();
        $this->assertTrue($window->isOpen());

        $reply = $this->macAuth(self::MAC)->assertOk()->json();
        $this->assertEqualsWithDelta(600, $reply['reply:Session-Timeout'], 2);
        $this->macAuth(self::MAC)->assertOk();
        $this->macAuth('AA:BB:CC:00:00:21')->assertOk();
        // User-Name must still be the Calling-Station-Id; routers of other sites are not covered
        $this->radius('authorize', ['User-Name' => self::MAC, 'User-Password' => '', 'Calling-Station-Id' => 'AA:BB:CC:00:00:99'])->assertStatus(401);
        $other = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']);
        $otherNas = NasClient::find($other->nasClients()->create(['name' => 'Router B', 'nas_ip' => '10.0.2.1', 'coa_port' => 3799])->id);
        $this->macAuth('AA:BB:CC:00:00:22', $otherNas)->assertStatus(401);

        $attempt = LearningAttempt::query()->where('mac', self::MAC)->sole();
        $this->assertSame(2, $attempt->count);
        $this->assertSame($this->nas->id, $attempt->nas_client_id);
        $this->assertSame(2, LearningAttempt::count());

        // Accounting: a session without grant and entry, not disconnected while the window is open
        $this->start(self::MAC, 'S1');
        $this->start('AA:BB:CC:00:00:21', 'S2', '10.5.50.31');
        $this->assertSame(2, SessionSummary::query()->whereNull('grant_id')->whereNull('mac_whitelist_id')->count());
        Artisan::call('portal:enforce-grants');
        Queue::assertNothingPushed();

        $page = $this->admin()->get('/admin/whitelist')->assertOk()->getContent();
        $this->assertStringContainsString('data-learning-window="'.$this->site->id.'"', $page);
        $this->assertStringContainsString('data-learning-attempt="'.self::MAC.'"', $page);

        // Take over: entry for the site, the session belongs to it; the comment is required
        $this->admin()->post("/admin/whitelist-learning/attempts/{$attempt->id}/take-over", [])->assertSessionHasErrors('comment');
        $this->admin()->post("/admin/whitelist-learning/attempts/{$attempt->id}/take-over", ['comment' => 'Printer'])->assertRedirect();
        $entry = MacWhitelist::query()->with('siteLinks')->sole();
        $this->assertSame([self::MAC, [$this->site->id]], [$entry->mac, $entry->siteIds()]);
        $this->assertSame(LearningAttempt::TAKEN, $attempt->fresh()->status);
        $this->assertSame($entry->id, SessionSummary::query()->where('mac', self::MAC)->value('mac_whitelist_id'));
        $this->assertNotNull(SessionSummary::query()->where('mac', self::MAC)->value('learning_attempt_id'));
        $this->admin()->post("/admin/whitelist-learning/attempts/{$attempt->id}/take-over", ['comment' => 'Again'])->assertSessionHasErrors('learning');
        $this->assertSame(['whitelist.learning_taken' => false], AuditLog::query()->where('action', 'whitelist.learning_taken')->get()
            ->mapWithKeys(fn ($a) => [$a->action => $a->data['updated']])->all());

        // Close: the device not taken over is disconnected and rejected afterwards; the taken one stays
        $this->admin()->post("/admin/whitelist-learning/{$window->id}/close")->assertRedirect();
        $this->assertFalse($window->fresh()->isOpen());
        Queue::assertPushed(DisconnectSession::class, 1);
        $this->macAuth('AA:BB:CC:00:00:21')->assertStatus(401);
        $this->assertArrayNotHasKey('reply:Session-Timeout', $this->macAuth(self::MAC)->assertOk()->json());
        Artisan::call('portal:enforce-grants');
        Queue::assertPushed(DisconnectSession::class, 2);
        Queue::assertPushed(DisconnectSession::class, fn ($job) => $job->sessionSummaryId === SessionSummary::query()->where('mac', 'AA:BB:CC:00:00:21')->value('id'));

        $this->assertSame(['whitelist.learning_opened', 'whitelist.learning_taken', 'whitelist.learning_closed'],
            AuditLog::query()->where('action', 'like', 'whitelist.learning%')->orderBy('id')->pluck('action')->all());
    }

    public function test_discarded_devices_and_expired_windows_lose_access(): void
    {
        Queue::fake([DisconnectSession::class]);
        $this->openWindow(5)->assertRedirect();
        $this->macAuth(self::MAC)->assertOk();
        $this->start(self::MAC, 'S1');
        $this->macAuth('AA:BB:CC:00:00:21')->assertOk();
        $this->start('AA:BB:CC:00:00:21', 'S2', '10.5.50.31');

        // Discard: disconnected at once, rejected for the rest of the window, no longer listed
        $attempt = LearningAttempt::query()->where('mac', self::MAC)->sole();
        $this->admin()->post("/admin/whitelist-learning/attempts/{$attempt->id}/discard")->assertRedirect();
        $this->assertSame(LearningAttempt::DISCARDED, $attempt->fresh()->status);
        Queue::assertPushed(DisconnectSession::class, 1);
        $this->macAuth(self::MAC)->assertStatus(401);
        $this->assertStringNotContainsString('data-learning-attempt="'.self::MAC.'"', $this->admin()->get('/admin/whitelist')->getContent());

        // Expiry: enforce-grants closes the window (audit) and disconnects the remaining device
        $this->travel(6)->minutes();
        $this->macAuth('AA:BB:CC:00:00:21')->assertStatus(401);
        Artisan::call('portal:enforce-grants');
        $this->assertNotNull(LearningWindow::sole()->closed_at);
        Queue::assertPushed(DisconnectSession::class, fn ($job) => $job->sessionSummaryId === SessionSummary::query()->where('mac', 'AA:BB:CC:00:00:21')->value('id'));
        $this->assertSame(['whitelist.learning_opened', 'whitelist.learning_discarded', 'whitelist.learning_expired'],
            AuditLog::query()->where('action', 'like', 'whitelist.learning%')->orderBy('id')->pluck('action')->all());
        // A new window can be opened again
        $this->openWindow(5)->assertSessionHasNoErrors();
    }

    public function test_opening_a_window_runs_the_reauth_script_when_switched_on(): void
    {
        $this->enable();
        $this->configureApi();
        $this->fakeRest([['.id' => '*5', 'name' => 'guest-portal-reauth', 'comment' => self::KEY, 'dont-require-permissions' => 'true']]);

        $this->openWindow(15)->assertRedirect()->assertSessionHas('reauth', fn ($r) => $r['queued'] === ['Site A / Router A']);
        $this->assertSame(['*5'], $this->runs());
        $this->assertSame('learning', AuditLog::query()->where('action', 'router.reauth')->sole()->data['reason']);
    }

    public function test_learning_window_is_refused_for_unifi_sites_and_sites_of_other_instances(): void
    {
        $unifi = Site::create(['slug' => 'site-u', 'name' => 'Site U', 'driver' => 'unifi']);
        $this->admin()->post('/admin/whitelist-learning', ['site_id' => $unifi->id, 'minutes' => 5])->assertSessionHasErrors('site_id');
        $this->site->forceFill(['home_instance_id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ'])->save();
        $this->openWindow(5)->assertSessionHasErrors('site_id');
        $this->assertSame(0, LearningWindow::count());
        $this->assertSame(MikrotikRos7Driver::REAUTH_SCRIPT, 'guest-portal-reauth');
    }

    public function test_take_over_updates_an_entry_that_does_not_admit_the_device(): void
    {
        $other = Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7']);
        $entry = MacWhitelist::create(['mac' => self::MAC, 'comment' => 'Old tablet', 'active' => false, 'expires_at' => now()->subDay()]);
        $entry->syncSites([$other->id]);
        $this->openWindow(10)->assertRedirect();
        // Inactive, expired and only for another site: accepted during the window like any blocked device
        $this->macAuth(self::MAC)->assertOk();
        $attempt = LearningAttempt::sole();

        $this->admin()->post("/admin/whitelist-learning/attempts/{$attempt->id}/take-over", ['comment' => 'New'])->assertRedirect()->assertSessionHasNoErrors();
        $entry = MacWhitelist::query()->with('siteLinks')->sole();
        $this->assertTrue($entry->active);
        $this->assertNull($entry->expires_at);
        $this->assertEqualsCanonicalizing([$other->id, $this->site->id], $entry->siteIds());
        $this->assertSame('Old tablet', $entry->comment);
        $this->assertSame(LearningAttempt::TAKEN, $attempt->fresh()->status);
        $audit = AuditLog::query()->where('action', 'whitelist.learning_taken')->sole();
        $this->assertTrue($audit->data['updated']);
        $this->assertSame($entry->id, $audit->subject_id);
        // Admitted by the entry now (no Session-Timeout of the window)
        $this->assertArrayNotHasKey('reply:Session-Timeout', $this->macAuth(self::MAC)->assertOk()->json());

        // An entry for all sites stays for all sites
        $all = MacWhitelist::create(['mac' => 'AA:BB:CC:00:00:23', 'comment' => 'All', 'active' => false]);
        $this->macAuth('AA:BB:CC:00:00:23')->assertOk();
        $second = LearningAttempt::query()->where('mac', 'AA:BB:CC:00:00:23')->sole();
        $this->admin()->post("/admin/whitelist-learning/attempts/{$second->id}/take-over", ['comment' => 'x'])->assertSessionHasNoErrors();
        $this->assertSame([], $all->fresh()->load('siteLinks')->siteIds());
        $this->assertTrue($all->fresh()->active);
    }

    public function test_only_learning_sessions_stay_online_while_a_window_is_open(): void
    {
        Queue::fake([DisconnectSession::class]);
        $this->openWindow(10)->assertRedirect();
        // Session of a whitelist entry that was removed (no entry, no grant, no attempt): ended even with a window open
        $removed = SessionSummary::create(['site_id' => $this->site->id, 'serving_instance_id' => app(LocalInstance::class)->id(),
            'mac' => 'AA:BB:CC:00:00:30', 'ip' => '10.5.50.40', 'started_at' => now()]);
        $this->assertTrue($removed->accessEnded());
        $entry = MacWhitelist::create(['mac' => 'AA:BB:CC:00:00:31', 'comment' => 'Gone', 'active' => true]);
        $gone = SessionSummary::create(['mac_whitelist_id' => $entry->id, 'site_id' => $this->site->id,
            'serving_instance_id' => app(LocalInstance::class)->id(), 'mac' => 'AA:BB:CC:00:00:31', 'ip' => '10.5.50.41', 'started_at' => now()]);
        $entry->forceDelete();
        $this->assertTrue($gone->fresh()->accessEnded());
        // Learning session: alive while its attempt is pending and the window open
        $this->macAuth(self::MAC)->assertOk();
        $this->start(self::MAC, 'S1');
        $learning = SessionSummary::query()->where('mac', self::MAC)->sole();
        $this->assertNotNull($learning->learning_attempt_id);
        $this->assertFalse($learning->accessEnded());
        Artisan::call('portal:enforce-grants');
        Queue::assertNotPushed(DisconnectSession::class, fn ($job) => $job->sessionSummaryId === $learning->id);
        Queue::assertPushed(DisconnectSession::class, fn ($job) => $job->sessionSummaryId === $removed->id);
    }

    public function test_open_is_atomic_per_site(): void
    {
        $this->openWindow(5)->assertSessionHasNoErrors();
        $this->openWindow(10)->assertSessionHasErrors('site_id');
        $this->assertSame(1, LearningWindow::query()->open()->count());
    }

    public function test_only_internal_script_ids_can_be_run(): void
    {
        $this->assertSame('*1A', Menus::guardScriptId('*1A'));
        foreach (['guest-portal-reauth', '*1;/system reset', '', '*', '*12345678A'] as $bad) {
            try {
                Menus::guardScriptId($bad);
                $this->fail("accepted: {$bad}");
            } catch (RouterOsException $e) {
                $this->assertSame('config', $e->kind);
            }
        }
    }
}
