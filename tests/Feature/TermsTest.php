<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Federation\Pairing;
use App\Models\AuditLog;
use App\Models\LocalSetting;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Default terms of use (decision 2026-11-03, CONCEPT.md §7.1): lang-file text shown while nothing is
 * published, published as version 1 by the admin button and with the first site of a new portal.
 */
class TermsTest extends TestCase
{
    use RefreshDatabase;

    private const LOCALES = ['de', 'it', 'fr', 'en'];

    /** First line of each default text (paragraph heading), unique per language. */
    private const HEADINGS = ['de' => '1. Geltungsbereich', 'it' => '1. Ambito di applicazione',
        'fr' => '1. Champ d\'application', 'en' => '1. Scope'];

    private function site(): Site
    {
        return Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com']]);
    }

    public function test_every_language_has_a_default_text_with_eight_paragraphs(): void
    {
        foreach (self::LOCALES as $locale) {
            $terms = TermsVersion::defaultFor($locale);
            $this->assertTrue($terms->isDefault());
            $this->assertNull($terms->id);
            $this->assertNull($terms->number);
            $this->assertSame($locale, $terms->locale);
            $this->assertCount(8, $terms->paragraphs(), $locale);
            $this->assertStringStartsWith(self::HEADINGS[$locale], $terms->body);
            $this->assertStringNotContainsString('ß', $terms->body);
        }
        // A locale without a default text falls back to the app default locale.
        $this->assertSame(config('app.locale'), TermsVersion::defaultFor('xx')->locale);
    }

