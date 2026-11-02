<?php

namespace Tests\Feature;

use App\Approval\Channels\WebhookChannel;
use App\Enums\GrantType;
use App\Models\Channel;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\OtpChallenge;
use App\Models\RadiusToken;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SmsMessage;
use App\Models\User;
use App\Sms\OtpService;
use App\Sms\SmsSettings;
use App\Support\Redact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * M5 core paths: SMS one-time password login, limits, country allowlist and SMS drivers.
 */
class SmsLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+41 79 000 00 00';

    protected function setUp(): void
    {
        parent::setUp();
        Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
    }

    public function test_guest_logs_in_with_sms_code_via_clickatell(): void
    {
        $this->clickatell();
        Http::fake(['platform.clickatell.com/*' => Http::response(['messages' => [['apiMessageId' => 'm1', 'accepted' => true]]])]);

        $this->enterPortal()->assertOk()->assertSee(__('portal.guest.sms_request'));
        $this->requestSms()->assertRedirect('/portal/sms');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://platform.clickatell.com/messages'
            && $r->hasHeader('Authorization', 'test-api-key') && $r['to'] === ['41790000000']);
        $code = $this->sentCode();
        $challenge = OtpChallenge::sole();
        $this->assertSame('+41790000000', $challenge->phone_e164);
        $this->assertNotSame($code, $challenge->code_hash);
        $this->assertSame(['accepted', 'm1', 'AA:BB:CC:00:00:01'], [SmsMessage::sole()->status, SmsMessage::sole()->provider_id, SmsMessage::sole()->mac]);
        $this->onZone('guest')->get('/portal/sms')->assertOk()->assertSee('+4179*****00')->assertDontSee('41790000000');

        $this->verify($code)->assertOk()->assertViewHas('fields');
        $grant = $challenge->fresh()->grant;
        $this->assertSame(GrantType::Sms, $grant->type);
        $this->assertEqualsWithDelta(now()->addDay()->getTimestamp(), $grant->expires_at->getTimestamp(), 5);
        $this->assertTrue($grant->allowsSite(Site::sole()->id));
        $this->assertSame('AA:BB:CC:00:00:01', RadiusToken::sole()->mac);

        // Single use: the code cannot start a second login; the page only repeats the handoff.
        $this->verify($code)->assertRedirect('/portal/sms');
        $this->assertSame([1, 1], [Grant::count(), RadiusToken::count()]);

        // Retention purge covers challenges and sent SMS.
        $this->travel(400)->days();
        Artisan::call('portal:purge');
        $this->assertSame([0, 0], [OtpChallenge::withTrashed()->count(), SmsMessage::count()]);
    }

    public function test_wrong_codes_use_up_tries_and_codes_expire(): void
    {
        $this->clickatell();
        Http::fake();

        $this->enterPortal();
        $this->requestSms();
        $code = $this->sentCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->verify($wrong)->assertSessionHasErrors(['otp' => __('portal.guest.errors.invalid_otp', ['tries' => 2])]);
        $this->verify($wrong)->assertSessionHasErrors('otp');
        $this->verify($wrong)->assertForbidden()->assertSee(__('portal.guest.error_pages.sms_failed.title'));
        $this->verify($code)->assertForbidden();
        $this->assertNull(OtpChallenge::sole()->grant_id);

        $this->travel(2)->minutes(); // route throttle (per IP, shared by the guest POST routes)
        $this->enterPortal('AA:BB:CC:00:00:02');
        $this->requestSms();
        $this->travel(11)->minutes();
        $this->verify($this->sentCode())->assertForbidden();
        $this->assertSame(0, OtpChallenge::whereNotNull('grant_id')->count());
    }

    public function test_country_allowlist_is_enforced_and_configurable(): void
    {
        $this->clickatell();
        Http::fake();
        $this->enterPortal();

        $this->requestSms('+44 7400 000000')->assertSessionHasErrors(['phone' => __('portal.guest.errors.phone_country',
            ['countries' => 'CH, DE, AT, IT, FR'])]);
        $this->requestSms('12')->assertSessionHasErrors(['phone' => __('portal.guest.errors.phone_invalid')]);
        $this->assertSame([0, 0], [SmsMessage::count(), Guest::count()]);
        Http::assertNothingSent();

        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/channels/create?driver=clickatell')->assertOk()
            ->assertSee(__('portal.channels.fields.api_key'));
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', ['sms_duration_hours' => 8, 'sms_countries' => 'ch, gb',
            'sms_caps' => config('portal.sms.caps')])->assertSessionHasNoErrors();
        $this->assertSame(['CH', 'GB'], Setting::get('sms_countries'));
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', ['sms_duration_hours' => 8, 'sms_countries' => 'CHE',
            'sms_caps' => config('portal.sms.caps')])->assertSessionHasErrors('sms_countries');

        $this->requestSms('+44 7400 000000')->assertRedirect('/portal/sms');
        $this->verify($this->sentCode());
        $this->assertSame(28800, OtpChallenge::sole()->grant->duration_s);
    }

    public function test_resend_cooldown_and_cost_caps(): void
    {
        $this->clickatell();
        Http::fake();
        $this->enterPortal();
        $this->requestSms();

        $this->resend()->assertSessionHasErrors(['otp' => __('portal.guest.errors.sms_wait', ['seconds' => 60])]);
        $this->travel(61)->seconds();
        $this->resend()->assertSessionHas('status', __('portal.guest.sms_resent'));
        $this->travel(61)->seconds();
        $this->resend()->assertSessionHasNoErrors();
        $this->travel(61)->seconds();
        $this->resend()->assertSessionHasErrors(['otp' => __('portal.guest.errors.sms_limit')]);
        $this->assertSame(3, SmsMessage::count());
        Http::assertSentCount(3);

        // Only the latest code is valid.
        $this->verify($this->sentCode())->assertOk();

        // Per-phone cap (3 per hour): another device with the same number is refused, nothing is stored.
        $this->enterPortal('AA:BB:CC:00:00:02');
        $this->requestSms()->assertStatus(429)->assertSee(__('portal.guest.error_pages.sms_busy.title'));
        $this->assertSame([1, 3], [Guest::count(), SmsMessage::count()]);

        // Per-device cap.
        Setting::put('sms_caps', ['mac_hour' => 0]);
        $this->requestSms('+41 78 000 00 00')->assertStatus(429);
        $this->travel(2)->hours();
        Setting::put('sms_caps', []);
        $this->requestSms('+41 78 000 00 00')->assertRedirect('/portal/sms');

        // Site and instance caps are logged as warnings (masked number) for the admin.
        Log::spy();
        Setting::put('sms_caps', ['site_hour' => 0]);
        $this->travel(2)->minutes();
        $this->requestSms('+41 77 000 00 00')->assertStatus(429);
        Log::shouldHaveReceived('warning')->with('SMS cost cap reached', ['cap' => 'site_hour', 'site' => Site::sole()->id,
            'mac' => 'AA:BB:CC:00:00:02', 'phone' => '+4177*****00']);
    }

    public function test_webhook_driver_signs_requests_and_failures_mask_secrets(): void
    {
        $hook = Channel::create(['kind' => 'sms', 'driver' => 'sms_webhook', 'name' => 'Gateway', 'enabled' => true,
            'config' => ['url' => 'https://sms.example.com/send', 'secret' => 'hook-secret']]);
        Http::fake(['sms.example.com/*' => Http::response(['id' => 'g1'])]);
        $this->enterPortal();
        $this->requestSms();

        $request = Http::recorded()->first()[0];
        $this->assertSame(['sms.send', '+41790000000'], [$request['event'], $request['to']]);
        $this->assertSame(WebhookChannel::signature('hook-secret', $request->header('X-Timestamp')[0], $request->body()),
            $request->header('X-Signature')[0]);
        $this->assertSame('g1', SmsMessage::sole()->provider_id);

        // A failing provider: the message is marked failed, key and number never appear in the error.
        $hook->delete();
        $this->clickatell();
        Http::fake(['platform.clickatell.com/*' => Http::response(['error' => 'bad key test-api-key for 41790000000'], 401)]);
        $this->enterPortal('AA:BB:CC:00:00:02');
        $this->requestSms();
        $failed = SmsMessage::query()->where('status', 'failed')->sole();
        $this->assertSame('Clickatell send failed: HTTP 401', $failed->error);
        $hook->config = ['url' => 'https://sms.example.com/send?token=t0ken', 'secret' => 'hook-secret'];
        $this->assertSame('cURL error for *** and ***', Redact::secrets(
            'cURL error for https://sms.example.com/send?token=t0ken and send?token=t0ken', $hook->secretValues()));
        $this->assertSame('+4179*****00', Redact::phone('+41790000000'));
    }

    public function test_sms_text_is_domain_bound_one_time_code(): void
    {
        $this->assertSame("123456 is your guest Wi-Fi code (valid for 10 min).\n\n@portal.example.com #123456",
            OtpService::text('123456', 10, 'en', 'Portal.Example.com'));
        $this->assertSame("123456 ist dein Code für das Gäste-WLAN (gültig 10 Min.).\n\n@portal.example.com #123456",
            OtpService::text('123456', 10, 'de', 'portal.example.com'));
        foreach (['fr', 'it'] as $locale) {
            $this->assertStringStartsWith('123456 ', OtpService::text('123456', 10, $locale, 'portal.example.com'));
        }
        foreach ([null, '', 'bad host', 'a.b:8443', 'https://a.b', '[::1]'] as $host) {
            $text = OtpService::text('123456', 10, 'en', $host);
            $this->assertStringNotContainsString('@', $text);
            $this->assertSame(1, substr_count($text, '123456'));
        }
    }

    public function test_admin_sets_own_sms_text_per_locale(): void
    {
        $admin = User::factory()->admin()->create();
        $base = ['sms_duration_hours' => 24, 'sms_countries' => 'CH', 'sms_caps' => config('portal.sms.caps')];
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee('name="sms_text[de]"', false)->assertSee('@:host #:code');

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_text' => [
            'en' => "Code :code\r\nfor :minutes min\r\n\r\n@:host #:code",
            'de' => SmsSettings::defaultText('de'),
            'fr' => '',
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['en' => "Code :code\nfor :minutes min\n\n@:host #:code"], Setting::get('sms_text'));
        $this->assertSame("Code 123456\nfor 10 min\n\n@portal.example.com #123456",
            OtpService::text('123456', 10, 'en', 'portal.example.com'));
        $this->assertSame("Code 123456\nfor 10 min", OtpService::text('123456', 10, 'en', 'bad host'));
        $this->assertStringStartsWith('123456 ist dein Code', OtpService::text('123456', 10, 'de', 'portal.example.com'));

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_text' => ['en' => 'No placeholder']])
            ->assertSessionHasErrors('sms_text.en');
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_text' => ['en' => "Hi\n@:host #:code"]])
            ->assertSessionHasErrors('sms_text.en');
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_text' => ['en' => ':code'.str_repeat('x', 300)]])
            ->assertSessionHasErrors('sms_text.en');
        $this->assertSame(['en' => "Code :code\nfor :minutes min\n\n@:host #:code"], Setting::get('sms_text'));

        // Form without the text fields keeps the stored texts; empty fields reset to the default.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base)->assertSessionHasNoErrors();
        $this->assertSame(['en' => "Code :code\nfor :minutes min\n\n@:host #:code"], Setting::get('sms_text'));
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/sms', $base + ['sms_text' => ['en' => '']])->assertSessionHasNoErrors();
        $this->assertSame([], Setting::get('sms_text'));
        $this->assertStringStartsWith('123456 is your guest Wi-Fi code', OtpService::text('123456', 10, 'en', null));
    }

    public function test_sent_sms_carries_request_host_line(): void
    {
        $this->clickatell();
        Http::fake(['platform.clickatell.com/*' => Http::response(['messages' => [['apiMessageId' => 'm1', 'accepted' => true]]])]);

        $this->enterPortal();
        $this->onZone('guest')->post('http://portal.example.com:8081/portal/sms', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1', 'phone' => self::PHONE,
        ])->assertRedirect();
        $request = Http::recorded()->last()[0];
        $text = $request['content'] ?? $request['text'];
        $code = $this->sentCode();
        $this->assertStringStartsWith($code.' is your guest Wi-Fi code', $text);
        $this->assertStringEndsWith("\n\n@portal.example.com #{$code}", $text);
    }

    private function clickatell(): Channel
    {
        return Channel::create(['kind' => 'sms', 'driver' => 'clickatell', 'name' => 'Clickatell', 'enabled' => true,
            'config' => ['api_key' => 'test-api-key']]);
    }

    /** Code from the last SMS sent through Http::fake. */
    private function sentCode(): string
    {
        $request = Http::recorded()->last()[0];
        preg_match('/\b(\d{6})\b/', $request['content'] ?? $request['text'], $match);

        return $match[1];
    }

    private function enterPortal(string $mac = 'AA:BB:CC:00:00:01')
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => $mac, 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }

    private function requestSms(string $phone = self::PHONE)
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->post('/portal/sms', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1', 'phone' => $phone,
        ]);
    }

    private function verify(string $code)
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->post('/portal/sms/verify', ['otp' => $code]);
    }

    private function resend()
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en')->post('/portal/sms/resend');
    }
}
