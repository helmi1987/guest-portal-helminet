<?php

namespace Tests\Feature;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\MicrosoftProvider;
use App\Auth\Social\ProviderCheck;
use App\Auth\Social\SocialSettings;
use App\Auth\Social\WalledGarden;
use App\Enums\GrantType;
use App\Federation\Federation;
use App\Http\Controllers\Guest\SocialLoginController;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Site;
use App\Models\TermsVersion;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Microsoft (Entra ID) as fifth social login provider (CONCEPT.md §7.2 D, decision 2026-11-05, v0.31.0): authorization
 * URL, ID token checks, identity mapping, tenant, settings, check button, walled garden and logo.
 */
class MicrosoftSocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:02';

    private const PORTAL = 'https://portal.example.com';

    private const CLIENT = '11111111-2222-3333-4444-555555555555';

    private const SECRET = 'test-microsoft-client-secret';

    private const TID = '00000000-0000-0000-0000-000000000001';

    private TermsVersion $terms;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['app.url' => self::PORTAL]);
        Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => self::PORTAL, 'radius_address' => '10.0.0.10']]);
        $this->terms = TermsVersion::publish('en', 'Be nice.');
    }

    public function test_authorization_url_carries_tenant_pkce_nonce_and_response_mode(): void
    {
        $this->configure('organizations');
        $this->enter();
        $this->start()->assertRedirect(route('guest.social.go', 'microsoft'));
        $flow = session(SocialLoginController::FLOW);
        $this->assertSame(self::PORTAL.'/portal/social/microsoft/callback', $flow['redirect_uri']);

        $location = $this->guest()->get('/portal/social/microsoft/go')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT, $query['client_id']);
        $this->assertSame($flow['redirect_uri'], $query['redirect_uri']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('query', $query['response_mode']);
        $this->assertSame($flow['state'], $query['state']);
        $this->assertSame($flow['nonce'], $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $flow['verifier'], true)), $query['code_challenge']);

        // The tenant is URL-encoded into the path.
        $this->assertSame('https://login.microsoftonline.com/tenant.example.com/oauth2/v2.0/token', MicrosoftProvider::tokenUrl('tenant.example.com'));
        $this->assertSame('https://login.microsoftonline.com/a%2Fb/oauth2/v2.0/authorize', MicrosoftProvider::authUrl('a/b'));
    }

    public function test_callback_logs_in_with_the_id_token_claims(): void
    {
        $this->configure();
        $this->enter();
        $this->start();
        $flow = session(SocialLoginController::FLOW);
        $this->fakeToken(['given_name' => 'Mia', 'family_name' => 'Soft', 'name' => 'Ignored Name', 'email' => 'mia@example.com',
            'preferred_username' => 'mia@example.org', 'nonce' => $flow['nonce']]);

        $this->providerCallback(['code' => 'ms-code', 'state' => $flow['state']])->assertOk()->assertViewHas('fields');

        $guest = Guest::sole();
        $this->assertSame(['Mia', 'Soft', 'microsoft', 'ms-sub-1', 'mia@example.com'],
            [$guest->first_name, $guest->last_name, $guest->social_provider, $guest->social_subject, $guest->email]);
        $this->assertSame('Microsoft', Grant::sole()->label);
        $this->assertSame(GrantType::Social, Grant::sole()->type);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://login.microsoftonline.com/common/oauth2/v2.0/token'
            && $r['code'] === 'ms-code' && $r['client_id'] === self::CLIENT && $r['client_secret'] === self::SECRET
            && $r['code_verifier'] === $flow['verifier'] && $r['redirect_uri'] === $flow['redirect_uri'] && $r['grant_type'] === 'authorization_code');
        // No Graph call: the ID token is enough.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'graph.microsoft.com'));
    }

    public function test_name_falls_back_to_the_display_name_and_email_to_an_address_like_username(): void
    {
        $this->assertSame(['Max', 'van Muster', 'max@example.com'], MicrosoftProvider::profile(
            ['name' => ' Max  van Muster ', 'preferred_username' => 'max@example.com']));
        // preferred_username without @ (a phone number or a plain user name) is no email
        $this->assertSame(['', '', null], MicrosoftProvider::profile(['preferred_username' => '+10000000000']));
        $this->assertSame(['Ana', '', 'ana@example.com'], MicrosoftProvider::profile(
            ['given_name' => 'Ana', 'email' => 'ana@example.com', 'preferred_username' => 'other@example.com']));

        $this->configure();
        $this->enter();
        $this->start();
        $flow = session(SocialLoginController::FLOW);
        $this->fakeToken(['preferred_username' => 'live-user', 'nonce' => $flow['nonce']]);
        $this->providerCallback(['code' => 'c', 'state' => $flow['state']])->assertOk();
        $guest = Guest::sole();
        $this->assertSame(['', '', null], [$guest->first_name, $guest->last_name, $guest->email]);
        $this->assertSame('Microsoft #'.substr(hash('sha256', 'microsoft|ms-sub-1'), 0, 6), $guest->displayName());
    }

    /** @return array<string, array{0: array<string, mixed>, 1?: string}> */
    public static function badTokens(): array
    {
        return [
            'issuer of another tenant' => [['iss' => 'https://login.microsoftonline.com/00000000-0000-0000-0000-000000000009/v2.0']],
            'v1 issuer' => [['iss' => 'https://sts.windows.net/'.self::TID.'/']],
            'tid missing' => [['tid' => null]],
            'audience' => [['aud' => '99999999-2222-3333-4444-555555555555']],
            'nonce' => [['nonce' => 'other']],
            'no subject' => [['sub' => '']],
            'tenant ID configured, token of another tenant' => [[], '00000000-0000-0000-0000-000000000002'],
        ];
    }

    #[DataProvider('badTokens')]
    public function test_id_token_checks_reject_the_login(array $override, string $tenant = 'common'): void
    {
        $this->configure($tenant);
        $this->enter();
        $this->start();
        $flow = session(SocialLoginController::FLOW);
        $this->fakeToken(array_merge(['nonce' => $flow['nonce']], $override));

        $this->providerCallback(['code' => 'c', 'state' => $flow['state']])->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.social_failed.title'));
        $this->assertSame(0, Guest::count());
    }

    public function test_configured_tenant_id_accepts_its_own_tokens(): void
    {
        $this->configure(strtoupper(self::TID));
        $this->enter();
        $this->start();
        $flow = session(SocialLoginController::FLOW);
        $this->fakeToken(['nonce' => $flow['nonce']], strtoupper(self::TID));
        $this->providerCallback(['code' => 'c', 'state' => $flow['state']])->assertOk();
        $this->assertSame(1, Guest::count());
    }

    public function test_tenant_validation(): void
    {
        foreach (['common', 'consumers', 'organizations', 'Common', self::TID, 'tenant.example.net', 'guest.example.com'] as $ok) {
            $this->assertTrue(SocialSettings::validTenant($ok), $ok);
        }
        foreach (['', 'nodomain', 'a/b', 'x.example.com/', '-bad.example.com', 'bad..example.com', str_repeat('a', 250).'.com', 'exa mple.com'] as $bad) {
            $this->assertFalse(SocialSettings::validTenant($bad), $bad);
        }

        $admin = User::factory()->admin()->create();
        $post = fn (string $tenant) => $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/social',
            $this->form(['microsoft' => ['enabled' => '1', 'client_id' => self::CLIENT, 'secret' => self::SECRET, 'tenant' => $tenant]]));
        $post('nodomain')->assertSessionHasErrors(['providers.microsoft.tenant' => __('portal.settings.social.tenant_invalid')]);
        $this->assertNull(SocialSettings::secret('microsoft'));

        $post(' Organizations ')->assertSessionHasNoErrors();
        $this->assertSame('organizations', SocialSettings::provider('microsoft')['tenant']);
        $post('')->assertSessionHasNoErrors();
        $this->assertSame('common', SocialSettings::provider('microsoft')['tenant']);
        $post(self::TID)->assertSessionHasNoErrors();
        $this->assertSame(self::TID, SocialSettings::provider('microsoft')['tenant']);
        $this->assertTrue(SocialSettings::isReady('microsoft'));

        // An invalid stored tenant (e.g. replicated from elsewhere) makes the provider not ready.
        SocialSettings::save(['providers' => ['microsoft' => ['tenant' => 'nope']]]);
        $this->assertFalse(SocialSettings::isReady('microsoft'));
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.settings.social.missing.tenant'));
    }

    public function test_settings_card_shows_fields_status_guide_and_redirect_uris(): void
    {
        Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7', 'settings' => ['portal_url' => 'https://guest-b.example.com']]);
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee('id="social-microsoft"', false)
            ->assertSee('name="providers[microsoft][tenant]"', false)
            ->assertSee('value="common"', false)
            ->assertSee(__('portal.settings.social.tenant_help'))
            ->assertSee(__('portal.social_secret.help_microsoft'))
            ->assertSee(__('portal.settings.social.guide.microsoft.0'))
            ->assertSee(__('portal.settings.social.guide.microsoft.2'))
            ->assertSee('https://portal.example.com/portal/social/microsoft/callback')
            ->assertSee('https://guest-b.example.com/portal/social/microsoft/callback')
            ->assertSee('action="'.route('admin.settings.social.check', 'microsoft').'"', false);

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form([
            'microsoft' => ['enabled' => '1', 'client_id' => self::CLIENT, 'secret' => self::SECRET, 'tenant' => 'consumers'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(['enabled' => true, 'client_id' => self::CLIENT, 'tenant' => 'consumers', 'secret_expires_at' => null],
            SocialSettings::provider('microsoft'));
        $this->assertSame(self::SECRET, SocialSettings::secret('microsoft'));
        $this->assertTrue(SocialSettings::isReady('microsoft'));
        $this->assertContains(SocialSettings::secretSetting('microsoft'), Federation::SECURITY_SETTINGS);
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('data-social-ready', false)
            ->assertDontSee(self::SECRET);
        // A secret longer than 500 characters is rejected (like the other client secrets).
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form([
            'microsoft' => ['enabled' => '1', 'client_id' => self::CLIENT, 'secret' => str_repeat('x', 501)],
        ]))->assertSessionHasErrors('providers.microsoft.secret');
    }

    public function test_check_maps_the_token_endpoint_errors(): void
    {
        $this->configure('consumers');
        $admin = User::factory()->admin()->create();
        Http::fake(['login.microsoftonline.com/*' => Http::sequence()
            ->push(['error' => 'invalid_grant', 'error_description' => 'AADSTS70000'], 400)
            ->push(['error' => 'invalid_client', 'error_description' => 'AADSTS7000215'], 401)
            ->push(['error' => 'unauthorized_client', 'error_description' => 'AADSTS700016'], 400)
            ->push(['error' => 'temporarily_unavailable'], 503)]);
        foreach ([ProviderCheck::OK, ProviderCheck::REJECTED, ProviderCheck::REJECTED, ProviderCheck::UNREACHABLE] as $expected) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/microsoft/check')
                ->assertRedirect(route('admin.settings').'#social-microsoft')->assertSessionHas('social_check.status', $expected);
        }
        Http::assertSent(fn (Request $r) => $r->url() === 'https://login.microsoftonline.com/consumers/oauth2/v2.0/token'
            && $r['client_id'] === self::CLIENT && $r['client_secret'] === self::SECRET && $r['code'] === ProviderCheck::CODE
            && $r['grant_type'] === 'authorization_code' && $r['redirect_uri'] === self::PORTAL.'/portal/social/microsoft/callback');

        // An invalid tenant gives "incomplete" without a request.
        Http::fake();
        SocialSettings::save(['providers' => ['microsoft' => ['tenant' => 'no tenant']]]);
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/microsoft/check')
            ->assertSessionHas('social_check.status', ProviderCheck::INCOMPLETE)
            ->assertSessionHas('social_check.message', __('portal.settings.social.check.missing_tenant'));
        Http::assertNothingSent();
    }

    public function test_walled_garden_contains_the_microsoft_hosts_once_ready(): void
    {
        LoginMethods::save([LoginMethods::SOCIAL => true]);
        $this->assertNotContains('login.microsoftonline.com', WalledGarden::hosts());
        $this->configure();
        foreach (['login.microsoftonline.com', 'login.live.com', '*.msauth.net', '*.msftauth.net', '*.msauthimages.net'] as $host) {
            $this->assertContains($host, WalledGarden::hosts());
            $this->assertContains($host, WalledGarden::allHosts());
        }
        $objects = array_map(WalledGarden::object(...), WalledGarden::allHosts());
        $this->assertSame($objects, array_values(array_unique($objects)));
        $this->assertSame('social-any-msftauth-net', WalledGarden::object('*.msftauth.net'));
    }

    public function test_logo_on_the_chooser_and_the_social_page(): void
    {
        $this->configure();
        // Two methods (code + social): the chooser shows the round logos.
        $this->enter()->assertOk()->assertSee('gp-social-logo-microsoft', false)->assertSee('fill="#f25022"', false)
            ->assertSee(__('portal.guest.social_with', ['provider' => 'Microsoft']));
        $this->guest()->get('/portal?method=social')->assertOk()
            ->assertSee('formaction="'.route('guest.social.start', 'microsoft').'"', false)
            ->assertSee('gp-social-logo-microsoft', false);
    }

    public function test_lang_files_name_microsoft(): void
    {
        foreach (config('portal.locales') as $locale) {
            $this->assertStringContainsString('Microsoft', __('portal.settings.login_methods.methods.social', [], $locale));
            $this->assertSame('Microsoft', __('portal.settings.social.providers.microsoft', [], $locale));
            $this->assertCount(7, (array) __('portal.settings.social.guide.microsoft', [], $locale));
        }
    }

    // --- Helpers ----------------------------------------------------------------------------------

    private function configure(string $tenant = 'common'): void
    {
        LoginMethods::save([LoginMethods::SOCIAL => true]);
        SocialSettings::save(['providers' => ['microsoft' => ['enabled' => true, 'client_id' => self::CLIENT, 'tenant' => $tenant]]],
            ['microsoft' => self::SECRET]);
        $this->assertSame(['microsoft'], SocialSettings::enabledProviders());
    }

    /** Form payload with all providers off and no secrets. */
    private function form(array $providers): array
    {
        $base = [];
        foreach (SocialSettings::PROVIDERS as $provider) {
            $base[$provider] = ['enabled' => '0', 'client_id' => '', 'secret' => ''];
        }

        return ['social_duration_hours' => 24, 'providers' => array_replace_recursive($base, $providers)];
    }

    /** Token endpoint answering an ID token with valid claims, overridden by $claims (null removes a claim). */
    private function fakeToken(array $claims, string $tid = self::TID): void
    {
        $claims = array_filter(array_merge(['iss' => 'https://login.microsoftonline.com/'.$tid.'/v2.0', 'tid' => $tid, 'aud' => self::CLIENT,
            'sub' => 'ms-sub-1', 'oid' => '00000000-0000-0000-0000-0000000000aa', 'ver' => '2.0'], $claims), fn ($v) => $v !== null);
        $jwt = JWT::urlsafeB64Encode('{"alg":"RS256","typ":"JWT"}').'.'.JWT::urlsafeB64Encode((string) json_encode($claims)).'.sig';
        Http::fake(['login.microsoftonline.com/*' => Http::response(['token_type' => 'Bearer', 'access_token' => 'at-ms', 'id_token' => $jwt])]);
    }

    private function guest(): static
    {
        return $this->onZone('guest')->withHeader('Accept-Language', 'en');
    }

    private function enter(): TestResponse
    {
        return $this->guest()->get(self::PORTAL.'/portal?'.http_build_query(['site' => 'site-a', 'mac' => self::MAC,
            'link-login-only' => 'https://hotspot.example.com/login']));
    }

    private function start(): TestResponse
    {
        return $this->guest()->post(self::PORTAL.'/portal/social/microsoft', ['terms' => '1', 'terms_version' => $this->terms->id]);
    }

    private function providerCallback(array $query): TestResponse
    {
        return $this->guest()->get(self::PORTAL.'/portal/social/microsoft/callback?'.http_build_query($query));
    }
}
