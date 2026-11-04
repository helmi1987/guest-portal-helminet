<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SmsMessage;
use App\Models\User;
use App\Sms\SmsProviders;
use App\Sms\SmsSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * One active SMS provider with ordered backups and automatic failover (CONCEPT.md §7.2 B, v0.26.0).
 */
class SmsFailoverTest extends TestCase
{
    use RefreshDatabase;

    private const SEVEN = 'gateway.seven.io/*';

    private const ECALL = 'rest.ecall.ch/*';

    protected function setUp(): void
    {
        parent::setUp();
        Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
    }

    public function test_primary_failure_uses_backup_once_per_outage_and_recovers(): void
    {
        [$primary, $backup] = $this->twoProviders();
        Channel::create(['kind' => 'notification', 'driver' => 'gotify', 'name' => 'Gotify', 'enabled' => true,
            'config' => ['url' => 'https://gotify.example.com', 'app_token' => 'app-token']]);
        Log::spy();
        $sevenUp = false;
        Http::fake([
            self::SEVEN => function () use (&$sevenUp) {
                return $sevenUp ? Http::response(['success' => '100', 'messages' => [['id' => 's1']]]) : Http::response('down', 500);
            },
            self::ECALL => Http::response(['messageId' => 'e1']),
            'gotify.example.com/*' => Http::response(['id' => 1]),
        ]);

        $this->enterPortal();
        $this->requestSms()->assertRedirect('/portal/sms');
        $message = SmsMessage::sole();
        $this->assertSame([SmsMessage::ACCEPTED, $backup->id, 'e1'], [$message->status, $message->channel_id, $message->provider_id]);
        $this->assertNotNull(SmsProviders::downSince($primary));
        $this->assertSame(['primary' => $primary->id, 'used' => $backup->id], array_slice(SmsProviders::outage(), 0, 2));
        $this->assertSame(['primary' => 'Seven', 'used' => 'eCall'], AuditLog::where('action', 'sms.failover')->sole()->data);
        Log::shouldHaveReceived('warning')->with('SMS failover', ['primary' => $primary->id, 'used' => $backup->id])->once();
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://gotify.example.com/message')
            && str_contains($r['message'], 'Seven') && str_contains($r['message'], 'eCall'));
        $this->onZone('guest')->get('/portal/sms')->assertOk(); // challenge stays open

