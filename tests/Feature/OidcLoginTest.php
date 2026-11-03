<?php

namespace Tests\Feature;

use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcSettings;
use App\Auth\TwoFactor;
use App\Enums\Role;
use App\Livewire\Admin\Login;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * v0.9.0: OIDC login for the admin UI (CONCEPT.md §9) against a faked identity provider with a
 * locally generated RSA key.
 */
class OidcLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://idp.example.com/application/o/portal/';

    private const CLIENT_ID = 'portal-client';

    private const CLIENT_SECRET = 'test-oidc-client-secret-value';

    private const CODE = 'test-authorization-code';

    private const KID = 'test-key-1';

    private static ?string $privateKey = null;

    private static ?string $otherKey = null;

    /** Claims (or a closure returning the JWT) for the next token response. */
    private array $claims = [];

    private ?string $idToken = null;

    private array $userinfo = [];

    /** @var list<HttpRequest> */
    private array $tokenRequests = [];

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$privateKey ??= self::newKey();
        self::$otherKey ??= self::newKey();
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logs[] = $e->message.' '.json_encode($e->context));

        Setting::put('admin_url', 'https://portal.example.com:8443');
        OidcSettings::save(['enabled' => true, 'label' => 'Authentik', 'issuer' => self::ISSUER, 'client_id' => self::CLIENT_ID], self::CLIENT_SECRET);
        $this->fakeIdp();
    }

    public function test_happy_path_provisions_admin_with_pkce_and_client_auth(): void
    {
        $this->onZone('admin')->get('/admin/login')->assertOk()->assertSee('Authentik');

        [$state, $nonce, $query] = $this->startLogin();
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://portal.example.com:8443/admin/oidc/callback', $query['redirect_uri']);
        $this->assertSame('openid profile email groups', $query['scope']);

        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin', 'other']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));

        $user = User::where('email', 'alice@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(Role::Admin, $user->role);
        $this->assertSame(self::ISSUER.' alice-sub', $user->oidc_subject);
        $this->assertSame('Alice Example', $user->name);
        $this->assertFalse(password_verify('', $user->password));

        $token = $this->tokenRequests[0];
        $this->assertSame('Basic '.base64_encode(self::CLIENT_ID.':'.self::CLIENT_SECRET), $token->header('Authorization')[0]);
        $this->assertSame(OidcClient::codeChallenge($token->data()['code_verifier']), $query['code_challenge']);
        $this->assertSame(self::CODE, $token->data()['code']);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'user_id' => $user->id]);
        $login = AuditLog::where('action', 'auth.login')->sole();
        $this->assertSame('oidc', $login->data['method']);
    }

    public function test_cross_site_callback_without_cookie_bounces_once_on_the_same_origin(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);

        // The IdP's cross-site redirect carries no SameSite=Strict cookie: a page that reloads the URL here.
        $response = $this->onZone('admin')->get('/admin/oidc/callback?'.http_build_query(['code' => self::CODE, 'state' => $state]))
            ->assertOk()->assertCookieMissing('portal_admin_session')->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $url = '/admin/oidc/callback?'.http_build_query(['bounce' => '1', 'code' => self::CODE, 'state' => $state]);
        $response->assertSee('<meta http-equiv="refresh" content="0;url='.e($url).'">', false)->assertDontSee('<script', false);
        $this->assertSame([], $this->tokenRequests);
        $this->assertGuest();
        $this->assertNotNull(session('oidc.flow'), 'the flow is not consumed by the bounce');

        // The reload is same-site and carries the cookie: the login completes.
        $this->onZone('admin')->withCookie('portal_admin_session', session()->getId())->get($url)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs(User::where('email', 'alice@example.com')->sole());
    }

    public function test_bounced_callback_without_the_flow_fails_as_before(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        session()->forget('oidc.flow'); // the cookie did not come back after the bounce either

        $this->onZone('admin')->get('/admin/oidc/callback?'.http_build_query(['bounce' => '1', 'code' => self::CODE, 'state' => $state]))
            ->assertRedirect(route('login'))->assertSessionHas('oidc_error', __('portal.oidc.failed'));
        $this->assertGuest();
        $this->assertFailure('state');
        $this->assertSame([], $this->tokenRequests);
    }

    public function test_manager_is_provisioned_and_disabled_oidc_is_hidden(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertSame(Role::Manager, User::where('email', 'alice@example.com')->sole()->role);

        auth()->logout();
        OidcSettings::save(['enabled' => false] + OidcSettings::all(), null);
        $this->onZone('admin')->get('/admin/login')->assertOk()->assertDontSee('Authentik');
        $this->onZone('admin')->get('/admin/oidc/redirect')->assertNotFound();
    }

    public function test_existing_user_is_linked_by_verified_email_then_matched_by_subject(): void
    {
        $existing = User::factory()->create(['email' => 'Alice@example.com']);

        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($existing);
        $this->assertSame(self::ISSUER.' alice-sub', $existing->fresh()->oidc_subject);
        $this->assertSame(1, User::count());
        auth()->logout();

        // Afterwards the subject decides, not the email.
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager'], 'email' => 'renamed@example.com', 'email_verified' => false]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_unverified_email_is_not_linked(): void
    {
        $existing = User::factory()->admin()->create(['email' => 'alice@example.com']);

        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'email_verified' => false]);
        $this->finishLogin($state)->assertRedirect(route('login'))->assertSessionHas('oidc_error', __('portal.oidc.failed'));
        $this->assertGuest();
        $this->assertNull($existing->fresh()->oidc_subject);
        $this->assertFailure('email_unverified');
    }

    public function test_no_matching_group_is_denied(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['somebody-else']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertFailure('no_group');
    }

    public function test_role_is_resynced_on_every_login(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->create(['oidc_subject' => self::ISSUER.' alice-sub']);

        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin', 'portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertSame(Role::Admin, $user->fresh()->role);
        auth()->logout();

        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertSame(Role::Manager, $user->fresh()->role);
        $this->assertSame(2, AuditLog::where('action', 'user.role_synced')->count());
        auth()->logout();

        // Removing all groups denies the login and keeps the role.
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => []]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(Role::Manager, $user->fresh()->role);
    }

    public function test_last_admin_cannot_be_demoted(): void
    {
        $user = User::factory()->admin()->create(['oidc_subject' => self::ISSUER.' alice-sub']);

        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(Role::Admin, $user->fresh()->role);
        $this->assertFailure('last_admin');
    }

    public function test_bad_state_and_missing_flow_are_rejected(): void
    {
        [, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        $this->finishLogin('wrong-state')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFailure('state');
        $this->assertSame([], $this->tokenRequests);

        // The flow is single-use: replaying the callback fails as well.
        $this->finishLogin('wrong-state')->assertRedirect(route('login'));
        $this->assertSame(0, User::count());
    }

    public function test_bad_nonce_is_rejected(): void
    {
        [$state] = $this->startLogin();
        $this->claims = $this->baseClaims('another-nonce', ['groups' => ['portal-admin']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFailure('nonce');
    }

    public function test_bad_signature_is_rejected(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->idToken = JWT::encode($this->baseClaims($nonce, ['groups' => ['portal-admin']]), self::$otherKey, 'RS256', self::KID);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFailure('id_token');
        $this->assertLogged('SignatureInvalidException');

        // "none" and HMAC algorithms are refused before any key lookup.
        [$state, $nonce] = $this->startLogin();
        $this->idToken = JWT::encode($this->baseClaims($nonce, ['groups' => ['portal-admin']]), str_repeat('k', 64), 'HS256', self::KID);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertLogged('Unsupported ID token algorithm');
    }

    public function test_wrong_audience_and_issuer_are_rejected(): void
    {
        foreach ([['aud' => 'another-client'], ['iss' => 'https://evil.example.com/']] as $override) {
            [$state, $nonce] = $this->startLogin();
            $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']] + $override);
            $this->finishLogin($state)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->assertSame(2, AuditLog::where('action', 'auth.oidc_failed')->where('data->reason', 'id_token')->count());
        $this->assertLogged('ID token audience mismatch');
        $this->assertLogged('ID token issuer mismatch');
    }

    public function test_expired_token_is_rejected(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'iat' => time() - 600, 'exp' => time() - 120]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFailure('id_token');
        $this->assertLogged('ExpiredException');
    }

    public function test_groups_and_email_from_userinfo_when_missing_in_id_token(): void
    {
        [$state, $nonce] = $this->startLogin();
        $claims = $this->baseClaims($nonce, []);
        unset($claims['email'], $claims['email_verified']);
        $this->claims = $claims;
        $this->userinfo = ['sub' => 'alice-sub', 'email' => 'alice@example.com', 'email_verified' => true, 'groups' => ['portal-manager']];
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertSame(Role::Manager, User::where('email', 'alice@example.com')->sole()->role);

        // Userinfo for another subject is ignored as an error.
        auth()->logout();
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, []);
        unset($this->claims['email']);
        $this->userinfo = ['sub' => 'mallory', 'groups' => ['portal-admin']];
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertFailure('userinfo');
    }

    public function test_totp_modes(): void
    {
        $twoFactor = app(TwoFactor::class);
        $user = User::factory()->admin()->create(['oidc_subject' => self::ISSUER.' alice-sub']);
        $twoFactor->begin($user);
        $user->forceFill(['totp_confirmed_at' => now()])->save();

        // Default skip_if_idp_mfa: amr "mfa" skips the portal code.
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['pwd', 'mfa']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        auth()->logout();

        // Without MFA at the provider the existing code step follows.
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['pwd']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        Livewire::test(Login::class)->assertSet('twoFactor', true)
            ->set('code', $twoFactor->currentCode($user->fresh()->totp_secret))->call('verify')
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(AuditLog::where('action', 'auth.login')->where('data->method', 'oidc_two_factor')->exists());
        auth()->logout();
        session()->flush();

        // A configured acr value counts as MFA.
        OidcSettings::save(['acr' => 'urn:example:acr:mfa'] + OidcSettings::all(), null);
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'acr' => 'urn:example:acr:mfa']);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        auth()->logout();

        // always: code step even with MFA at the provider.
        OidcSettings::save(['totp_mode' => OidcSettings::TOTP_ALWAYS] + OidcSettings::all(), null);
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['mfa']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        session()->flush();

        // never: no code step.
        OidcSettings::save(['totp_mode' => OidcSettings::TOTP_NEVER] + OidcSettings::all(), null);
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_settings_store_secret_encrypted_and_never_log_or_audit_it(): void
    {
        $admin = User::factory()->admin()->create();
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee('https://portal.example.com:8443/admin/oidc/callback')->assertDontSee(self::CLIENT_SECRET);

        $form = ['oidc_enabled' => '1', 'oidc_label' => 'Authentik', 'oidc_issuer' => self::ISSUER, 'oidc_client_id' => self::CLIENT_ID,
            'oidc_client_secret' => '', 'oidc_scopes' => 'openid profile email groups', 'oidc_groups_claim' => 'groups',
            'oidc_admin_group' => 'portal-admin', 'oidc_manager_group' => 'portal-manager', 'oidc_totp_mode' => 'skip_if_idp_mfa', 'oidc_acr' => ''];
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/oidc', ['oidc_issuer' => 'http://idp.example.com'] + $form)
            ->assertSessionHasErrors('oidc_issuer');
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/oidc', ['oidc_scopes' => 'profile email'] + $form)
            ->assertSessionHasErrors('oidc_scopes');
        // Empty secret keeps the stored one.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/oidc', $form)->assertSessionHasNoErrors();
        $this->assertSame(self::CLIENT_SECRET, OidcSettings::clientSecret());
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/oidc', ['oidc_client_secret' => 'a-new-oidc-secret-value'] + $form)
            ->assertSessionHasNoErrors();
        $this->assertSame('a-new-oidc-secret-value', OidcSettings::clientSecret());
        $stored = DB::table('settings')->where('key', OidcSettings::SECRET_SETTING)->value('value');
        $this->assertStringNotContainsString('a-new-oidc-secret-value', $stored);
        $this->assertSame(['skip_if_idp_mfa'], AuditLog::where('action', 'settings.oidc')->get()->pluck('data.mfa_mode')->unique()->values()->all());
        OidcSettings::save(OidcSettings::all(), self::CLIENT_SECRET);
        auth()->logout();

        // A failing and a successful login: neither the secret nor the code or tokens reach log or audit.
        [$state, $nonce] = $this->startLogin();
        $this->tokenStatus = 400;
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->tokenStatus = 200;
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));

        $this->assertNotEmpty($this->logs);
        $audit = AuditLog::all()->toJson();
        foreach ([self::CLIENT_SECRET, 'a-new-oidc-secret-value', self::CODE, $this->lastIdToken] as $secret) {
            $this->assertStringNotContainsString($secret, implode("\n", $this->logs));
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    public function test_linking_can_be_disabled_and_always_asks_the_local_totp_once(): void
    {
        $twoFactor = app(TwoFactor::class);
        $existing = User::factory()->admin()->create(['email' => 'alice@example.com']);
        $twoFactor->begin($existing);
        $existing->forceFill(['totp_confirmed_at' => now()])->save();

        OidcSettings::save(['link_by_email' => false] + OidcSettings::all(), null);
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['mfa']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNull($existing->fresh()->oidc_subject);
        $this->assertFailure('link_disabled');

        // Linking enabled, mode "never": the linking login still needs the local code.
        OidcSettings::save(['link_by_email' => true, 'totp_mode' => OidcSettings::TOTP_NEVER] + OidcSettings::all(), null);
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['mfa']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotNull($existing->fresh()->oidc_subject);
        $this->assertTrue(AuditLog::where('action', 'user.oidc_linked')->where('subject_id', $existing->id)->exists());
        Livewire::test(Login::class)->assertSet('twoFactor', true)
            ->set('code', $twoFactor->currentCode($existing->fresh()->totp_secret))->call('verify')
            ->assertRedirect(route('admin.dashboard'));
        auth()->logout();
        session()->flush();

        // Once linked, the mode applies again.
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($existing);
    }

    public function test_lost_groups_and_role_change_end_other_sessions(): void
    {
        User::factory()->admin()->create();
        $user = User::factory()->admin()->create(['oidc_subject' => self::ISSUER.' alice-sub']);
        $this->addSession($user, 'old-session-1');

        // Role change: other sessions end, the new login stays.
        $token = $user->remember_token;
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertFalse(DB::table('sessions')->where('id', 'old-session-1')->exists());
        $this->assertNotSame($token, $user->fresh()->remember_token);
        auth()->logout();

        // Same role: sessions are kept.
        $this->addSession($user, 'old-session-2');
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-manager']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(DB::table('sessions')->where('id', 'old-session-2')->exists());
        auth()->logout();

        // No group: denied and all sessions end.
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => []]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertFalse(DB::table('sessions')->where('user_id', $user->id)->exists());
        $this->assertFailure('no_group');
    }

    public function test_non_string_query_parameters_give_the_generic_error(): void
    {
        foreach (['code' => ['x' => 'y'], 'state' => ['a'], 'error' => ['b']] as $param => $value) {
            [$state, $nonce] = $this->startLogin();
            $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
            $query = array_merge(['code' => self::CODE, 'state' => $state], [$param => $value]);
            $this->callbackWithCookie($query)
                ->assertRedirect(route('login'))->assertSessionHas('oidc_error', __('portal.oidc.failed'));
            $this->assertGuest();
        }
        $this->assertSame([], $this->tokenRequests);
        $this->assertFailure('token');
        $this->assertFailure('state');
        $this->assertFailure('idp_error');
        $this->assertSame(0, AuditLog::where('action', 'auth.oidc_failed')->where('data->reason', 'error')->count());
    }

    public function test_sms_amr_does_not_skip_totp_and_max_auth_age_is_checked(): void
    {
        $twoFactor = app(TwoFactor::class);
        $user = User::factory()->admin()->create(['oidc_subject' => self::ISSUER.' alice-sub']);
        $twoFactor->begin($user);
        $user->forceFill(['totp_confirmed_at' => now()])->save();

        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['pwd', 'sms']]);
        $this->finishLogin($state)->assertRedirect(route('login'));
        $this->assertGuest();
        session()->flush();

        OidcSettings::save(['max_auth_age' => 300] + OidcSettings::all(), null);
        foreach ([[time() - 3600, false], [null, false], [time() - 60, true]] as [$authTime, $skipped]) {
            [$state, $nonce] = $this->startLogin();
            $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin'], 'amr' => ['mfa']] + ($authTime ? ['auth_time' => $authTime] : []));
            $this->finishLogin($state)->assertRedirect($skipped ? route('admin.dashboard') : route('login'));
            $skipped ? $this->assertAuthenticatedAs($user) : $this->assertGuest();
            auth()->logout();
            session()->flush();
        }
    }

    public function test_provisioning_requires_verified_email(): void
    {
        foreach ([['email_verified' => false], ['email_verified' => 'true']] as $override) {
            [$state, $nonce] = $this->startLogin();
            $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']] + $override);
            $this->userinfo = ['sub' => 'alice-sub'];
            $this->finishLogin($state)->assertRedirect(route('login'));
            $this->assertGuest();
        }
        $this->assertSame(0, User::count());
        $this->assertFailure('email_unverified');

        // email_verified only in userinfo is used.
        [$state, $nonce] = $this->startLogin();
        $claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        unset($claims['email_verified']);
        $this->claims = $claims;
        $this->userinfo = ['sub' => 'alice-sub', 'email_verified' => true];
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertSame(1, User::count());
    }

    public function test_undecryptable_secret_is_reported_without_value(): void
    {
        Setting::put(OidcSettings::SECRET_SETTING, 'not-an-encrypted-value');
        $this->assertNull(OidcSettings::clientSecret());
        $this->assertLogged('OIDC client secret cannot be decrypted');
        $this->assertStringNotContainsString('not-an-encrypted-value', implode("\n", $this->logs));
        $this->onZone('admin')->actingAs(User::factory()->admin()->create())->get('/admin/settings')
            ->assertOk()->assertSee(__('portal.oidc.client_secret_broken'));
    }

    public function test_forget_cache_clears_discovery_and_jwks(): void
    {
        [$state, $nonce] = $this->startLogin();
        $this->claims = $this->baseClaims($nonce, ['groups' => ['portal-admin']]);
        $this->finishLogin($state)->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull(cache()->get('oidc.jwks.'.sha1(self::ISSUER.'jwks/')));
        OidcClient::forgetCache(self::ISSUER);
        $this->assertNull(cache()->get('oidc.jwks.'.sha1(self::ISSUER.'jwks/')));
        $this->assertNull(cache()->get('oidc.discovery.'.sha1(rtrim(self::ISSUER, '/'))));
    }

    private function addSession(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    private int $tokenStatus = 200;

    private string $lastIdToken = '';

    private function fakeIdp(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::ISSUER.'.well-known/openid-configuration' => Http::response([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'https://idp.example.com/application/o/authorize/',
                'token_endpoint' => 'https://idp.example.com/application/o/token/',
                'userinfo_endpoint' => 'https://idp.example.com/application/o/userinfo/',
                'jwks_uri' => self::ISSUER.'jwks/',
                'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            ]),
            self::ISSUER.'jwks/' => fn () => Http::response(['keys' => [self::jwk(self::$privateKey, self::KID)]]),
            'https://idp.example.com/application/o/token/' => function (HttpRequest $request) {
                $this->tokenRequests[] = $request;
                if ($this->tokenStatus !== 200) {
                    return Http::response(['error' => 'invalid_grant', 'error_description' => 'code '.self::CODE], $this->tokenStatus);
                }
                $this->lastIdToken = $this->idToken ?? JWT::encode($this->claims, self::$privateKey, 'RS256', self::KID);

                return Http::response(['access_token' => 'test-access-token-value', 'token_type' => 'Bearer', 'id_token' => $this->lastIdToken]);
            },
            'https://idp.example.com/application/o/userinfo/' => fn () => Http::response($this->userinfo),
        ]);
    }

    /** @return array{0: string, 1: string, 2: array<string, string>} state, nonce, query */
    private function startLogin(): array
    {
        $this->idToken = null;
        $location = $this->onZone('admin')->get('/admin/oidc/redirect')->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://idp.example.com/application/o/authorize/?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);

        return [$query['state'], $query['nonce'], $query];
    }

    private function finishLogin(string $state)
    {
        return $this->callbackWithCookie(['code' => self::CODE, 'state' => $state]);
    }

    /** Callback with the admin session cookie (sent when the browser comes back same-site or after the bounce). */
    private function callbackWithCookie(array $query)
    {
        return $this->onZone('admin')->withCookie('portal_admin_session', session()->getId())
            ->get('/admin/oidc/callback?'.http_build_query($query));
    }

    private function baseClaims(string $nonce, array $override): array
    {
        return array_merge([
            'iss' => self::ISSUER,
            'sub' => 'alice-sub',
            'aud' => self::CLIENT_ID,
            'iat' => time(),
            'exp' => time() + 300,
            'nonce' => $nonce,
            'email' => 'alice@example.com',
            'email_verified' => true,
            'name' => 'Alice Example',
        ], $override);
    }

    private function assertFailure(string $reason): void
    {
        $this->assertTrue(AuditLog::where('action', 'auth.oidc_failed')->where('data->reason', $reason)->exists(),
            "No auth.oidc_failed audit with reason $reason");
    }

    private function assertLogged(string $needle): void
    {
        $this->assertStringContainsString($needle, implode("\n", $this->logs));
    }

    private static function newKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private static function jwk(string $privatePem, string $kid): array
    {
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($privatePem))['rsa'];

        return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
            'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])];
    }
}
