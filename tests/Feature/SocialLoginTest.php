<?php

namespace Tests\Feature;

use App\Approval\ApprovalMessage;
use App\Approval\Channels\WebhookChannel;
use App\Auth\Social\LoginMethods;
use App\Auth\Social\SocialSettings;
use App\Auth\Social\WalledGarden;
use App\Enums\GrantType;
use App\Federation\Topology;
use App\Http\Controllers\Guest\SocialLoginController;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\Instance;
use App\Models\LoginAttempt;
use App\Models\NasClient;
use App\Models\Setting;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Models\User;
use App\Nas\MikrotikRos7Driver;
use App\Services\GrantManager;
use App\Support\Redact;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Method chooser and guest social login (CONCEPT.md §7.1, §7.2 D, decision 2026-11-03).
 */
class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private const MAC = 'AA:BB:CC:00:00:01';

    private const PORTAL = 'https://portal.example.com';

    private Site $site;

    private TermsVersion $terms;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'mikrotik_ros7',
            'settings' => ['hotspot_hosts' => ['hotspot.example.com'], 'portal_url' => self::PORTAL, 'radius_address' => '10.0.0.10']]);
        $this->terms = TermsVersion::publish('en', 'Be nice.');
    }

    // --- Method chooser -------------------------------------------------------------------------

    public function test_a_single_method_is_shown_directly(): void
    {
        $this->enter()->assertOk()->assertSee('action="'.route('guest.redeem').'"', false)
            ->assertSee('gp-step-portal-code', false)->assertDontSee('gp-methods', false)
            ->assertDontSee(__('portal.guest.other_methods'));
    }

    public function test_chooser_with_several_methods_and_selection(): void
    {
        $this->configure(['google', 'github']);

        $this->enter()->assertOk()->assertSee('gp-step-portal-chooser', false)->assertSee('id="gp-methods"', false)
            ->assertSee(__('portal.guest.choose.code'))->assertSee(__('portal.guest.choose.social'))
            ->assertSee(__('portal.guest.social_with', ['provider' => 'Google']))->assertSee(__('portal.guest.social_with', ['provider' => 'GitHub']))
            ->assertDontSee(__('portal.guest.choose.sms'))->assertDontSee('name="code"', false)->assertDontSee('portal.guest.');

        $this->guest()->get('/portal?method=social')->assertOk()->assertSee('gp-step-portal-social', false)
            ->assertSee('formaction="'.route('guest.social.start', 'google').'"', false)
            ->assertSee('formaction="'.route('guest.social.start', 'github').'"', false)
            ->assertDontSee(route('guest.social.start', 'apple'))
            ->assertSee('name="terms"', false)->assertSee(__('portal.guest.social_hint'))
            ->assertDontSee('mac=AA%3ABB%3ACC%3A00%3A00%3A01', false)
            ->assertSee(__('portal.guest.other_methods'))->assertDontSee('portal.guest.');
        $this->guest()->get('/portal?method=code')->assertOk()->assertSee('name="code"', false)->assertSee(__('portal.guest.other_methods'));
        // Unknown or unavailable selection: the chooser
        $this->guest()->get('/portal?method=sms')->assertOk()->assertSee('id="gp-methods"', false);
        // A prefilled code (site code notice) opens the code page directly
        $this->enter(['code' => '123456'])->assertOk()->assertSee('value="123456"', false)->assertDontSee('id="gp-methods"', false);
    }

    public function test_chooser_follows_the_configured_order(): void
    {
        $this->configure(['google', 'github']);
        Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);

        // Default order: code first (primary button), social box after approval.
        $this->enter()->assertOk()->assertSeeInOrder(['gp-method-code', 'gp-method-approval', 'gp-method-social'], false)
            ->assertSeeInOrder([__('portal.guest.social_with', ['provider' => 'Google']), __('portal.guest.social_with', ['provider' => 'GitHub'])])
            ->assertSee('gp-social-logos', false)->assertSeeInOrder(['gp-social-logo-google', 'gp-social-logo-github'], false)
            // The whole social box is one link to the social page (v0.31.1), not a link per logo.
            ->assertSee('gp-method gp-method-social', false)->assertDontSee('gp-social-button', false)
            ->assertSee('gp-method-code btn"', false);

        LoginMethods::save(LoginMethods::all(), ['social', 'approval', 'sms', 'account', 'code']);
        SocialSettings::save(['order' => ['github', 'facebook', 'apple', 'google']]);
        $this->enter()->assertOk()->assertSeeInOrder(['gp-method-social', 'gp-method-approval', 'gp-method-code'], false)
            ->assertSeeInOrder([__('portal.guest.social_with', ['provider' => 'GitHub']), __('portal.guest.social_with', ['provider' => 'Google'])])
            ->assertSee('gp-method-code gp-button-secondary', false);

        LoginMethods::save(LoginMethods::all(), ['approval', 'code', 'social', 'sms', 'account']);
        $this->enter()->assertOk()->assertSeeInOrder(['gp-method-approval', 'gp-method-code', 'gp-method-social'], false)
            ->assertSee('gp-method-approval btn"', false)->assertSee('gp-method-code gp-button-secondary', false);
        $this->guest()->get('/portal?method=social')->assertOk()
            ->assertSeeInOrder([route('guest.social.start', 'github'), route('guest.social.start', 'google')], false);

        // The admin preview of the chooser uses the same order.
        $this->onZone('admin')->actingAs(User::factory()->admin()->create())->get('/admin/texts/preview?locale=en&screen=chooser')
            ->assertOk()->assertSeeInOrder(['gp-method-approval', 'gp-method-code', 'gp-method-social', 'gp-method-sms', 'gp-method-account'], false);

        // One method left: shown directly whatever the order.
        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::SOCIAL => false, LoginMethods::APPROVAL => false, LoginMethods::SMS => false]);
        $this->enter()->assertOk()->assertSee('gp-step-portal-code', false)->assertDontSee('id="gp-methods"', false);
    }

    public function test_no_available_method_shows_an_error_page(): void
    {
        LoginMethods::save([LoginMethods::CODE => false, LoginMethods::SMS => false, LoginMethods::APPROVAL => false, LoginMethods::SOCIAL => false]);
        $this->enter()->assertOk()->assertSee(__('portal.guest.error_pages.no_login_method.title'));
    }

    public function test_switches_are_enforced_server_side(): void
    {
        $this->configure(['google']);
        LoginMethods::save([LoginMethods::CODE => false, LoginMethods::SMS => true, LoginMethods::APPROVAL => true, LoginMethods::SOCIAL => true]);
        $this->enter()->assertOk()->assertSee('gp-step-portal-social', false);

        $form = ['first_name' => 'Test', 'last_name' => 'Guest', 'terms' => '1', 'terms_version' => $this->terms->id];
        $this->guest()->post('/portal/code', $form + ['email' => 'guest@example.com', 'code' => '123456'])->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.method_disabled.title'));
        // SMS and approval are switched on but lack an SMS provider / notification channel
        $this->guest()->post('/portal/sms', $form + ['phone' => '+41790000000'])->assertForbidden();
        $this->guest()->post('/portal/approval', $form)->assertForbidden();
        // Provider not configured
        $this->guest()->post('/portal/social/github', $form)->assertForbidden();

        LoginMethods::save([LoginMethods::CODE => true, LoginMethods::SOCIAL => false]);
        $this->guest()->post('/portal/social/google', $form)->assertForbidden();
        $this->guest()->get('/portal/social/google/go')->assertForbidden();
        $this->guest()->get('/portal/social/google/callback?code=x&state=y')->assertForbidden();
        $this->assertSame(0, Guest::count());
    }

    public function test_limited_mode_hides_social_login(): void
    {
        $this->configure(['google']);
        $hub = Instance::create(['name' => 'Hub', 'federation_url' => 'https://10.0.0.9:9443']);
        Setting::put(Topology::HUB_SETTING, $hub->id); // never heartbeated: unreachable
        $this->assertTrue(app(Topology::class)->limitedMode());

        $this->assertSame([LoginMethods::CODE], LoginMethods::availableList());
        $this->enter()->assertOk()->assertSee('name="code"', false)->assertDontSee(__('portal.guest.choose.social'));
        $this->guest()->post('/portal/social/google', ['terms' => '1', 'terms_version' => $this->terms->id])->assertForbidden();
        // The router keeps its walled garden: a temporary state does not change the configuration
        $this->assertContains('accounts.google.com', WalledGarden::hosts());
    }

    // --- Start ------------------------------------------------------------------------------------

    public function test_start_requires_terms_and_a_portal_origin(): void
    {
        $this->configure(['google']);
        $this->enter();

        $this->guest()->post('/portal/social/google', [])->assertSessionHasErrors('terms');
        $this->guest()->post('/portal/social/google', ['terms' => '1', 'terms_version' => 'other'])->assertSessionHasErrors('terms');
        $this->assertNull(session(SocialLoginController::FLOW));

        // A host that is not APP_URL or a site portal URL: the callback could not reach this session
        $this->onZone('guest')->post('https://other.example.net/portal/social/google', ['terms' => '1', 'terms_version' => $this->terms->id])
            ->assertOk()->assertSee(__('portal.guest.error_pages.misconfigured.title'));
        $this->assertNull(session(SocialLoginController::FLOW));

        // Without hotspot context: back to the portal
        $this->flushSession();
        $this->start('google')->assertRedirect(route('guest.portal'));
    }

    public function test_go_redirects_to_the_provider_with_state_nonce_and_pkce(): void
    {
        $this->configure(['google']);
        $this->enter();
        $this->start('google')->assertRedirect(route('guest.social.go', 'google'));
        $flow = session(SocialLoginController::FLOW);
        $this->assertSame(self::PORTAL.'/portal/social/google/callback', $flow['redirect_uri']);
        $this->assertSame($this->terms->id, $flow['terms_version']);

        $location = $this->guest()->get('/portal/social/google/go')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('google-client', $query['client_id']);
        $this->assertSame($flow['redirect_uri'], $query['redirect_uri']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertSame($flow['state'], $query['state']);
        $this->assertSame($flow['nonce'], $query['nonce']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(JWT::urlsafeB64Encode(hash('sha256', $flow['verifier'], true)), $query['code_challenge']);
        // Socialite stays stateless: no own session keys
        $this->assertNull(session('state'));
        $this->assertNull(session('code_verifier'));
    }

    // --- Callbacks --------------------------------------------------------------------------------

    public function test_google_callback_creates_guest_acceptance_social_grant_and_handoff(): void
    {
        $this->configure(['google'], 7200);
        $this->enter();
        $this->start('google');
        $flow = session(SocialLoginController::FLOW);
        // The token answer carries the nonce of the current attempt
        $nonce = $flow['nonce'];
        Http::fake([
            'www.googleapis.com/oauth2/v4/token' => function () use (&$nonce) {
                return Http::response(['access_token' => 'at-google', 'token_type' => 'Bearer',
                    'id_token' => self::unsignedJwt(['iss' => 'https://accounts.google.com', 'aud' => 'google-client', 'sub' => 'g-123', 'nonce' => $nonce])]);
            },
            'www.googleapis.com/oauth2/v3/userinfo*' => Http::response(['sub' => 'g-123', 'given_name' => 'Gina', 'family_name' => 'Google',
                'email' => 'gina@example.com', 'email_verified' => true]),
        ]);

        $this->providerCallback('google', ['code' => 'auth-code', 'state' => $flow['state']])->assertOk()->assertViewHas('fields');

        $guest = Guest::sole();
        $this->assertSame(['Gina', 'Google', 'google', 'g-123', 'gina@example.com', 'en'],
            [$guest->first_name, $guest->last_name, $guest->social_provider, $guest->social_subject, $guest->email, $guest->locale]);
        $grant = Grant::sole();
        $this->assertSame(GrantType::Social, $grant->type);
        $this->assertSame('Google', $grant->label);
        $this->assertSame(7200, $grant->duration_s);
        $this->assertTrue($grant->allowsSite($this->site->id));
        $this->assertEqualsWithDelta(now()->addSeconds(7200)->getTimestamp(), $grant->expires_at->getTimestamp(), 5);
        $acceptance = TermsAcceptance::sole();
        $this->assertSame([$guest->id, $this->terms->id, $grant->id, self::MAC],
            [$acceptance->guest_id, $acceptance->terms_version_id, $acceptance->grant_id, $acceptance->mac]);
        $this->assertTrue(LoginAttempt::query()->where('method', 'social')->where('success', true)->exists());
        $this->assertNull(session(SocialLoginController::FLOW));

        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.googleapis.com/oauth2/v4/token'
            && $r['code'] === 'auth-code' && $r['client_secret'] === 'google-secret' && $r['code_verifier'] === $flow['verifier']
            && $r['redirect_uri'] === $flow['redirect_uri']);

        // The same device logging in again gets a new grant (like SMS)
        $this->start('google');
        $flow = session(SocialLoginController::FLOW);
        $nonce = $flow['nonce'];
        $this->providerCallback('google', ['code' => 'auth-code-2', 'state' => $flow['state']])->assertOk()->assertViewHas('fields');
        $this->assertSame(2, Grant::query()->where('type', GrantType::Social)->count());
    }

    public function test_google_nonce_mismatch_is_rejected(): void
    {
        $this->configure(['google']);
        $this->enter();
        $this->start('google');
        $flow = session(SocialLoginController::FLOW);
        Http::fake(['www.googleapis.com/oauth2/v4/token' => Http::response(['access_token' => 'at', 'id_token' => self::unsignedJwt(
            ['iss' => 'https://accounts.google.com', 'aud' => 'google-client', 'sub' => 'g-1', 'nonce' => 'other'])])]);

        $this->providerCallback('google', ['code' => 'c', 'state' => $flow['state']])->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.social_failed.title'));
        $this->assertSame(0, Guest::count());
    }

    public function test_facebook_callback_logs_in(): void
    {
        $this->configure(['facebook']);
        $this->enter();
        $this->start('facebook');
        $flow = session(SocialLoginController::FLOW);
        $this->assertStringStartsWith('https://www.facebook.com/', $location = $this->guest()->get('/portal/social/facebook/go')->headers->get('Location'));
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['email,public_profile', $flow['state']], [$query['scope'], $query['state']]);
        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'at-facebook', 'expires' => 3600]),
            'graph.facebook.com/*/me*' => Http::response(['id' => 'fb-77', 'first_name' => 'Fiona', 'last_name' => 'Facebook', 'email' => 'fiona@example.com']),
        ]);

        $this->providerCallback('facebook', ['code' => 'fb-code', 'state' => $flow['state']])->assertOk()->assertViewHas('fields');
        $guest = Guest::sole();
        $this->assertSame(['Fiona', 'Facebook', 'facebook', 'fb-77', 'fiona@example.com'],
            [$guest->first_name, $guest->last_name, $guest->social_provider, $guest->social_subject, $guest->email]);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me?') && str_contains($r->url(), 'fields=id%2Cfirst_name%2Clast_name%2Cemail'));
    }

    public function test_github_callback_splits_the_name(): void
    {
        $this->configure(['github']);
        $this->enter();
        $this->start('github');
        $flow = session(SocialLoginController::FLOW);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'at-github', 'token_type' => 'bearer']),
            'api.github.com/user/emails' => Http::response([['email' => 'old@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'gus@example.com', 'primary' => true, 'verified' => true]]),
            'api.github.com/user' => Http::response(['id' => 4711, 'node_id' => 'n', 'login' => 'gus', 'name' => 'Gus van Hub', 'avatar_url' => '']),
        ]);

        $this->providerCallback('github', ['code' => 'gh-code', 'state' => $flow['state']])->assertOk()->assertViewHas('fields');
        $guest = Guest::sole();
        $this->assertSame(['Gus', 'van Hub', 'github', '4711', 'gus@example.com'],
            [$guest->first_name, $guest->last_name, $guest->social_provider, $guest->social_subject, $guest->email]);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://github.com/login/oauth/access_token' && $r['code_verifier'] === $flow['verifier']);
    }

    public function test_missing_name_goes_straight_to_the_login(): void
    {
        $this->configure(['github']);
        $this->enter();
        $this->start('github');
        $flow = session(SocialLoginController::FLOW);
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'at-github']),
            'api.github.com/user/emails' => Http::response([]),
            'api.github.com/user' => Http::response(['id' => 99, 'node_id' => 'n', 'login' => '', 'name' => null, 'avatar_url' => '']),
        ]);

        // No name page (decision 2026-11-05): grant and router handoff right away, the guest stays nameless.
        $this->providerCallback('github', ['code' => 'gh', 'state' => $flow['state']])->assertOk()->assertViewHas('fields');
        $guest = Guest::sole();
        $this->assertSame(['', '', 'github', '99', null], [$guest->first_name, $guest->last_name, $guest->social_provider, $guest->social_subject, $guest->email]);
        $this->assertSame(GrantType::Social, Grant::sole()->type);
        $this->assertSame(1, TermsAcceptance::count());
        $this->assertNull(session(SocialLoginController::FLOW));
        // Fallback name: provider label and a short hash of provider and subject, never the raw ID.
        $this->assertSame('GitHub #'.substr(hash('sha256', 'github|99'), 0, 6), $guest->displayName());
        $this->assertMatchesRegularExpression('/^GitHub #[0-9a-f]{6}$/', $guest->displayName());
    }

    public function test_the_name_completion_page_is_gone(): void
    {
        $this->configure(['github']);
        $this->enter();
        $this->guest()->get('/portal/social/name')->assertNotFound();
        $this->guest()->post('/portal/social/name', ['first_name' => 'Otto', 'last_name' => 'Cat'])->assertNotFound();
        $this->assertSame(0, Guest::count());
    }

    public function test_display_name_falls_back_to_the_email_then_to_the_provider(): void
    {
        $named = new Guest(['first_name' => 'Gina', 'last_name' => 'Google', 'social_provider' => 'google', 'social_subject' => 'g-1']);
        $this->assertSame('Gina Google', $named->displayName());
        $this->assertSame('Google, Gina', $named->displayName(true));
        $this->assertSame('octo', (new Guest(['first_name' => 'octo', 'last_name' => '']))->displayName(true));
        $mailed = new Guest(['first_name' => '', 'last_name' => '', 'social_provider' => 'facebook', 'social_subject' => '123',
            'email' => 'fay@example.com']);
        $this->assertSame('fay@example.com', $mailed->displayName());
        $this->assertSame('f***@example.com', $mailed->displayName(email: Redact::email(...)));
        $bare = new Guest(['first_name' => '', 'last_name' => '', 'social_provider' => 'facebook', 'social_subject' => '123']);
        $this->assertSame('Facebook #'.substr(hash('sha256', 'facebook|123'), 0, 6), $bare->displayName());
    }

    public function test_approval_messages_and_webhook_carry_the_display_name(): void
    {
        $guest = Guest::create(['first_name' => '', 'last_name' => '', 'locale' => 'en', 'social_provider' => 'apple', 'social_subject' => 'a-7']);
        $request = ApprovalRequest::create(['guest_id' => $guest->id, 'site_id' => $this->site->id, 'mac' => self::MAC,
            'expires_at' => now()->addMinutes(10)]);
        $fallback = 'Apple #'.substr(hash('sha256', 'apple|a-7'), 0, 6);
        $this->assertSame($fallback, $request->guestName());
        $this->assertStringContainsString($fallback, ApprovalMessage::lines($request)[0]);

        Http::fake(['hooks.example.com/*' => Http::response()]);
        $channel = Channel::create(['kind' => 'notification', 'driver' => 'webhook', 'name' => 'Hook', 'enabled' => true,
            'config' => ['url' => 'https://hooks.example.com/in', 'secret' => 'hook-secret']]);
        (new WebhookChannel)->requested($request, $channel);
        Http::assertSent(fn (Request $r) => $r['guest'] === ['first_name' => '', 'last_name' => '', 'display_name' => $fallback]);
    }

    public function test_state_mismatch_replay_and_provider_errors_show_the_error_page(): void
    {
        $this->configure(['google']);
        $this->enter();
        $this->start('google');
        $state = session(SocialLoginController::FLOW)['state'];

        $this->providerCallback('google', ['code' => 'c', 'state' => 'forged'])->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.social_failed.title'));
        // The attempt is used up by the first callback
        $this->providerCallback('google', ['code' => 'c', 'state' => $state])->assertForbidden();

        $this->start('google');
        $state = session(SocialLoginController::FLOW)['state'];
        $this->providerCallback('google', ['error' => 'access_denied', 'state' => $state])->assertForbidden()
            ->assertSee(__('portal.guest.error_pages.social_failed.title'));

        // Expired attempt
        $this->start('google');
        $state = session(SocialLoginController::FLOW)['state'];
        $this->travel(11)->minutes();
        $this->providerCallback('google', ['code' => 'c', 'state' => $state])->assertForbidden();
        Http::assertNothingSent();
        $this->assertSame(0, Guest::count());
    }

    public function test_terms_changed_after_the_start_sends_the_guest_back(): void
    {
        $this->configure(['google']);
        $this->enter();
        $this->start('google');
        $flow = session(SocialLoginController::FLOW);
        TermsVersion::publish('en', 'Be even nicer.');
        Http::fake([
            'www.googleapis.com/oauth2/v4/token' => Http::response(['access_token' => 'at', 'id_token' => self::unsignedJwt(
                ['iss' => 'https://accounts.google.com', 'aud' => 'google-client', 'sub' => 'g-1', 'nonce' => $flow['nonce']])]),
            'www.googleapis.com/oauth2/v3/userinfo*' => Http::response(['sub' => 'g-1', 'given_name' => 'A', 'family_name' => 'B']),
        ]);

        $this->providerCallback('google', ['code' => 'c', 'state' => $flow['state']])
            ->assertRedirect(route('guest.portal', ['method' => 'social']))->assertSessionHasErrors('terms');
        $this->assertSame(0, Guest::count());
    }

    public function test_apple_form_post_bounce_and_id_token_verification(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $p8);
        $this->configure(['apple'], appleKey: $p8);
        $this->enter();
        $this->start('apple');
        $flow = session(SocialLoginController::FLOW);

        $location = $this->guest()->get('/portal/social/apple/go')->headers->get('Location');
        $this->assertStringStartsWith('https://appleid.apple.com/auth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(['form_post', 'name email', $flow['nonce'], 'apple-client'],
            [$query['response_mode'], $query['scope'], $query['nonce'], $query['client_id']]);
        $this->assertArrayNotHasKey('code_challenge', $query);

        // Cross-site POST without the session cookie: no session, no CSRF, a page forwarding to the GET callback
        $user = json_encode(['name' => ['firstName' => 'Ada', 'lastName' => 'Apple'], 'email' => 'ada@example.com']);
        $bounce = $this->onZone('guest')->post(self::PORTAL.'/portal/social/apple/callback',
            ['code' => 'apple-code', 'state' => $flow['state'], 'user' => $user]);
        $bounce->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertCookieMissing(config('session.cookie'));
        $this->assertMatchesRegularExpression('#<meta http-equiv="refresh" content="0;url=/portal/social/apple/callback\?bounce=[^"]+">#', $bounce->getContent());
        $this->assertStringNotContainsString('apple-code', $bounce->getContent());
        $this->assertStringNotContainsString('Ada', $bounce->getContent());
        preg_match('#url=([^"]+)"#', $bounce->getContent(), $m);
        $forward = html_entity_decode($m[1]);

        [$jwks, $sign] = self::rsaKeySet('apple-kid');
        $idToken = $sign(['iss' => 'https://appleid.apple.com', 'aud' => 'apple-client', 'sub' => '000123.apple', 'nonce' => $flow['nonce'],
            'email' => 'relay@privaterelay.example.com', 'iat' => time(), 'exp' => time() + 600]);
        Http::fake([
            'appleid.apple.com/auth/token' => Http::response(['access_token' => 'at-apple', 'id_token' => $idToken]),
            'appleid.apple.com/auth/keys' => Http::response($jwks),
        ]);

        $this->guest()->get($forward)->assertOk()->assertViewHas('fields');
        $guest = Guest::sole();
        $this->assertSame(['Ada', 'Apple', 'apple', '000123.apple', 'relay@privaterelay.example.com'],
            [$guest->first_name, $guest->last_name, $guest->social_provider, $guest->social_subject, $guest->email]);
        $this->assertSame('Apple', Grant::sole()->label);

        // The client secret is an ES256 JWT of the configured key
        Http::assertSent(function (Request $r) use ($ec) {
            if ($r->url() !== 'https://appleid.apple.com/auth/token') {
                return false;
            }
            $claims = (array) JWT::decode($r['client_secret'], new Key(openssl_pkey_get_details($ec)['key'], 'ES256'));

            return $claims['iss'] === 'TEAM123456' && $claims['sub'] === 'apple-client' && $claims['aud'] === 'https://appleid.apple.com'
                && json_decode(JWT::urlsafeB64Decode(explode('.', $r['client_secret'])[0]), true)['kid'] === 'KEY1234567';
        });
    }

    public function test_apple_id_token_checks_signature_audience_and_nonce(): void
    {
        $ec = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($ec, $p8);
        $this->configure(['apple'], appleKey: $p8);
        [$jwks, $sign] = self::rsaKeySet('apple-kid');
        [, $foreign] = self::rsaKeySet('apple-kid');
        $claims = fn (array $flow) => ['iss' => 'https://appleid.apple.com', 'aud' => 'apple-client', 'sub' => 's',
            'nonce' => $flow['nonce'], 'iat' => time(), 'exp' => time() + 600];

        $cases = [
            'audience' => fn ($flow) => $sign(['aud' => 'other-client'] + $claims($flow)),
            'nonce' => fn ($flow) => $sign(['nonce' => 'other'] + $claims($flow)),
            'expired' => fn ($flow) => $sign(['exp' => time() - 3600] + $claims($flow)),
            'signature' => fn ($flow) => $foreign($claims($flow)),
        ];
        $this->enter();
        $current = null;
        Http::fake([
            'appleid.apple.com/auth/token' => function () use (&$current) {
                return Http::response(['id_token' => $current]);
            },
            'appleid.apple.com/auth/keys' => Http::response($jwks),
        ]);
        foreach ($cases as $case => $token) {
            $this->start('apple');
            $flow = session(SocialLoginController::FLOW);
            $current = $token($flow);
            $this->providerCallback('apple', ['code' => 'c', 'state' => $flow['state']])->assertForbidden();
            $this->assertSame(0, Guest::count(), $case);
        }
    }

    public function test_an_invalid_or_old_bounce_is_rejected(): void
    {
        $this->configure(['apple'], appleKey: 'unused');
        $this->enter();
        $this->start('apple');
        $this->providerCallback('apple', ['bounce' => 'garbage'])->assertForbidden();

        $this->start('apple');
        $state = session(SocialLoginController::FLOW)['state'];
        $page = $this->onZone('guest')->post(self::PORTAL.'/portal/social/apple/callback', ['code' => 'c', 'state' => $state])->getContent();
        preg_match('#url=([^"]+)"#', $page, $m);
        $this->travel(6)->minutes();
        $this->guest()->get(html_entity_decode($m[1]))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_provider_failures_are_logged_without_tokens(): void
    {
        $this->configure(['facebook']);
        $this->enter();
        $this->start('facebook');
        $flow = session(SocialLoginController::FLOW);
        Http::fake([
            'graph.facebook.com/*/oauth/access_token' => Http::response(['access_token' => 'secret-access-token']),
            'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'bad']], 400),
        ]);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context);
        });

        $this->providerCallback('facebook', ['code' => 'c', 'state' => $flow['state']])->assertForbidden();
        $line = collect($logged)->first(fn ($l) => str_starts_with($l, 'Social login failed'));
        $this->assertStringContainsString('HTTP 400', (string) $line);
        $this->assertStringNotContainsString('secret-access-token', implode("\n", $logged));
    }

    // --- Admin guest list -------------------------------------------------------------------------

    public function test_guests_list_shows_the_social_method_and_masks_the_email_for_managers(): void
    {
        $this->configure(['google']);
        $guest = Guest::create(['first_name' => 'Gina', 'last_name' => 'Google', 'locale' => 'en',
            'social_provider' => 'google', 'social_subject' => 'g-1', 'email' => 'gina@example.com']);
        Guest::create(['first_name' => 'Carl', 'last_name' => 'Code', 'locale' => 'en']);
        // Nameless social guests: the (masked) email, else the provider fallback (decision 2026-11-05).
        Guest::create(['first_name' => '', 'last_name' => '', 'locale' => 'en', 'social_provider' => 'facebook',
            'social_subject' => 'fb-1', 'email' => 'fay@example.com']);
        Guest::create(['first_name' => '', 'last_name' => '', 'locale' => 'en', 'social_provider' => 'github', 'social_subject' => '4711']);
        $hash = substr(hash('sha256', 'github|4711'), 0, 6);
        $grant = app(GrantManager::class)->createTimedGrant(GrantType::Social, $this->site, 3600, 'Google');
        TermsAcceptance::create(['guest_id' => $guest->id, 'terms_version_id' => $this->terms->id, 'grant_id' => $grant->id,
            'site_id' => $this->site->id, 'mac' => self::MAC, 'accepted_at' => now()]);

        $admin = User::factory()->admin()->create(['name' => 'Site Admin']);
        $this->onZone('admin')->actingAs($admin)->get('/admin/guests?method=social')->assertOk()
            ->assertSee('Social (Google)')->assertSee('gina@example.com')->assertDontSee('Carl')
            ->assertSee('<option value="social" selected', false);
        $manager = User::factory()->create(['role' => 'manager', 'name' => 'Site Manager']);
        $this->onZone('admin')->actingAs($manager)->get('/admin/guests')->assertOk()
            ->assertSee('g***@example.com')->assertDontSee('gina@example.com')->assertSee('Code, Carl')
            ->assertSee('f***@example.com')->assertDontSee('fay@example.com')->assertSee('GitHub #'.$hash)->assertDontSee('4711')
            ->assertDontSee('<div class="font-medium">, </div>', false);
        $this->onZone('admin')->actingAs($admin)->get('/admin/guests')->assertOk()
            ->assertSee('fay@example.com')->assertSee('GitHub #'.$hash);

        $this->assertSame('a***@example.com', Redact::email('alice@example.com'));
        $this->assertSame('', Redact::email(null));
        $this->assertSame('***', Redact::email('no-at-sign'));
    }

    // --- Walled garden ----------------------------------------------------------------------------

    public function test_router_script_adds_walled_garden_entries_of_the_enabled_providers(): void
    {
        $nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
        $script = fn () => $this->site->fresh()->driver()->configScript($this->site->fresh(), $nas);
        $key = fn (string $host) => MikrotikRos7Driver::key('walled', WalledGarden::object($host));

        // Off: no entries, but the own entries of every provider are removed
        $off = $script();
        $this->assertStringNotContainsString('add dst-host="accounts.google.com"', $off);
        $this->assertStringContainsString('remove [find where (comment="'.$key('accounts.google.com').'" || ', $off);
        $this->assertStringContainsString('comment="'.$key('*.githubusercontent.com').'") && !dynamic]', $off);

        $this->configure(['google', 'github']);
        $on = $script();
        $this->assertStringContainsString('add dst-host="accounts.google.com" action=allow comment="guest-portal:walled:social-accounts-google-com"', $on);
        $this->assertStringContainsString('add dst-host="*.gstatic.com" action=allow comment="guest-portal:walled:social-any-gstatic-com"', $on);
        $this->assertStringContainsString('add dst-host="*.githubassets.com" action=allow', $on);
        $this->assertStringNotContainsString('add dst-host="appleid.apple.com"', $on);
        $this->assertStringNotContainsString('add dst-host="*.facebook.com"', $on);
        // Social login switched off again: the entries go away
        LoginMethods::save([LoginMethods::SOCIAL => false]);
        $this->assertStringNotContainsString('add dst-host="accounts.google.com"', $script());

        // Uninstall removes the own entries of all providers
        $uninstall = implode("\n", $this->site->driver()->setupCommands($this->site, [])['uninstall']);
        $this->assertStringContainsString('/ip hotspot walled-garden remove [find where (comment="'.$key('accounts.google.com').'"', $uninstall);

        // Object names: unique, valid comment key characters
        $objects = array_map(WalledGarden::object(...), WalledGarden::allHosts());
        $this->assertSame($objects, array_unique($objects));
        foreach ($objects as $object) {
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $object);
        }
    }

    public function test_router_state_detects_missing_and_stale_social_entries(): void
    {
        $this->configure(['facebook']);
        $nas = NasClient::find($this->site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.1.1', 'coa_port' => 3799])->id);
        $driver = $this->site->driver();
        $key = fn (string $host) => MikrotikRos7Driver::key('walled', WalledGarden::object($host));
        $row = fn (string $host) => ['dst-host' => $host, 'action' => 'allow', 'comment' => $key($host)];

        $this->assertArrayHasKey($key('*.facebook.com'), $driver->managedObjects($this->site)['walled']);
        $this->assertSame(['dst-host' => '*.fbcdn.net', 'action' => 'allow'], $driver->expectedValues($this->site)[$key('*.fbcdn.net')]);

        $rows = [$row('*.facebook.com'), $row('*.fbcdn.net'), $row('*.facebook.net'), ['dst-host' => '*.fbsbx.com', 'action' => 'deny', 'comment' => $key('*.fbsbx.com')],
            // An entry of a provider no longer offered
            $row('github.com')];
        $compare = $driver->compareState($this->site, ['menus' => ['/ip hotspot walled-garden' => ['rows' => $rows]]]);
        $this->assertSame('present', $compare['walled'][$key('*.facebook.com')]['status']);
        $this->assertSame('differs', $compare['walled'][$key('*.fbsbx.com')]['status']);
        $this->assertSame('differs', $compare['walled'][$key('github.com')]['status']);
        $this->assertArrayNotHasKey($key('*.github.com'), $compare['walled']);

        $compare = $driver->compareState($this->site, ['menus' => ['/ip hotspot walled-garden' => ['rows' => []]]]);
        $this->assertSame('missing', $compare['walled'][$key('*.facebook.com')]['status']);
        // The script lines of the entries are marked for the setup assistant
        $script = $driver->configScript($this->site, $nas);
        $lines = explode("\n", $script);
        $statuses = $driver->lineStatuses($script, ['guest-portal:walled:social-any-facebook-com' => 'missing']);
        $index = array_search('add dst-host="*.facebook.com" action=allow comment="'.$key('*.facebook.com').'"', $lines, true);
        $this->assertNotFalse($index);
        $this->assertSame('missing', $statuses[$index]);
    }

    // --- Helpers ----------------------------------------------------------------------------------

    /** @param  list<string>  $providers */
    private function configure(array $providers, int $duration = 86400, ?string $appleKey = null): void
    {
        LoginMethods::save([LoginMethods::SOCIAL => true]);
        $config = [];
        $secrets = [];
        foreach ($providers as $provider) {
            $config[$provider] = ['enabled' => true, 'client_id' => $provider.'-client']
                + ($provider === 'apple' ? ['team_id' => 'TEAM123456', 'key_id' => 'KEY1234567'] : []);
            $secrets[$provider] = $provider === 'apple' ? (string) $appleKey : $provider.'-secret';
        }
        SocialSettings::save(['duration_s' => $duration, 'providers' => $config], $secrets);
        $this->assertSame($providers, SocialSettings::enabledProviders());
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

    private function start(string $provider): TestResponse
    {
        return $this->guest()->post(self::PORTAL.'/portal/social/'.$provider, ['terms' => '1', 'terms_version' => $this->terms->id]);
    }

    private function providerCallback(string $provider, array $query): TestResponse
    {
        return $this->guest()->get(self::PORTAL.'/portal/social/'.$provider.'/callback?'.http_build_query($query));
    }

    private static function unsignedJwt(array $claims): string
    {
        return JWT::urlsafeB64Encode('{"alg":"RS256","typ":"JWT"}').'.'.JWT::urlsafeB64Encode((string) json_encode($claims)).'.sig';
    }

    /** @return array{0: array, 1: \Closure(array): string} JWKS with one fresh RSA key and a signer for it */
    private static function rsaKeySet(string $kid): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($key);
        $jwks = ['keys' => [['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]]];

        return [$jwks, fn (array $claims) => JWT::encode($claims, $key, 'RS256', $kid)];
    }
}