    public function test_guest_sees_the_default_text_and_accepts_it_with_version_null(): void
    {
        $this->site();
        foreach (self::LOCALES as $locale) {
            $this->enterPortal($locale)->assertOk()
                ->assertSee(self::HEADINGS[$locale])
                ->assertSee(trans('portal.guest.terms_default', [], $locale))
                ->assertSee('('.trans('portal.guest.terms_default', [], $locale).')')
                ->assertDontSee('('.trans('portal.guest.terms_version', ['version' => 1], $locale).')');
        }

        $code = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin])->code;
        $this->submit($code, 'not-a-version')->assertSessionHasErrors('terms');
        $this->submit($code, null)->assertOk()->assertViewHas('fields');

        $acceptance = TermsAcceptance::sole();
        $this->assertNull($acceptance->terms_version_id);
        $this->assertNotNull($acceptance->grant_id);
        $this->assertSame(0, TermsVersion::count());
    }

    public function test_a_published_version_beats_the_default_text(): void
    {
        $this->site();
        $fr = TermsVersion::publish('fr', 'Soyez gentils.');

        // Only French is published: the German guest gets it (any published version wins over the default).
        $this->assertTrue(TermsVersion::current('de')->is($fr));
        $this->assertFalse(TermsVersion::current('de')->isDefault());
        $this->enterPortal('de')->assertOk()->assertSee('Soyez gentils.')->assertSee('Version 1')
            ->assertDontSee(self::HEADINGS['de'])->assertDontSee(__('portal.guest.terms_default', [], 'de'));

        $code = app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin])->code;
        $this->submit($code, null)->assertSessionHasErrors('terms');
        $this->submit($code, $fr->id)->assertOk();
        $this->assertSame($fr->id, TermsAcceptance::sole()->terms_version_id);
    }

    public function test_admin_page_prefills_the_default_text_and_loads_it_on_request(): void
    {
        $admin = User::factory()->admin()->create();
        $page = fn (string $query = '') => $this->onZone('admin')->actingAs($admin)->withHeader('Accept-Language', 'en')
            ->get('/admin/terms'.$query)->assertOk();

        $response = $page()->assertSee(__('portal.terms.default_hint', [], 'en'))->assertSee(__('portal.terms.publish_defaults', [], 'en'))
            ->assertDontSee(__('portal.terms.load_default', [], 'en'));
        foreach (self::HEADINGS as $heading) {
            $response->assertSee($heading);
        }

        TermsVersion::publish('de', 'Eigene Regel.');
        $page()->assertSee('Eigene Regel.')->assertDontSee(self::HEADINGS['de'])
            ->assertSee(route('admin.terms', ['default' => 'de']).'#terms-de', false)
            ->assertDontSee(__('portal.terms.default_loaded', [], 'en'));

        $page('?default=de')->assertSee(self::HEADINGS['de'])->assertSee(__('portal.terms.default_loaded', [], 'en'))
            ->assertDontSee('Eigene Regel.');
        // Unknown locales are ignored.
        $page('?default=xx')->assertDontSee(self::HEADINGS['de'])->assertSee('Eigene Regel.');
    }

    public function test_publish_defaults_button_publishes_only_missing_languages_and_audits(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();
        $custom = TermsVersion::publish('fr', 'Soyez gentils.');

        $this->onZone('admin')->actingAs($manager)->post('/admin/terms/defaults')->assertForbidden();
        $this->assertSame(1, TermsVersion::count());

        $this->onZone('admin')->actingAs($admin)->withHeader('Accept-Language', 'en')->post('/admin/terms/defaults')
            ->assertRedirect()->assertSessionHas('status', __('portal.terms.defaults_published', ['locales' => 'de, it, en'], 'en'));

        $this->assertSame(4, TermsVersion::count());
        $this->assertSame('Soyez gentils.', TermsVersion::latestFor('fr')->body);
        $this->assertTrue(TermsVersion::latestFor('fr')->is($custom));
        foreach (['de', 'it', 'en'] as $locale) {
            $terms = TermsVersion::latestFor($locale);
            $this->assertSame(1, $terms->number);
            $this->assertSame(TermsVersion::defaultText($locale), $terms->body);
        }
        $audits = AuditLog::query()->where('action', 'terms.published')->get();
        $this->assertCount(3, $audits);
        $this->assertEqualsCanonicalizing(['de', 'it', 'en'], $audits->pluck('data.locale')->all());
        $this->assertTrue($audits->every(fn (AuditLog $a) => $a->data['default'] === true && $a->data['number'] === 1 && $a->user_id === $admin->id));

        $this->onZone('admin')->actingAs($admin)->withHeader('Accept-Language', 'en')->post('/admin/terms/defaults')
            ->assertSessionHas('status', __('portal.terms.defaults_none', [], 'en'));
        $this->assertSame(4, TermsVersion::count());
        $this->onZone('admin')->actingAs($admin)->withHeader('Accept-Language', 'en')->get('/admin/terms')->assertOk()
            ->assertDontSee(__('portal.terms.publish_defaults', [], 'en'))->assertDontSee(__('portal.terms.default_hint', [], 'en'));
    }

    public function test_the_first_site_of_a_new_portal_publishes_the_default_terms(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->post('/admin/sites', ['name' => 'Site A', 'slug' => 'site-a', 'driver' => 'mikrotik_ros7'])
            ->assertRedirect();

        $this->assertSame(4, TermsVersion::count());
        foreach (self::LOCALES as $locale) {
            $this->assertSame(TermsVersion::defaultText($locale), TermsVersion::latestFor($locale)->body);
            $this->assertSame(1, TermsVersion::latestFor($locale)->number);
        }
        $this->assertSame(4, AuditLog::query()->where('action', 'terms.published')->count());

        // Further sites publish nothing.
        $this->onZone('admin')->actingAs($admin)->post('/admin/sites', ['name' => 'Site B', 'slug' => 'site-b', 'driver' => 'mikrotik_ros7']);
        $this->assertSame(4, TermsVersion::count());
    }

    public function test_sites_of_an_existing_or_joined_portal_publish_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $this->site();
        $this->onZone('admin')->actingAs($admin)->post('/admin/sites', ['name' => 'Site B', 'slug' => 'site-b', 'driver' => 'mikrotik_ros7'])
            ->assertRedirect();
        $this->assertSame(0, TermsVersion::count());

        Site::query()->get()->each->delete();
        LocalSetting::put(Pairing::JOINED_SETTING, now()->toIso8601String());
        $this->onZone('admin')->actingAs($admin)->post('/admin/sites', ['name' => 'Site C', 'slug' => 'site-c', 'driver' => 'mikrotik_ros7'])
            ->assertRedirect();
        $this->assertSame(0, TermsVersion::count());
    }

    public function test_the_bootstrap_admin_keeps_the_instance_able_to_join_a_federation(): void
    {
        $this->artisan('portal:admin', ['--email' => 'admin@example.com', '--name' => 'Admin',
            '--password' => 'a-long-test-password', '--no-interaction' => true])->assertSuccessful();

        $this->assertSame(0, TermsVersion::count());
        $this->assertNull(app(Pairing::class)->isFresh());
    }

    private function enterPortal(string $locale)
    {
        return $this->onZone('guest')->withHeader('Accept-Language', $locale)->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => 'AA:BB:CC:00:00:01', 'link-login-only' => 'https://hotspot.example.com/login',
        ]));
    }

    private function submit(string $code, ?string $termsVersion)
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'de')->post('/portal/code', [
            'first_name' => 'Test', 'last_name' => 'Guest', 'email' => 'guest@example.com', 'terms' => '1', 'code' => $code,
            'terms_version' => $termsVersion,
        ]);
    }
}