        // Second SMS within the cooldown: the primary is skipped, no second audit or notification.
        $this->travel(2)->minutes();
        $this->enterPortal('AA:BB:CC:00:00:02');
        $this->requestSms('+41 79 000 00 01');
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'seven.io'))->count());
        $this->assertSame(2, SmsMessage::where('channel_id', $backup->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'sms.failover')->count());
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'gotify'))->count());

        // Settings page shows the outage and the cooldown of the primary.
        $admin = User::factory()->admin()->create();
        $page = $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('data-sms-outage', false);
        $page->assertSee(__('portal.channels.sms_outage', ['primary' => 'Seven', 'used' => 'eCall',
            'time' => now()->subMinutes(2)->setTimezone(config('app.timezone'))->format('d.m.Y H:i')]));

        // After the cooldown the primary is tried again and, working, ends the outage.
        $this->travel(SmsSettings::failoverCooldownS() + 1)->seconds();
        $sevenUp = true;
        $this->enterPortal('AA:BB:CC:00:00:03');
        $this->requestSms('+41 79 000 00 02');
        $this->assertSame($primary->id, SmsMessage::latest('id')->first()->channel_id);
        $this->assertNull(SmsProviders::outage());
        $this->assertSame(['primary' => 'Seven'], AuditLog::where('action', 'sms.recovered')->sole()->data);
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertDontSee('data-sms-outage', false);
    }

    public function test_invalid_number_does_not_fail_over(): void
    {
        [$primary] = $this->twoProviders();
        Http::fake([self::SEVEN => Http::response(['success' => '202']), self::ECALL => Http::response(['messageId' => 'e1'])]);

        $this->enterPortal();
        $this->requestSms();
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'ecall'));
        $message = SmsMessage::sole();
        $this->assertSame([SmsMessage::FAILED, $primary->id, 'seven.io rejected the message: code 202'],
            [$message->status, $message->channel_id, $message->error]);
        $this->assertNull(SmsProviders::downSince($primary));
        $this->assertNull(SmsProviders::outage());
        $this->assertSame(0, AuditLog::where('action', 'sms.failover')->count());
    }

    public function test_all_providers_failing_shows_the_sms_failed_page(): void
    {
        [$primary, $backup] = $this->twoProviders();
        $sevenUp = false;
        Http::fake([
            self::SEVEN => function () use (&$sevenUp) {
                return $sevenUp ? Http::response(['success' => '100', 'messages' => [['id' => 's9']]]) : Http::response('', 503);
            },
            self::ECALL => Http::response(['errorCode' => 'InsufficientPoints'], 400),
        ]);

        $this->enterPortal();
        $this->requestSms()->assertRedirect('/portal/sms');
        $message = SmsMessage::sole();
        $this->assertSame([SmsMessage::FAILED, $backup->id, 'eCall send failed: HTTP 400 (code InsufficientPoints)'],
            [$message->status, $message->channel_id, $message->error]);
        $this->assertFalse(OtpChallenge::sole()->isOpen());
        $this->assertSame(0, OtpChallenge::sole()->attempts);
        $this->assertNotNull(SmsProviders::downSince($primary));
        $this->assertNotNull(SmsProviders::downSince($backup));
        $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/portal/sms')->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.sms_failed.title'));

        // Both in cooldown: still tried (the only ones left).
        $sevenUp = true;
        $this->travel(2)->minutes();
        $this->enterPortal('AA:BB:CC:00:00:02');
        $this->requestSms('+41 79 000 00 01');
        $this->assertSame([SmsMessage::ACCEPTED, 's9'], [SmsMessage::latest('id')->first()->status, SmsMessage::latest('id')->first()->provider_id]);
    }

    public function test_existing_channels_keep_their_provider_and_roles_are_managed(): void
    {
        // Before 0.26.0: first enabled by name sent; the others become backups in id (creation) order.
        $b = $this->provider('seven', 'B seven', true);
        $c = $this->provider('ecall', 'C ecall', true);
        $off = $this->provider('aspsms', 'D off', false);
        $a = $this->provider('smsapi', 'A smsapi', true);
        $this->assertSame([$a->id, $b->id, $c->id], SmsProviders::chain()->pluck('id')->all());
        $this->assertSame([$a->id, $b->id, $c->id, $off->id], SmsProviders::ordered()->pluck('id')->all());

        $admin = User::factory()->admin()->create();
        $page = $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk();
        $page->assertSeeInOrder(['A smsapi', 'B seven', 'C ecall', 'D off'])
            ->assertSee(__('portal.channels.sms_primary'))->assertSee(__('portal.channels.sms_backup', ['n' => 1]))
            ->assertSee(__('portal.channels.sms_backup', ['n' => 2]))->assertSee(__('portal.channels.sms_off'))
            ->assertSee(route('admin.channels.sms-primary', $b), false)->assertDontSee(route('admin.channels.sms-primary', $a), false)
            ->assertSee(__('portal.channels.sms_failover_help'));

        // Make C active: the previous active provider becomes the first backup.
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.sms-primary', $c))->assertRedirect('/admin/settings');
        $this->assertSame([$c->id, $a->id, $b->id], SmsProviders::chain()->pluck('id')->all());
        $this->assertSame(SmsProviders::PRIMARY, $c->fresh()->config[SmsProviders::ROLE]);
        $this->assertSame(SmsProviders::BACKUP, $a->fresh()->config[SmsProviders::ROLE]);
        $this->assertSame(1, AuditLog::where('action', 'channel.sms_primary')->count());

        // Move B up above A; the active provider cannot be moved (and nothing moves above it).
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.sms-move', $b), ['direction' => 'up']);
        $this->assertSame([$c->id, $b->id, $a->id], SmsProviders::chain()->pluck('id')->all());
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.sms-move', $b), ['direction' => 'up']);
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.sms-move', $c), ['direction' => 'down']);
        $this->assertSame([$c->id, $b->id, $a->id], SmsProviders::chain()->pluck('id')->all());
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.sms-move', $a), ['direction' => 'sideways'])
            ->assertSessionHasErrors('direction');

        // Saving the form keeps role and order; disabling the active provider makes the next one send.
        $this->onZone('admin')->actingAs($admin)->put(route('admin.channels.update', $c), ['name' => 'C ecall', 'enabled' => '0',
            'config' => ['username' => 'u', 'sender' => 'Portal']])->assertSessionHasNoErrors();
        $this->assertSame(SmsProviders::PRIMARY, $c->fresh()->config[SmsProviders::ROLE]);
        $this->assertSame([$b->id, $a->id], SmsProviders::chain()->pluck('id')->all());

        // A new provider is appended as the last backup.
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.store'), ['driver' => 'gtx', 'name' => 'E gtx',
            'enabled' => '1', 'config' => ['api_key' => 'k-123', 'sender' => 'Portal']])->assertSessionHasNoErrors();
        $this->assertSame([$b->id, $a->id, Channel::where('name', 'E gtx')->sole()->id], SmsProviders::chain()->pluck('id')->all());

        // Free Mobile only reaches its own subscriber: never in the chain, cannot be made active.
        $free = $this->provider('freemobile', 'F free', true);
        $this->assertNotContains($free->id, SmsProviders::chain()->pluck('id')->all());
        $this->onZone('admin')->actingAs($admin)->post(route('admin.channels.sms-primary', $free))->assertNotFound();
    }

    public function test_only_free_mobile_does_not_enable_sms_login(): void
    {
        $this->provider('freemobile', 'Free', true);
        $this->enterPortal()->assertOk()->assertDontSee('/portal?method=sms', false);
    }

    public function test_cooldown_setting_is_validated(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['sms_duration_hours' => 24, 'sms_countries' => 'CH', 'sms_caps' => config('portal.sms.caps')];
        $this->assertSame(300, SmsSettings::failoverCooldownS());
        foreach ([30, 4000, 'x'] as $bad) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_failover_cooldown_s' => $bad])
                ->assertSessionHasErrors('sms_failover_cooldown_s');
        }
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_failover_cooldown_s' => 600])
            ->assertSessionHasNoErrors();
        $this->assertSame([600, 600], [Setting::get('sms_failover_cooldown_s'), SmsSettings::failoverCooldownS()]);
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertSee('name="sms_failover_cooldown_s"', false)
            ->assertSee('value="600"', false);
    }

    /** @return array{Channel, Channel} active seven.io, backup eCall */
    private function twoProviders(): array
    {
        $primary = $this->provider('seven', 'Seven', true);
        $backup = $this->provider('ecall', 'eCall', true);
        SmsProviders::makePrimary($primary);

        return [$primary->fresh(), $backup->fresh()];
    }

    private function provider(string $driver, string $name, bool $enabled): Channel
    {
        $config = match ($driver) {
            'seven' => ['api_key' => 'seven-key'],
            'ecall' => ['username' => 'u', 'password' => 'ecall-pass', 'sender' => 'Portal'],
            'freemobile' => ['login' => '1', 'password' => 'free-key'],
            'aspsms' => ['userkey' => 'K', 'password' => 'asp-pass', 'sender' => 'Portal'],
            default => ['token' => 'token-1'],
        };

        return Channel::create(['kind' => 'sms', 'driver' => $driver, 'name' => $name, 'enabled' => $enabled, 'config' => $config]);
    }

    private function enterPortal(string $mac = 'AA:BB:CC:00:00:01')
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => $mac, 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }

    private function requestSms(string $phone = '+41 79 000 00 00')
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->post('/portal/sms', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1', 'phone' => $phone,
        ]);
    }
}
