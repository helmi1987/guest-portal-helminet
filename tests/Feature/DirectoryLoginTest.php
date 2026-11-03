<?php

namespace Tests\Feature;

use App\Auth\Directory\DirectoryClient;
use App\Auth\Directory\DirectoryException;
use App\Auth\Directory\DirectorySettings;
use App\Auth\Social\LoginMethods;
use App\Enums\GrantType;
use App\Federation\FederatedSecrets;
use App\Federation\Topology;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Instance;
use App\Models\LoginAttempt;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\FakeDirectoryClient;
use Tests\TestCase;

/**
 * Guest login with the site's Active Directory / LDAP account (CONCEPT.md §7.2 E, decision 2026-11-03,
 * v0.24.0): method availability per site, the login, lockouts, error mapping, the admin section with the
 * encrypted service account password and the check button.
 */
class DirectoryLoginTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private const PORTAL = 'https://portal.example.com';

    private const DIRECTORY = [
        'enabled' => true, 'host' => 'dc1.example.com', 'port' => 636, 'tls' => 'ldaps', 'verify' => true, 'ca_cert' => '',
        'base_dn' => 'DC=example,DC=com', 'bind_dn' => 'CN=svc-portal,OU=Service,DC=example,DC=com',
        'user_filter' => DirectorySettings::DEFAULT_FILTER, 'group_dn' => '', 'name_attributes' => ['displayName'],
        'email_attribute' => 'mail', 'timeout_s' => 5, 'duration_s' => null,
    ];

    private Site $site;

    private TermsVersion $terms;

    private FakeDirectoryClient $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => self::PORTAL, 'radius_address' => '10.0.0.10']]);
        $this->terms = TermsVersion::publish('en', 'Be nice.');
        $this->directory = (new FakeDirectoryClient)
            ->addUser('anna', 'correct horse', ['displayName' => 'Anna Maria Muster', 'mail' => 'anna@example.com',
                'memberOf' => ['CN=Guest-WiFi,OU=Groups,DC=example,DC=com']])
            ->addUser('ben', 'battery staple', ['displayName' => 'Beispiel, Ben', 'sn' => 'Beispiel'])
            ->addUser('locked', 'whatever', [], restricted: true);
        $this->app->instance(DirectoryClient::class, $this->directory);
    }

    // --- Availability -----------------------------------------------------------------------------

    public function test_method_is_hidden_without_configuration_and_offered_per_site(): void
    {
        $this->assertArrayHasKey(LoginMethods::ACCOUNT, LoginMethods::all());
        $this->assertFalse(LoginMethods::all()[LoginMethods::ACCOUNT]);
        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::ACCOUNT => true]);
        // Switched on, but no directory: not offered.
        $this->enter()->assertOk()->assertSee('name="code"', false)->assertDontSee(__('portal.guest.choose.account'));
        $this->assertFalse(LoginMethods::available($this->site)[LoginMethods::ACCOUNT]);

        $this->configure();
        $this->assertTrue(LoginMethods::available($this->site)[LoginMethods::ACCOUNT]);
        // Chooser order: code, account, ...
        $page = $this->enter()->assertOk()->assertSee('id="gp-methods"', false)->getContent();
        $this->assertLessThan(strpos($page, __('portal.guest.choose.account')), strpos($page, __('portal.guest.choose.code')));
        $this->assertStringContainsString('/portal?method=account', $page);
        $this->guest()->get('/portal?method=account')->assertOk()->assertSee('gp-step-portal-account', false)
            ->assertSee('action="'.route('guest.account').'"', false)->assertSee('name="username"', false)
            ->assertSee('name="password"', false)->assertSee('autocomplete="current-password"', false)
            ->assertSee('name="terms"', false)->assertDontSee('name="first_name"', false)->assertDontSee('portal.guest.');

        // Another site without a directory does not offer it; only method left: its page directly.
        Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7', 'settings' => ['hotspot_hosts' => ['hotspot-b.example.com']]]);
        $this->guest()->get(self::PORTAL.'/portal?'.http_build_query(['site' => 'site-b', 'mac' => self::MAC,
            'link-login-only' => 'https://hotspot-b.example.com/login']))->assertOk()->assertDontSee(__('portal.guest.choose.account'));
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.method_disabled.title'));

        // Only method switched on: the account page is shown directly.
        LoginMethods::save([LoginMethods::CODE => false, LoginMethods::SMS => false, LoginMethods::APPROVAL => false, LoginMethods::ACCOUNT => true]);
        $this->enter()->assertOk()->assertSee('name="username"', false)->assertDontSee('id="gp-methods"', false);
    }

    public function test_server_side_checks_and_limited_mode(): void
    {
        $this->configure();
        $this->enter();
        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::ACCOUNT => false]);
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertForbidden();

        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::ACCOUNT => true]);
        $this->site->update(['settings' => DirectorySettings::mergeInto($this->site, ['enabled' => false], null)]);
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertForbidden();
        $this->site->update(['settings' => DirectorySettings::mergeInto($this->site->fresh(), ['enabled' => true], null)]);

        // Hub unreachable: like social login, not offered.
        $hub = Instance::create(['name' => 'Hub', 'federation_url' => 'https://10.0.0.9:9443']);
        Setting::put(Topology::HUB_SETTING, $hub->id);
        $this->assertFalse(LoginMethods::available($this->site)[LoginMethods::ACCOUNT]);
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertForbidden();
        $this->assertSame([], $this->directory->connections);
        $this->assertSame(0, Guest::count());
    }

    // --- Login ------------------------------------------------------------------------------------

    public function test_successful_login_creates_guest_account_grant_and_handoff(): void
    {
        $this->configure(['group_dn' => 'cn=guest-wifi, ou=groups, dc=example, dc=com', 'duration_s' => 7200]);
        $this->enter();

        $this->guest()->post('/portal/account', $this->form('  anna ', 'correct horse'))->assertOk()->assertViewHas('fields');

        $guest = Guest::sole();
        $this->assertSame(['Anna Maria', 'Muster', 'anna@example.com', 'en', null],
            [$guest->first_name, $guest->last_name, $guest->email, $guest->locale, $guest->social_provider]);
        $grant = Grant::sole();
        $this->assertSame(GrantType::Account, $grant->type);
        $this->assertSame('Site A', $grant->label);
        $this->assertSame(7200, $grant->duration_s);
        $this->assertTrue($grant->allowsSite($this->site->id));
        $this->assertEqualsWithDelta(now()->addSeconds(7200)->getTimestamp(), $grant->expires_at->getTimestamp(), 5);
        $acceptance = TermsAcceptance::sole();
        $this->assertSame([$guest->id, $this->terms->id, $grant->id, self::MAC],
            [$acceptance->guest_id, $acceptance->terms_version_id, $acceptance->grant_id, $acceptance->mac]);
        $this->assertTrue(LoginAttempt::query()->where('method', 'account')->where('success', true)->exists());

        // Service bind, then the user's own bind; the filter carries the escaped user name.
        $this->assertSame([$this->directory->serviceDn, 'CN=anna,OU=Staff,DC=example,DC=com'], $this->directory->binds);
        $this->assertSame(['(&(objectClass=user)(sAMAccountName=anna))'], $this->directory->filters);
        $this->assertSame('service-secret', $this->directory->connections[0]->bindPassword);

        // Audit: site and user name only.
        $audit = AuditLog::query()->where('action', 'guest.account_login')->sole();
        $this->assertSame(['site' => 'site-a', 'username' => 'anna'], $audit->data);
        $this->assertSame($grant->id, $audit->subject_id);
        $this->assertStringNotContainsString('correct horse', json_encode(AuditLog::all()->toArray()));
        $this->assertStringNotContainsString('correct horse', (string) json_encode(DB::table('federation_events')->pluck('payload')));

        // Each login gets a new grant; the global default applies when the site has none.
        $this->site->update(['settings' => DirectorySettings::mergeInto($this->site->fresh(), ['duration_s' => null, 'group_dn' => ''], null)]);
        DirectorySettings::saveDurationS(3600);
        $this->guest()->post('/portal/account', $this->form('ben', 'battery staple'))->assertOk()->assertViewHas('fields');
        $second = Grant::query()->where('type', GrantType::Account)->latest('created_at')->orderByDesc('id')->get()
            ->firstWhere('id', '!=', $grant->id);
        $this->assertSame(3600, $second->duration_s);
        $this->assertSame(['Ben', 'Beispiel'], [Guest::latest('id')->first()->first_name, Guest::latest('id')->first()->last_name]);
    }

    public function test_wrong_password_gives_a_generic_error_and_locks_out(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->configure();
        $this->enter();

        foreach (['wrong', 'also wrong'] as $password) {
            $this->guest()->from('/portal?method=account')->post('/portal/account', $this->form('anna', $password))
                ->assertRedirect('/portal?method=account')->assertSessionHasErrors(['username' => __('portal.guest.errors.account_invalid')]);
        }
        // Unknown user: the same message; the password is never flashed back.
        $this->guest()->post('/portal/account', $this->form('nobody', 'secret-guess'))
            ->assertSessionHasErrors(['username' => __('portal.guest.errors.account_invalid')])
            ->assertSessionHasInput('username', 'nobody')->assertSessionMissing('_old_input.password');
        // Empty password: never sent to the directory (would be an unauthenticated bind).
        $binds = count($this->directory->binds);
        $this->guest()->post('/portal/account', $this->form('anna', ''))->assertSessionHasErrors('password');
        $this->assertCount($binds, $this->directory->binds);
        $this->assertSame(3, LoginAttempt::query()->where('method', 'account')->where('success', false)->count());

        // Per MAC (5 failures): locked.
        $this->guest()->post('/portal/account', $this->form('ben', 'x'))->assertSessionHasErrors('username');
        $this->guest()->post('/portal/account', $this->form('ben', 'y'))->assertSessionHasErrors('username');
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertStatus(429)
            ->assertSee(__('portal.guest.error_pages.locked.title'));
        $this->assertSame(0, Guest::count());
    }

    public function test_attempts_per_account_are_limited_across_devices(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['portal.login_limits.per_mac' => 100, 'portal.login_limits.per_ip' => 100]);
        $this->configure();
        for ($i = 1; $i <= 5; $i++) {
            $this->enter(['mac' => sprintf('AA:BB:CC:00:01:%02d', $i)]);
            $this->guest()->post('/portal/account', $this->form('ANNA', 'guess-'.$i))->assertSessionHasErrors('username');
        }
        // Sixth try for the same account (case-insensitive), even with the right password: locked.
        $this->enter(['mac' => 'AA:BB:CC:00:01:99']);
        $binds = count($this->directory->binds);
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertStatus(429);
        $this->assertCount($binds, $this->directory->binds);
        // Other accounts are not affected.
        $this->guest()->post('/portal/account', $this->form('ben', 'battery staple'))->assertOk()->assertViewHas('fields');
    }

    public function test_route_is_throttled_per_ip(): void
    {
        $this->configure();
        $this->enter();
        for ($i = 0; $i < 5; $i++) {
            $this->guest()->post('/portal/account', $this->form('nobody', 'x'))->assertStatus(302);
        }
        $this->guest()->post('/portal/account', $this->form('nobody', 'x'))->assertStatus(429);
    }

    public function test_restricted_accounts_and_group_membership(): void
    {
        $this->configure(['group_dn' => 'CN=Guest-WiFi,OU=Groups,DC=example,DC=com']);
        $this->enter();

        $this->guest()->post('/portal/account', $this->form('locked', 'whatever'))
            ->assertSessionHasErrors(['username' => __('portal.guest.errors.account_restricted')]);
        // Right password, but not in the group.
        $this->guest()->post('/portal/account', $this->form('ben', 'battery staple'))
            ->assertSessionHasErrors(['username' => __('portal.guest.errors.account_not_allowed')]);
        // Wrong password of a non-member: the generic message (membership is checked after the bind).
        $this->guest()->post('/portal/account', $this->form('ben', 'wrong'))
            ->assertSessionHasErrors(['username' => __('portal.guest.errors.account_invalid')]);
        $this->assertSame(0, Guest::count());
        $this->assertSame(3, LoginAttempt::query()->where('method', 'account')->where('success', false)->count());

        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertOk()->assertViewHas('fields');
        $this->assertSame(1, Guest::count());
    }

    public function test_directory_down_shows_the_unavailable_page_and_is_not_counted(): void
    {
        $this->configure();
        $this->enter();
        foreach ([DirectoryException::UNREACHABLE, DirectoryException::TLS] as $reason) {
            $this->directory->connectFailure = $reason;
            $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertStatus(503)
                ->assertSee(__('portal.guest.error_pages.account_unavailable.title'));
        }
        $this->directory->connectFailure = null;
        $this->directory->searchFailure = DirectoryException::SEARCH_FAILED;
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertStatus(503);
        $this->directory->searchFailure = null;
        // Service account rejected: also "unavailable", never "wrong password".
        $this->directory->servicePassword = 'rotated-elsewhere';
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertStatus(503);

        $this->assertSame(0, LoginAttempt::count());
        $this->assertSame(0, Guest::count());
        $this->assertFalse($this->directory->connected);
    }

    public function test_terms_must_be_accepted_in_the_current_version(): void
    {
        $this->configure();
        $this->enter();
        $this->guest()->post('/portal/account', ['username' => 'anna', 'password' => 'correct horse'])->assertSessionHasErrors('terms');
        $this->guest()->post('/portal/account', ['terms_version' => 'old'] + $this->form('anna', 'correct horse'))->assertSessionHasErrors('terms');
        $this->assertSame([], $this->directory->connections);
    }

    // --- Admin ------------------------------------------------------------------------------------

    public function test_admin_saves_the_directory_with_an_encrypted_masked_password(): void
    {
        $admin = User::factory()->admin()->create();
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()
            ->assertSee(__('portal.directory.title'))->assertSee('name="directory[bind_password]"', false)
            ->assertSee(__('portal.directory.not_ready'));

        $this->saveDirectory($admin, ['bind_password' => 'Svc pass 1!'])->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.sites.edit', $this->site).'#directory');
        $site = $this->site->fresh();
        $raw = DB::table('sites')->where('id', $site->id)->value('settings');
        $this->assertStringNotContainsString('Svc pass 1!', $raw);
        $this->assertStringStartsWith('fdk1:', $site->setting('directory.bind_password'));
        $this->assertSame('Svc pass 1!', DirectorySettings::password($site));
        $this->assertTrue(DirectorySettings::isConfigured($site));
        $this->assertSame(['dc1.example.com', 636, 'ldaps', true, ['displayName', 'cn'], 28800],
            array_values(array_intersect_key(DirectorySettings::forSite($site), array_flip(['host', 'port', 'tls', 'verify', 'name_attributes', 'duration_s']))));
        $audit = AuditLog::query()->where('action', 'site.directory_updated')->sole();
        $this->assertTrue($audit->data['credential_changed']);
        $this->assertStringNotContainsString('Svc pass 1!', json_encode($audit->data));
        $this->assertStringNotContainsString($site->setting('directory.bind_password'), json_encode($audit->data));

        // The page never renders the password; an empty field keeps it.
        $page = $this->admin($admin)->get("/admin/sites/{$site->id}/edit")->assertOk()
            ->assertSee(__('portal.directory.password_stored'))->assertSee(__('portal.directory.ready'))->getContent();
        $this->assertStringNotContainsString('Svc pass 1!', $page);
        $this->assertStringNotContainsString((string) $site->setting('directory.bind_password'), $page);
        $this->saveDirectory($admin, ['bind_password' => '', 'host' => 'DC2.example.com'])->assertSessionHasNoErrors();
        $this->assertSame('Svc pass 1!', DirectorySettings::password($this->site->fresh()));
        $this->assertSame('dc2.example.com', DirectorySettings::forSite($this->site->fresh())['host']);
        $this->assertFalse(AuditLog::query()->where('action', 'site.directory_updated')->latest('id')->first()->data['credential_changed']);

        // The general site form keeps the directory.
        $this->admin($admin)->put("/admin/sites/{$site->id}", ['name' => 'Site A', 'slug' => 'site-a', 'driver' => 'mikrotik_ros7',
            'hotspot_hosts' => 'hotspot.example.com', 'portal_url' => self::PORTAL])->assertSessionHasNoErrors();
        $this->assertSame('Svc pass 1!', DirectorySettings::password($this->site->fresh()));

        // Remove the password: a service account without one is refused while enabled.
        $this->saveDirectory($admin, ['bind_password_clear' => '1'])->assertSessionHasErrors('directory.bind_password');
        $this->saveDirectory($admin, ['bind_password_clear' => '1', 'bind_dn' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->site->fresh()->setting('directory.bind_password'));

        // Spaces at the ends of the service password are kept.
        $this->saveDirectory($admin, ['bind_password' => ' Svc pass 2 '])->assertSessionHasNoErrors();
        $this->assertSame(' Svc pass 2 ', DirectorySettings::password($this->site->fresh()));

        // Unencrypted: saved with a warning.
        $this->saveDirectory($admin, ['tls' => 'none', 'port' => 389, 'bind_dn' => ''])->assertSessionHas('warning', __('portal.directory.tls_none_warning'));

        // Managers have no access.
        $manager = User::factory()->create();
        $this->admin($manager)->put("/admin/sites/{$site->id}/directory", ['directory' => self::input()])->assertForbidden();
        $this->admin($manager)->post("/admin/sites/{$site->id}/directory/check")->assertForbidden();
    }

    public function test_directory_input_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $cases = [
            'directory.host' => ['host' => ''],
            'directory.port' => ['port' => 70000],
            'directory.tls' => ['tls' => 'ssl'],
            'directory.base_dn' => ['base_dn' => 'not a dn'],
            'directory.bind_dn' => ['bind_dn' => 'svc-portal'],
            'directory.user_filter' => ['user_filter' => '(sAMAccountName=fixed)'],
            'directory.name_attributes' => ['name_attributes' => 'display name'],
            'directory.email_attribute' => ['email_attribute' => 'mail;x'],
            'directory.ca_cert' => ['ca_cert' => "-----BEGIN CERTIFICATE-----\nnope\n-----END CERTIFICATE-----"],
            'directory.timeout_s' => ['timeout_s' => 0],
        ];
        foreach ($cases as $field => $input) {
            $this->saveDirectory($admin, $input + ['bind_password' => 'Svc pass 1!'])->assertSessionHasErrors($field)
                ->assertSessionMissing('_old_input.directory.bind_password');
        }
        foreach (['0.05', '721'] as $hours) {
            $this->saveDirectory($admin, ['duration_hours' => $hours, 'bind_password' => 'x'])
                ->assertSessionHasErrors(['directory.duration_hours' => __('portal.directory.errors.duration')]);
        }
        foreach (['(&(uid={username})', '(uid={username}))(', 'uid={username}'] as $filter) {
            $this->saveDirectory($admin, ['user_filter' => $filter, 'bind_password' => 'x'])->assertSessionHasErrors('directory.user_filter');
        }
        $this->saveDirectory($admin, ['bind_password' => ''])->assertSessionHasErrors('directory.bind_password');
        $this->assertSame([], (array) $this->site->fresh()->setting('directory', []));
        // Disabled: host and base DN may stay empty.
        $this->saveDirectory($admin, ['enabled' => '0', 'host' => '', 'base_dn' => '', 'bind_dn' => ''])->assertSessionHasNoErrors();
        $this->assertFalse(DirectorySettings::isConfigured($this->site->fresh()));
    }

    public function test_check_button_reports_the_result_without_the_password(): void
    {
        $admin = User::factory()->admin()->create();
        $this->saveDirectory($admin, ['bind_password' => 'service-secret'])->assertSessionHasNoErrors();
        $check = fn () => $this->admin($admin)->post("/admin/sites/{$this->site->id}/directory/check")
            ->assertRedirect(route('admin.sites.edit', $this->site).'#directory');

        $check()->assertSessionHas('directory_check', ['status' => 'ok', 'message' => __('portal.directory.check.ok')]);
        $this->assertSame(['(&(objectClass=user)(sAMAccountName=portal-directory-check))'], $this->directory->filters);

        $this->directory->servicePassword = 'other';
        $check()->assertSessionHas('directory_check.status', DirectoryException::SERVICE_BIND_FAILED);
        $this->directory->servicePassword = 'service-secret';
        $this->directory->searchFailure = DirectoryException::SEARCH_FAILED;
        $check()->assertSessionHas('directory_check.status', DirectoryException::SEARCH_FAILED);
        $this->directory->connectFailure = DirectoryException::TLS;
        $check()->assertSessionHas('directory_check', ['status' => 'tls', 'message' => __('portal.directory.check.tls')]);
        $this->directory->connectFailure = DirectoryException::UNREACHABLE;
        $check()->assertSessionHas('directory_check.status', DirectoryException::UNREACHABLE);

        $statuses = AuditLog::query()->where('action', 'site.directory_checked')->orderBy('id')->get()->pluck('data.status')->all();
        $this->assertSame(['ok', 'service_bind_failed', 'search_failed', 'tls', 'unreachable'], $statuses);
        $this->assertStringNotContainsString('service-secret', json_encode(AuditLog::all()->toArray()));

        // The result is shown on the site page.
        $this->admin($admin)->get("/admin/sites/{$this->site->id}/edit")->assertOk()->assertSee('data-directory-check="unreachable"', false);

        // Incomplete without a request.
        $this->saveDirectory($admin, ['enabled' => '0', 'host' => '', 'bind_dn' => '']);
        $connections = count($this->directory->connections);
        $check()->assertSessionHas('directory_check.status', 'incomplete');
        $this->assertCount($connections, $this->directory->connections);
    }

    public function test_service_password_survives_a_data_key_rotation(): void
    {
        $this->configure();
        $before = $this->site->fresh()->setting('directory.bind_password');

        app(FederatedSecrets::class)->rotate();

        $after = $this->site->fresh()->setting('directory.bind_password');
        $this->assertNotSame($before, $after);
        $this->assertSame('service-secret', DirectorySettings::password($this->site->fresh()));
    }

    public function test_settings_show_the_hint_and_save_the_default_duration(): void
    {
        $admin = User::factory()->admin()->create();
        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::ACCOUNT => true]);
        $this->admin($admin)->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.settings.login_methods.methods.account'))
            ->assertSee(__('portal.settings.login_methods.hint_account'))
            ->assertSee('name="account_duration_hours"', false);
        $this->configure();
        $this->admin($admin)->get('/admin/settings')->assertOk()->assertDontSee(__('portal.settings.login_methods.hint_account'));

        $methods = ['code' => '1', 'account' => '1'];
        $this->admin($admin)->post('/admin/settings/login-methods', ['methods' => $methods, 'account_duration_hours' => '0.01'])
            ->assertSessionHasErrors('account_duration_hours');
        $this->admin($admin)->post('/admin/settings/login-methods', ['methods' => $methods, 'account_duration_hours' => '12'])
            ->assertSessionHasNoErrors();
        $this->assertSame(43200, DirectorySettings::durationS());
        $this->assertSame(43200, DirectorySettings::siteDurationS($this->site->fresh()));
    }

    public function test_guest_list_shows_the_account_method_with_the_site(): void
    {
        $this->configure();
        $this->enter();
        $this->guest()->post('/portal/account', $this->form('anna', 'correct horse'))->assertOk();
        $admin = User::factory()->admin()->create();

        $this->admin($admin)->get('/admin/guests')->assertOk()
            ->assertSee(__('portal.guests.method_account', ['site' => 'Site A']))->assertSee('anna@example.com');
        $this->admin($admin)->get('/admin/guests?method=account')->assertOk()->assertSee('Muster');
        $this->admin($admin)->get('/admin/guests?method=social')->assertOk()->assertDontSee('Muster');
        $this->admin($admin)->get('/admin/audit?group=guest')->assertOk()->assertSee('guest.account_login');
    }

    // --- Helpers ----------------------------------------------------------------------------------

    private function configure(array $overrides = []): void
    {
        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::ACCOUNT => true]);
        $this->site->update(['settings' => DirectorySettings::mergeInto($this->site->fresh(), $overrides + self::DIRECTORY, 'service-secret')]);
        $this->site->refresh();
        $this->assertTrue(DirectorySettings::isConfigured($this->site));
    }

    /** Form input of the site section (overrides by field name). */
    private static function input(array $overrides = []): array
    {
        return array_merge(['enabled' => '1', 'host' => 'dc1.example.com', 'port' => '636', 'tls' => 'ldaps', 'verify' => '1', 'ca_cert' => '',
            'base_dn' => 'DC=example,DC=com', 'bind_dn' => 'CN=svc-portal,OU=Service,DC=example,DC=com', 'bind_password' => '',
            'user_filter' => DirectorySettings::DEFAULT_FILTER, 'group_dn' => '', 'name_attributes' => 'displayName, cn',
            'email_attribute' => 'mail', 'timeout_s' => '5', 'duration_hours' => '8'], $overrides);
    }

    private function saveDirectory(User $admin, array $overrides): TestResponse
    {
        return $this->admin($admin)->put("/admin/sites/{$this->site->id}/directory", ['directory' => self::input($overrides)]);
    }

    private function admin(User $user): static
    {
        return $this->onZone('admin')->actingAs($user);
    }

    private function guest(): static
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en');
    }

    private function enter(array $extra = []): TestResponse
    {
        return $this->guest()->get(self::PORTAL.'/portal?'.http_build_query(['site' => 'site-a', 'mac' => self::MAC,
            'link-login-only' => 'https://hotspot.example.com/login'] + $extra));
    }

    private function form(string $username, string $password): array
    {
        return ['username' => $username, 'password' => $password, 'terms' => '1', 'terms_version' => $this->terms->id];
    }
}
