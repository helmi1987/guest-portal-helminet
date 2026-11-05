<?php

namespace Tests\Feature;

use App\Federation\Federation;
use App\Guest\GuestPreview;
use App\Guest\GuestTexts;
use App\Http\Controllers\Admin\GuestTextsController;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guest texts editable per language with a live preview (CONCEPT.md §9 "Guest texts", v0.23.0).
 */
class GuestTextsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com']]);
        $this->admin = User::factory()->admin()->create();
    }

    public function test_every_key_exists_in_all_languages_and_has_a_label(): void
    {
        foreach (config('portal.locales') as $locale) {
            $lines = require lang_path($locale.'/portal.php');
            foreach (GuestTexts::keys() as $key) {
                $this->assertIsString(Arr::get($lines, $key), "$locale: $key");
                $this->assertNotSame('', GuestTexts::default($key, $locale));
                // Same placeholders in every language.
                $this->assertSame(GuestTexts::placeholders($key, 'de'), GuestTexts::placeholders($key, $locale), "$locale: $key");
            }
            foreach (GuestPreview::screens() as $screen) {
                $this->assertStringNotContainsString('portal.texts', GuestPreview::screenLabel($screen));
            }
        }
        app()->setLocale('de');
        foreach (GuestTexts::keys() as $key) {
            $this->assertStringNotContainsString('portal.texts', GuestTextsController::label($key), $key);
        }
    }

    public function test_admin_page_lists_fields_with_defaults_and_the_preview(): void
    {
        $this->admin()->get('/admin/texts?locale=de')->assertOk()
            ->assertSee('name="texts[guest-heading]"', false)
            ->assertSee('name="texts[guest-error_pages-expired-title]"', false)
            ->assertSee('placeholder="Willkommen im Gäste-WLAN"', false)
            ->assertSee(__('portal.texts.labels.heading', [], 'de'))
            ->assertSee('Platzhalter: :phone, :minutes')
            ->assertSee('<iframe name="preview"', false)
            ->assertSee('/admin/texts/preview?locale=de&amp;screen=chooser&amp;site='.$this->site->id, false)
            ->assertSee('action="'.route('admin.texts.update', 'de').'"', false);
        // Admin menu entry next to the terms.
        $this->admin()->get('/admin')->assertSee(route('admin.texts'), false);
    }

    public function test_save_stores_overrides_per_language_and_audits(): void
    {
        $this->admin()->post('/admin/texts/de', ['texts' => [
            'guest-heading' => '  Hallo und willkommen  ',
            'guest-social_with' => 'Weiter mit :provider',
            // Equal to the default: not stored.
            'guest-connect' => 'Verbinden',
            // Line breaks become spaces.
            'guest-online_hint' => "Viel Spass\r\nbeim Surfen",
            'unknown-key' => 'ignored',
        ]])->assertRedirect(route('admin.texts', ['locale' => 'de']))->assertSessionHas('status');
        $this->admin()->post('/admin/texts/en', ['texts' => ['guest-heading' => 'Hello']])->assertRedirect();

        $this->assertSame([
            'guest.heading' => ['de' => 'Hallo und willkommen', 'en' => 'Hello'],
            'guest.social_with' => ['de' => 'Weiter mit :provider'],
            'guest.online_hint' => ['de' => 'Viel Spass beim Surfen'],
        ], Setting::get(GuestTexts::SETTING));
        $audit = AuditLog::where('action', 'settings.guest_texts')->orderBy('id')->first();
        $this->assertSame(['locale' => 'de', 'keys' => ['guest.heading', 'guest.social_with', 'guest.online_hint']], $audit->data);

        // The form shows the override with the default below it.
        $this->admin()->get('/admin/texts?locale=de')->assertOk()
            ->assertSee('value="Hallo und willkommen"', false)
            ->assertSee('Standard: Willkommen im Gäste-WLAN');

        // Saving the same texts again changes nothing and writes no audit entry.
        $this->admin()->post('/admin/texts/en', ['texts' => ['guest-heading' => 'Hello']]);
        $this->assertSame(2, AuditLog::where('action', 'settings.guest_texts')->count());
    }

    public function test_placeholders_of_the_default_must_be_kept_and_length_is_limited(): void
    {
        $this->admin()->post('/admin/texts/de', ['texts' => ['guest-social_with' => 'Mit dem Konto anmelden']])
            ->assertSessionHasErrors(['texts.guest-social_with' => '«'.__('portal.texts.labels.social_with').'»: Der Platzhalter :provider fehlt.']);
        $this->admin()->post('/admin/texts/fr', ['texts' => ['guest-sms_sent' => 'Code envoyé à :phone.']])
            ->assertSessionHasErrors('texts.guest-sms_sent');
        $this->assertStringContainsString(':minutes', session('errors')->first('texts.guest-sms_sent'));
        $this->admin()->post('/admin/texts/de', ['texts' => ['guest-heading' => str_repeat('a', 501)]])
            ->assertSessionHasErrors('texts.guest-heading');
        // Other case of the placeholder is fine (Laravel replaces :Provider too).
        $this->admin()->post('/admin/texts/de', ['texts' => ['guest-social_with' => ':Provider-Konto verwenden']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['guest.social_with' => ['de' => ':Provider-Konto verwenden']], Setting::get(GuestTexts::SETTING));
        $this->admin()->post('/admin/texts/xx', ['texts' => []])->assertNotFound();
    }

    public function test_empty_field_resets_to_the_default(): void
    {
        Setting::put(GuestTexts::SETTING, ['guest.heading' => ['de' => 'Alt', 'it' => 'Vecchio'], 'guest.code' => ['de' => 'Code']]);
        $this->admin()->post('/admin/texts/de', ['texts' => ['guest-heading' => '', 'guest-code' => 'Code']])->assertRedirect();

        $this->assertSame(['guest.heading' => ['it' => 'Vecchio'], 'guest.code' => ['de' => 'Code']], Setting::get(GuestTexts::SETTING));
        $this->assertSame(['locale' => 'de', 'keys' => ['guest.heading']], AuditLog::where('action', 'settings.guest_texts')->sole()->data);
    }

    public function test_guest_pages_show_the_override_of_their_language_only(): void
    {
        $this->admin()->post('/admin/texts/de', ['texts' => [
            'guest-heading' => 'Servus im Netz', 'guest-intros-code' => 'Gib den Code vom Empfang ein.',
            'guest-code' => 'Dein Code', 'guest-title' => 'Hotel-WLAN', 'guest-error_pages-no_hotspot-title' => 'Bitte zuerst verbinden',
        ]]);

        $this->onZone('guest')->withHeader('Accept-Language', 'de')->get('/')->assertOk()
            ->assertSee('Servus im Netz')->assertSee('<title>Hotel-WLAN</title>', false);
        $this->onZone('guest')->withHeader('Accept-Language', 'de')->get('/portal')->assertOk()->assertSee('Bitte zuerst verbinden');
        // Code is the only available method here, so its page is shown directly.
        $this->onZone('guest')->withHeader('Accept-Language', 'de')->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => 'AA:BB:CC:00:00:01', 'link-login-only' => 'https://hotspot.example.com/login',
        ]))->assertOk()->assertSee('Gib den Code vom Empfang ein.')->assertSee('Dein Code')
            // Untouched keys keep the lang file text.
            ->assertSee(__('portal.guest.first_name', [], 'de'));

        $this->onZone('guest')->withHeader('Accept-Language', 'en')->get('/')->assertOk()
            ->assertSee(__('portal.guest.heading', [], 'en'))->assertDontSee('Servus im Netz');

        // Nothing stays in the translator after the request.
        $this->assertSame('Willkommen im Gäste-WLAN', __('portal.guest.heading', [], 'de'));
    }

    public function test_admin_zone_keeps_the_defaults(): void
    {
        $this->admin()->post('/admin/texts/de', ['texts' => ['guest-connect' => 'Los gehts']]);
        $this->onZone('guest')->withHeader('Accept-Language', 'de')->get('/')->assertOk();

        // The branding preview in the settings uses guest.connect.
        $this->admin()->get('/admin/settings')->assertOk()
            ->assertSee('Verbinden')->assertDontSee('Los gehts');
    }

    public function test_preview_renders_every_screen_with_demo_data_and_writes_nothing(): void
    {
        Setting::put(GuestTexts::SETTING, ['guest.online' => ['de' => 'Viel Spass im Netz']]);
        $before = [AuditLog::count(), DB::table('federation_events')->count(), DB::table('sessions')->count()];

        foreach (GuestPreview::screens() as $screen) {
            $response = $this->admin()->get('/admin/texts/preview?'.http_build_query(['locale' => 'de', 'screen' => $screen]))
                ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            if (str_starts_with($screen, 'error-')) {
                $response->assertSee(__('portal.guest.error_pages.'.substr($screen, 6).'.title', [], 'de'));
            }
        }
        $preview = fn (string $screen) => $this->admin()->get('/admin/texts/preview?locale=de&screen='.$screen);
        $preview('status')->assertSee('Viel Spass im Netz')->assertSee('2 Stunden');
        $preview('sms_otp')->assertSee('+4179*****00');
        $preview('sms_resent')->assertSee(__('portal.guest.sms_resent', [], 'de'));
        $preview('notices')->assertSee(__('portal.guest.limited_mode', [], 'de'))->assertSee(__('portal.guest.access_ended.expired', [], 'de'));
        $preview('approval_waiting')->assertDontSee('http-equiv="refresh"', false);
        $preview('error-expired')->assertSee(__('portal.guest.try_again', [], 'de'));
        // Links lead to the preview screens, forms post to the preview; never to the guest routes.
        $preview('chooser')->assertSee('/admin/texts/preview?locale=de&amp;screen=code', false)
            ->assertSee('/admin/texts/preview?locale=de&amp;screen=social', false)
            ->assertDontSee('/portal?method=', false)
            // Language switcher: same screen, other language.
            ->assertSee('/admin/texts/preview?locale=fr&amp;screen=chooser', false);
        $preview('code')->assertSee('action="/admin/texts/preview"', false)->assertSee('name="screen" value="status"', false)
            ->assertSee('novalidate', false)->assertDontSee(route('guest.redeem'), false);
        $preview('sms_otp')->assertSee('name="screen" value="sms_resent"', false)->assertSee('name="screen" value="status"', false);
        $preview('social')->assertSee('name="screen" value="social_name"', false);
        // Status with demo usage; its logout form leads to the logged-out screen (decision 2026-11-05).
        $preview('status')->assertSee(__('portal.guest.usage_title', [], 'de'))->assertSee('175.8 MiB')
            ->assertSee('name="screen" value="logged_out"', false)->assertSee('action="/admin/texts/preview"', false)
            ->assertDontSee('/logout', false);
        $preview('logged_out')->assertSee(__('portal.guest.logged_out', [], 'de'))
            ->assertSee('/admin/texts/preview?locale=de&amp;screen=chooser', false);
        $this->assertSame(['logged_out'], GuestPreview::screensFor('guest.logged_out_timeout'));
        $this->assertSame(['status'], GuestPreview::screensFor('guest.usage_hint'));
        $this->assertSame(['notices'], GuestPreview::screensFor('guest.access_ended.logged_out'));
        // Unknown screen and language fall back.
        $this->admin()->get('/admin/texts/preview?locale=xx&screen=nope')->assertOk();

        $this->assertSame(0, Grant::count() + Guest::count() + ApprovalRequest::count() + OtpChallenge::count());
        $this->assertSame($before, [AuditLog::count(), DB::table('federation_events')->count(), DB::table('sessions')->count()]);
    }

    public function test_preview_post_applies_drafts_without_storing_them(): void
    {
        Setting::put(GuestTexts::SETTING, ['guest.heading' => ['de' => 'Gespeichert'], 'guest.code' => ['de' => 'Gespeicherter Code']]);

        $this->admin()->post('/admin/texts/preview', ['locale' => 'de', 'screen' => 'code', 'site' => $this->site->id, 'texts' => [
            'guest-heading' => 'Entwurf', 'guest-code' => '', 'guest-connect' => 'Jetzt verbinden', 'unknown' => 'x',
        ]])->assertOk()->assertSee('Entwurf')->assertSee('Jetzt verbinden')
            // An emptied field shows the default, not the stored override.
            ->assertSee(__('portal.guest.code', [], 'de'))->assertDontSee('Gespeicherter Code');

        // A form inside the preview (no texts): the stored texts.
        $this->admin()->post('/admin/texts/preview', ['locale' => 'de', 'screen' => 'status'])->assertOk();
        $this->admin()->post('/admin/texts/preview', ['locale' => 'de', 'screen' => 'code'])->assertOk()->assertSee('Gespeicherter Code');

        $this->assertSame(['guest.heading' => ['de' => 'Gespeichert'], 'guest.code' => ['de' => 'Gespeicherter Code']], Setting::get(GuestTexts::SETTING));
        $this->assertSame(0, AuditLog::where('action', 'settings.guest_texts')->count());
        $this->assertSame('Willkommen im Gäste-WLAN', __('portal.guest.heading', [], 'de'));
    }

    public function test_texts_and_preview_are_admin_only_and_admin_zone_only(): void
    {
        $this->onZone('admin')->get('/admin/texts/preview')->assertRedirect(route('login'));
        $manager = User::factory()->create();
        foreach (['/admin/texts', '/admin/texts/preview'] as $url) {
            $this->onZone('admin')->actingAs($manager)->get($url)->assertForbidden();
            $this->onZone('guest')->actingAs($this->admin)->get($url)->assertNotFound();
        }
        $this->onZone('admin')->actingAs($manager)->post('/admin/texts/preview', ['texts' => []])->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->post('/admin/texts/de', ['texts' => ['guest-heading' => 'x']])->assertForbidden();
        $this->assertNull(Setting::get(GuestTexts::SETTING));
    }

    public function test_setting_is_replicated_but_not_a_security_setting(): void
    {
        $this->assertNotContains(GuestTexts::SETTING, Federation::SECURITY_SETTINGS);
        $this->admin()->post('/admin/texts/de', ['texts' => ['guest-heading' => 'Hallo']]);
        $this->assertSame(1, DB::table('federation_events')->where('table', 'settings')
            ->where('record_id', Setting::idFor(GuestTexts::SETTING))->count());
    }

    private function admin(): static
    {
        return $this->onZone('admin')->withHeader('Accept-Language', 'de')->actingAs($this->admin);
    }
}
