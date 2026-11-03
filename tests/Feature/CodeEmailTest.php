<?php

namespace Tests\Feature;

use App\Enums\ExpiryMode;
use App\Models\Guest;
use App\Models\Site;
use App\Models\TermsVersion;
use App\Models\User;
use App\Services\GrantManager;
use App\Services\SiteCodeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Email address on the code login (CONCEPT.md §7.1, decision 2026-11-03, v0.24.1): required on the code
 * page (normal codes and the site code), stored lower-cased on the guest; SMS and approval stay name only.
 */
class CodeEmailTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => 'https://portal.example.com']]);
    }

    public function test_code_page_shows_a_required_email_field_in_de_and_fr(): void
    {
        foreach (['de' => 'E-Mail-Adresse', 'fr' => 'Adresse e-mail'] as $locale => $label) {
            $this->flushSession();
            $this->enter($locale, ['method' => 'code'])->assertOk()
                ->assertSee($label)->assertSee(__('portal.guest.intros.code', [], $locale))
                ->assertSee('type="email" name="email"', false)->assertSee('autocomplete="email"', false)
                ->assertSee('inputmode="email"', false);
        }
    }

    public function test_sms_and_approval_pages_keep_name_only(): void
    {
        foreach (['sms', 'approval'] as $method) {
            $this->assertStringNotContainsString('name="email"', view('guest.methods.'.$method, [
                'site' => $this->site, 'terms' => null, 'limitedMode' => false, 'errors' => new ViewErrorBag,
            ])->render());
        }
    }

    public function test_missing_email_is_a_translated_validation_error(): void
    {
        $code = $this->code();
        $this->redeem($code, null, 'de')->assertSessionHasErrors(['email' => 'E-Mail fehlt.']);
        $this->redeem($code, null, 'en')->assertSessionHasErrors(['email' => 'The email field is required.']);
        $this->assertSame(0, Guest::count());
    }

    public function test_invalid_email_is_rejected(): void
    {
        $code = $this->code();
        foreach (['no-at-sign', 'guest@', '@example.com', 'two@@example.com', str_repeat('a', 180).'@example.com'] as $email) {
            $this->redeem($code, $email, 'de')->assertSessionHasErrors('email');
        }
        $this->assertSame(0, Guest::count());
    }

    public function test_valid_email_is_stored_lower_cased_and_shown_in_the_guest_list(): void
    {
        $code = $this->code();
        $this->redeem($code, '  Guest.Name@Example.COM ', 'de')->assertOk()->assertSessionHasNoErrors();
        $guest = Guest::sole();
        $this->assertSame('guest.name@example.com', $guest->email);

        $admin = User::factory()->admin()->create();
        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/guests')->assertOk()->assertSee('guest.name@example.com');
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertDontSee('guest.name@example.com')->assertSee('g***@example.com');
    }

    public function test_site_code_login_requires_and_stores_the_email(): void
    {
        $siteCode = app(SiteCodeManager::class)->generate($this->site, 3600, null, null, User::factory()->create());
        $this->redeem($siteCode->code, null, 'de')->assertSessionHasErrors('email');
        $this->redeem($siteCode->code, 'site.guest@example.com', 'de')->assertOk()->assertSessionHasNoErrors();
        $this->assertSame('site.guest@example.com', Guest::sole()->email);
    }

    public function test_email_label_is_an_editable_guest_text_shown_in_the_code_preview(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->withHeader('Accept-Language', 'de')->get('/admin/texts')->assertOk()
            ->assertSee('name="texts[guest-email]"', false)->assertSee('Feld E-Mail-Adresse');
        $this->onZone('admin')->actingAs($admin)->post('/admin/texts/preview', ['locale' => 'fr', 'screen' => 'code',
            'texts' => ['guest-email' => 'Votre e-mail']])->assertOk()
            ->assertSee('Votre e-mail')->assertSee('type="email" name="email"', false);
    }

    private function code(): string
    {
        return app(GrantManager::class)->createCode(['duration_s' => 3600, 'expiry_mode' => ExpiryMode::FirstLogin])->code;
    }

    private function enter(string $locale, array $query = []): TestResponse
    {
        return $this->onZone('guest')->withHeader('Accept-Language', $locale)->get('/portal?'.http_build_query([
            'site' => 'site-a', 'mac' => self::MAC, 'ip' => '10.5.50.10',
            'link-login-only' => 'https://hotspot.example.com/login', 'link-orig' => 'http://example.com/', ...$query,
        ]));
    }

    private function redeem(string $code, ?string $email, string $locale): TestResponse
    {
        $this->enter($locale);

        return $this->onZone('guest')->withHeader('Accept-Language', $locale)->post('/portal/code', array_filter([
            'first_name' => 'Test', 'last_name' => 'Guest', 'email' => $email, 'terms' => '1', 'code' => $code,
            'terms_version' => TermsVersion::current($locale)?->id,
        ], fn ($value) => $value !== null));
    }
}
