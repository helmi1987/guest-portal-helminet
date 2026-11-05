<?php

namespace Tests\Feature;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\SecretExpiry;
use App\Auth\Social\SecretReminder;
use App\Auth\Social\SocialSettings;
use App\Federation\Topology;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Expiry date of the social login secrets with staged reminders and banners (CONCEPT.md §9, decision 2026-11-05,
 * v0.31.0). Only the social providers; Apple has no expiring secret.
 */
class SocialSecretExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => 'https://portal.example.com', 'portal.display_timezone' => 'UTC']);
        Setting::put('admin_url', 'https://portal.example.net:8443');
        $this->travelTo(Carbon::parse('2027-01-01 08:10:00', 'UTC'));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function form(array $providers): array
    {
        $base = [];
        foreach (SocialSettings::PROVIDERS as $provider) {
            $base[$provider] = ['enabled' => '0', 'client_id' => '', 'secret' => ''];
        }

        return ['social_duration_hours' => 24, 'providers' => array_replace_recursive($base, $providers)];
    }

    private function configure(string $provider, ?string $date, bool $enabled = true): void
    {
        SocialSettings::save(['providers' => [$provider => ['enabled' => $enabled, 'client_id' => $provider.'-client', 'secret_expires_at' => $date]]],
            [$provider => $provider.'-secret']);
    }

    private function webhook(): void
    {
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.net/in', 'secret' => 'hook-secret']]);
        Http::fake(['hooks.example.net/*' => Http::response('', 204)]);
    }

    /** @return list<array<string, mixed>> webhook bodies sent so far */
    private function sent(): array
    {
        return array_map(fn ($pair) => $pair[0]->data(), Http::recorded()->all());
    }

    public function test_date_is_saved_validated_and_cleared_with_the_secret(): void
    {
        $admin = $this->admin();
        $post = fn (array $providers) => $this->onZone('admin')->actingAs($admin)->from('/admin/settings')
            ->post('/admin/settings/social', $this->form($providers));

        $post(['github' => ['enabled' => '1', 'client_id' => 'gh', 'secret' => 'gh-secret', 'secret_expires_at' => '2027-03-12']])
            ->assertSessionHasNoErrors();
        $this->assertSame('2027-03-12', SocialSettings::secretExpiresAt('github'));
        $this->assertSame('2027-03-12', AuditLog::where('action', 'settings.social')->latest('id')->first()->data['providers']['github']['valid_until']);
        // The secret field left empty keeps secret and date; an empty date removes only the date.
        $post(['github' => ['enabled' => '1', 'client_id' => 'gh', 'secret_expires_at' => '2027-04-01']])->assertSessionHasNoErrors();
        $this->assertSame(['gh-secret', '2027-04-01'], [SocialSettings::secret('github'), SocialSettings::secretExpiresAt('github')]);
        $post(['github' => ['enabled' => '1', 'client_id' => 'gh', 'secret_expires_at' => '']])->assertSessionHasNoErrors();
        $this->assertNull(SocialSettings::secretExpiresAt('github'));

        foreach (['1999-12-31', '2057-01-02', '12.03.2027', '2027-02-30', 'soon'] as $bad) {
            $post(['github' => ['enabled' => '1', 'client_id' => 'gh', 'secret_expires_at' => $bad]])
                ->assertSessionHasErrors(['providers.github.secret_expires_at' => __('portal.social_secret.invalid')]);
        }
        $post(['github' => ['enabled' => '1', 'client_id' => 'gh', 'secret_expires_at' => '2057-01-01']])->assertSessionHasNoErrors();

        // Removing the secret also removes its date.
        $post(['github' => ['enabled' => '1', 'client_id' => 'gh', 'secret_expires_at' => '2027-03-12', 'secret_clear' => '1']])
            ->assertSessionHasNoErrors();
        $this->assertNull(SocialSettings::secret('github'));
        $this->assertNull(SocialSettings::secretExpiresAt('github'));

        // Apple has no date (its .p8 key does not expire): neither stored nor shown.
        $post(['apple' => ['secret_expires_at' => '2027-03-12']])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('secret_expires_at', SocialSettings::provider('apple'));
        $html = $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
        $this->assertSame(count(SocialSettings::EXPIRING), substr_count($html, 'data-social-secret-date'));
        $this->assertStringNotContainsString('name="providers[apple][secret_expires_at]"', $html);
        $this->assertStringContainsString(e(__('portal.social_secret.help')), $html);
    }

    public function test_status_strings_next_to_the_provider(): void
    {
        $admin = $this->admin();
        $page = fn () => $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk();

        $this->configure('google', '2027-03-12');
        $page()->assertSee(__('portal.social_secret.status.valid', ['date' => '12.03.2027']))->assertSee('data-social-secret-status="ok"', false)
            ->assertDontSee('data-social-secret-banner', false);

        $this->configure('google', '2027-01-21');
        $page()->assertSee(trans_choice('portal.social_secret.status.soon', 20, ['days' => 20, 'date' => '21.01.2027']))
            ->assertSee('data-social-secret-status="soon"', false);
        $this->configure('google', '2027-01-02');
        $page()->assertSee(trans_choice('portal.social_secret.status.soon', 1, ['days' => 1, 'date' => '02.01.2027']));
        $this->configure('google', '2027-01-01');
        $page()->assertSee(__('portal.social_secret.status.today', ['date' => '01.01.2027']));

        $this->configure('google', '2026-12-31');
        $page()->assertSee(__('portal.social_secret.status.expired', ['date' => '31.12.2026']))->assertSee('data-social-secret-status="expired"', false)
            // The provider stays enabled and ready (the check button tells the truth).
            ->assertSee('data-social-ready', false);
        $this->assertTrue(SocialSettings::isReady('google'));
    }

    public function test_banner_for_admins_on_dashboard_and_settings_not_for_managers(): void
    {
        $this->configure('microsoft', '2027-01-15');
        $this->configure('github', '2026-12-01');
        $this->configure('facebook', '2027-06-01');
        // Switched off: no banner line.
        $this->configure('google', '2027-01-05', false);
        $admin = $this->admin();

        foreach (['/admin', '/admin/settings'] as $url) {
            $html = $this->onZone('admin')->actingAs($admin)->get($url)->assertOk()
                ->assertSee('data-social-secret-banner', false)
                ->assertSee(__('portal.social_secret.banner.title'))
                ->assertSee('data-social-secret-expiring="microsoft"', false)
                ->assertSee('data-social-secret-expiring="github"', false)
                ->assertDontSee('data-social-secret-expiring="facebook"', false)
                ->assertDontSee('data-social-secret-expiring="google"', false)
                ->assertSee(route('admin.settings').'#social-microsoft', false)
                ->getContent();
            // Display order of the providers.
            $this->assertLessThan(strpos($html, 'data-social-secret-expiring="microsoft"'), strpos($html, 'data-social-secret-expiring="github"'));
        }

        $this->onZone('admin')->actingAs(User::factory()->create())->get('/admin')->assertOk()
            ->assertDontSee('data-social-secret-banner', false);
    }

    public function test_each_stage_is_sent_once_and_a_new_date_starts_over(): void
    {
        $this->webhook();
        $this->configure('microsoft', '2027-04-30'); // 119 days ahead

        $sentAt = [];
        for ($day = 0; $day <= 125; $day++) {
            $before = count($this->sent());
            $this->artisan('portal:secret-reminders')->assertSuccessful();
            // A second run on the same day sends nothing.
            $this->artisan('portal:secret-reminders')->assertSuccessful();
            foreach (array_slice($this->sent(), $before) as $body) {
                $sentAt[] = [$body['days'], $body['event']];
            }
            $this->travel(1)->days();
        }
        $this->assertSame([[90, 'social.secret_expiring'], [60, 'social.secret_expiring'], [30, 'social.secret_expiring'],
            [14, 'social.secret_expiring'], [7, 'social.secret_expiring'], [3, 'social.secret_expiring'], [2, 'social.secret_expiring'],
            [1, 'social.secret_expiring'], [0, 'social.secret_expiring'], [-1, 'social.secret_expired']], $sentAt);
        $this->assertSame(['microsoft' => ['date' => '2027-04-30', 'stage' => 'expired']], LocalSetting::get(SecretReminder::REMINDERS_KEY));

        // A new secret with a new date: the stages start over; only the latest reached stage is sent.
        $this->configure('microsoft', now()->addDays(10)->format('Y-m-d'));
        $before = count($this->sent());
        $this->artisan('portal:secret-reminders')->assertSuccessful()->expectsOutputToContain('Microsoft: secret valid until');
        $this->assertSame([[10, 'social.secret_expiring']], array_map(fn ($b) => [$b['days'], $b['event']], array_slice($this->sent(), $before)));
        $this->assertSame(14, LocalSetting::get(SecretReminder::REMINDERS_KEY)['microsoft']['stage']);
    }

    public function test_webhook_payload_and_message_texts(): void
    {
        $this->webhook();
        Setting::put('notification_locale', 'de');
        $this->configure('github', '2027-01-08');
        $this->artisan('portal:secret-reminders')->assertSuccessful();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://hooks.example.net/in' && $r['event'] === 'social.secret_expiring'
            && $r['provider'] === 'github' && $r['provider_name'] === 'GitHub' && $r['expires_at'] === '2027-01-08' && $r['days'] === 7
            && $r['url'] === 'https://portal.example.net:8443/admin/settings#social-github'
            && $r['title'] === __('portal.social_secret.notify.title', [], 'de')
            && str_contains($r['message'], '08.01.2027') && str_contains($r['message'], 'Noch 7 Tage') && str_contains($r['message'], 'https://portal.example.net:8443/admin/settings#social-github')
            && $r->hasHeader('X-Signature'));

        $this->travelTo(Carbon::parse('2027-01-09 08:10:00', 'UTC'));
        $this->artisan('portal:secret-reminders')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r['event'] === 'social.secret_expired' && $r['days'] === -1
            && $r['title'] === __('portal.social_secret.notify.title_expired', [], 'de'));
    }

    public function test_dry_run_sends_and_stores_nothing(): void
    {
        $this->webhook();
        $this->configure('facebook', '2027-01-31');
        $this->artisan('portal:secret-reminders', ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain('Facebook: secret valid until 31.01.2027 (30 days left), would send stage 30');
        Http::assertNothingSent();
        $this->assertNull(LocalSetting::get(SecretReminder::REMINDERS_KEY));

        $this->artisan('portal:secret-reminders')->assertSuccessful()->expectsOutputToContain('sent stage 30');
        $this->artisan('portal:secret-reminders')->assertSuccessful()->expectsOutputToContain('stage 30 already sent');
        $this->assertCount(1, $this->sent());
    }

    public function test_switched_off_apple_and_dates_far_ahead_get_no_reminder(): void
    {
        $this->webhook();
        $this->configure('google', '2027-01-05', false);
        $this->configure('github', '2027-06-01');
        SocialSettings::save(['providers' => ['apple' => ['enabled' => true, 'secret_expires_at' => '2027-01-05']]]);
        $this->artisan('portal:secret-reminders')->assertSuccessful()->expectsOutputToContain('GitHub: secret valid until 01.06.2027 (151 days left), no reminder yet');
        Http::assertNothingSent();
        $this->assertSame([], SecretExpiry::attention());
    }

    public function test_only_the_hub_sends(): void
    {
        $this->webhook();
        $this->configure('google', '2027-01-02');
        $hub = Instance::create(['name' => 'Hub', 'federation_url' => 'https://192.0.2.9:9443']);
        Setting::put(Topology::HUB_SETTING, $hub->id);
        $this->assertFalse(app(Topology::class)->isHub());

        $this->artisan('portal:secret-reminders')->assertSuccessful()->expectsOutputToContain('Not the federation hub');
        Http::assertNothingSent();
        $this->assertNull(LocalSetting::get(SecretReminder::REMINDERS_KEY));
        // The reminder state is local: not a replicated setting.
        $this->assertNull(Setting::get(SecretReminder::REMINDERS_KEY));
    }

    public function test_stage_rule(): void
    {
        $this->assertNull(SecretReminder::stage(91));
        $this->assertSame(90, SecretReminder::stage(90));
        $this->assertSame(90, SecretReminder::stage(61));
        $this->assertSame(60, SecretReminder::stage(60));
        $this->assertSame(14, SecretReminder::stage(10));
        $this->assertSame(3, SecretReminder::stage(3));
        $this->assertSame(0, SecretReminder::stage(0));
        $this->assertSame(SecretReminder::EXPIRED, SecretReminder::stage(-30));
    }

    public function test_lang_strings_exist_in_every_locale(): void
    {
        LoginMethods::save([LoginMethods::SOCIAL => true]);
        foreach (config('portal.locales') as $locale) {
            foreach (['field', 'help', 'help_microsoft', 'invalid', 'banner.title', 'notify.title', 'notify.title_expired', 'notify.link'] as $key) {
                $this->assertNotSame('portal.social_secret.'.$key, __('portal.social_secret.'.$key, [], $locale), $locale.' '.$key);
            }
            $this->assertStringContainsString('3', trans_choice('portal.social_secret.notify.days_left', 3, ['days' => 3], $locale));
        }
    }
}
