<?php

namespace Tests\Feature;

use App\Auth\Social\AppleClientSecret;
use App\Auth\Social\LoginMethods;
use App\Auth\Social\ProviderCheck;
use App\Auth\Social\SocialSettings;
use App\Auth\Social\WalledGarden;
use App\Federation\DataKey;
use App\Federation\FederatedSecrets;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * v0.22.0: social login provider settings, setup guide and check button (CONCEPT.md §9, decision
 * 2026-11-03). Provider answers are faked; the Apple key is generated per test run.
 */
class SocialSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_SECRET = 'test-google-client-secret';

    private const GITHUB_SECRET = 'test-github-client-secret';

    private const FACEBOOK_SECRET = 'test-facebook-app-secret';

    private static ?string $appleKey = null;

    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://portal.example.com']);
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logs[] = $e->message.' '.json_encode($e->context));
    }

    private static function ecKey(string $curve = 'prime256v1'): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        openssl_pkey_export($key, $pem);

        return $pem;
    }

    private static function appleKey(): string
    {
        return self::$appleKey ??= self::ecKey();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /** Form payload with all providers off and no secrets. */
    private function form(array $providers = [], float $hours = 24): array
    {
        $base = [];
        foreach (SocialSettings::PROVIDERS as $provider) {
            $base[$provider] = ['enabled' => '0', 'client_id' => '', 'secret' => ''];
        }
        $base['apple'] += ['team_id' => '', 'key_id' => ''];

        return ['social_duration_hours' => $hours, 'providers' => array_replace_recursive($base, $providers)];
    }

    private function configureAll(): void
    {
        SocialSettings::save(['providers' => [
            'google' => ['enabled' => true, 'client_id' => 'google-client.apps.example.com'],
            'apple' => ['enabled' => true, 'client_id' => 'com.example.portal', 'team_id' => 'TEAM123456', 'key_id' => 'KEY1234567'],
            'facebook' => ['enabled' => true, 'client_id' => '1234567890'],
            'github' => ['enabled' => true, 'client_id' => 'gh-client-id'],
        ]], ['google' => self::GOOGLE_SECRET, 'apple' => self::appleKey(), 'facebook' => self::FACEBOOK_SECRET, 'github' => self::GITHUB_SECRET]);
    }

    private function assertNoSecretsIn(string $text): void
    {
        foreach ([self::GOOGLE_SECRET, self::GITHUB_SECRET, self::FACEBOOK_SECRET, 'PRIVATE KEY'] as $secret) {
            $this->assertStringNotContainsString($secret, $text);
        }
    }

    public function test_section_renders_for_admins_with_guides_and_redirect_uris(): void
    {
        Site::create(['slug' => 'site-b', 'name' => 'Site B', 'driver' => 'mikrotik_ros7', 'settings' => ['portal_url' => 'https://guest-b.example.com/']]);

        $response = $this->onZone('admin')->actingAs($this->admin())->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.settings.social.title'))
            ->assertSee(__('portal.settings.social.services_id'))
            ->assertSee(__('portal.settings.social.guide.apple.4'))
            ->assertSee(__('portal.settings.social.guide.github.0'))
            ->assertSee(__('portal.settings.social.walled_garden'))
            ->assertSee('https://portal.example.com/portal/social/google/callback')
            ->assertSee('https://guest-b.example.com/portal/social/google/callback')
            ->assertSee('https://guest-b.example.com/portal/social/apple/callback')
            ->assertSee('https://guest-b.example.com/portal/social/github/callback')
            ->assertSee(__('portal.settings.social.guide.github.2'))
            ->assertDontSee('data-github-single-callback', false)
            ->assertSee(__('portal.settings.social.secret_none'))
            ->assertSee(__('portal.settings.social.not_ready', ['items' => implode(', ', [
                __('portal.settings.social.missing.enabled'), __('portal.settings.social.missing.client_id'), __('portal.settings.social.missing.secret'),
            ])]));
        foreach (SocialSettings::PROVIDERS as $provider) {
            $response->assertSee('id="social-'.$provider.'"', false)
                ->assertSee('action="'.route('admin.settings.social.check', $provider).'"', false);
        }

        $manager = User::factory()->create();
        $this->onZone('admin')->actingAs($manager)->post('/admin/settings/social', $this->form())->assertForbidden();
        $this->onZone('admin')->actingAs($manager)->post('/admin/settings/social/google/check')->assertForbidden();
    }

    public function test_status_shows_ready_switch_off_and_broken_secret(): void
    {
        $this->configureAll();
        $admin = $this->admin();

        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertSee(__('portal.settings.social.secret_stored'))
            ->assertSee(__('portal.settings.social.ready'))
            ->assertSee(__('portal.settings.social.switch_off'))
            ->assertDontSee(self::GOOGLE_SECRET)
            ->assertDontSee('PRIVATE KEY');

        LoginMethods::save([LoginMethods::SOCIAL => true]);
        Setting::put(SocialSettings::secretSetting('github'), 'not-a-ciphertext');
        $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()
            ->assertDontSee(__('portal.settings.social.switch_off'))
            ->assertSee(__('portal.settings.social.secret_broken'));
    }

    public function test_providers_are_saved_with_secret_keep_replace_and_remove(): void
    {
        $admin = $this->admin();

        $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/social', $this->form([
            'google' => ['enabled' => '1', 'client_id' => ' google-client.apps.example.com ', 'secret' => self::GOOGLE_SECRET],
            'github' => ['enabled' => '0', 'client_id' => 'gh-client-id', 'secret' => self::GITHUB_SECRET],
        ], 12.5))->assertRedirect('/admin/settings')->assertSessionHasNoErrors()->assertSessionHas('status', __('portal.saved'));

        $this->assertSame(45000, SocialSettings::durationS());
        $this->assertSame(['enabled' => true, 'client_id' => 'google-client.apps.example.com'], SocialSettings::provider('google'));
        $this->assertSame(self::GOOGLE_SECRET, SocialSettings::secret('google'));
        $this->assertSame(self::GITHUB_SECRET, SocialSettings::secret('github'));
        $this->assertNull(SocialSettings::secret('facebook'));
        $this->assertTrue(SocialSettings::isReady('google'));
        $this->assertFalse(SocialSettings::isReady('github'));
        // Stored encrypted with the data key.
        $raw = (string) DB::table('settings')->where('key', SocialSettings::secretSetting('google'))->value('value');
        $this->assertStringNotContainsString(self::GOOGLE_SECRET, $raw);
        $this->assertSame(app(DataKey::class)->currentId(), DataKey::keyIdOf(Setting::get(SocialSettings::secretSetting('google'))));

        // Empty secret keeps, a new one replaces, "remove" clears.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form([
            'google' => ['enabled' => '1', 'client_id' => 'google-client.apps.example.com', 'secret' => ''],
            'github' => ['enabled' => '1', 'client_id' => 'gh-client-id', 'secret' => 'new-github-secret'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(self::GOOGLE_SECRET, SocialSettings::secret('google'));
        $this->assertSame('new-github-secret', SocialSettings::secret('github'));

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form([
            'google' => ['enabled' => '1', 'client_id' => 'google-client.apps.example.com', 'secret_clear' => '1'],
        ]))->assertSessionHasNoErrors();
        $this->assertNull(SocialSettings::secret('google'));
        $this->assertFalse(SocialSettings::isReady('google'));
        $this->assertSame('new-github-secret', SocialSettings::secret('github'));

        $audits = AuditLog::where('action', 'settings.social')->orderBy('id')->get();
        $this->assertCount(3, $audits);
        $this->assertSame(['duration_s' => 45000, 'providers' => [
            'google' => ['enabled' => true, 'credential_changed' => true],
            'apple' => ['enabled' => false, 'credential_changed' => false],
            'facebook' => ['enabled' => false, 'credential_changed' => false],
            'github' => ['enabled' => false, 'credential_changed' => true],
        ], 'order' => SocialSettings::PROVIDERS], $audits[0]->data);
        $this->assertFalse($audits[1]->data['providers']['google']['credential_changed']);
        $this->assertTrue($audits[2]->data['providers']['google']['credential_changed']);
        $this->assertNoSecretsIn(json_encode($audits->pluck('data')).implode("\n", $this->logs));
        $this->assertStringNotContainsString('new-github-secret', json_encode($audits->pluck('data')));
    }

    public function test_saving_hints_at_the_walled_garden_when_the_hosts_change(): void
    {
        LoginMethods::save(['code' => true, 'social' => true]);
        $payload = $this->form(['google' => ['enabled' => '1', 'client_id' => 'google-client', 'secret' => self::GOOGLE_SECRET]]);

        $this->onZone('admin')->actingAs($this->admin())->post('/admin/settings/social', $payload)
            ->assertRedirect()->assertSessionHas('warning')->assertSessionHas('warning_link');
        // The same configuration again: the hosts are unchanged, no hint.
        $payload['providers']['google']['secret'] = '';
        $this->onZone('admin')->actingAs($this->admin())->post('/admin/settings/social', $payload)
            ->assertRedirect()->assertSessionMissing('warning');
        // Switching social login off removes the hosts: hint again.
        $this->onZone('admin')->actingAs($this->admin())->post('/admin/settings/login-methods', ['methods' => ['code' => '1']])
            ->assertRedirect()->assertSessionHas('warning');
        $this->onZone('admin')->actingAs($this->admin())->get('/admin/settings')->assertSee(__('portal.settings.social.walled_garden_changed'));
    }

    public function test_duration_is_validated(): void
    {
        $admin = $this->admin();
        foreach ([0, 9000] as $hours) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form([], $hours))
                ->assertSessionHasErrors('social_duration_hours');
        }
        $this->assertSame(SocialSettings::DEFAULTS['duration_s'], SocialSettings::durationS());
    }

    public function test_apple_private_key_and_ids_are_validated(): void
    {
        $admin = $this->admin();
        $apple = ['enabled' => '1', 'client_id' => 'com.example.portal', 'team_id' => 'team123456', 'key_id' => 'KEY1234567'];

        // Not a key, and an EC key on the wrong curve: rejected, the pasted text is not flashed back.
        foreach (['-----BEGIN PRIVATE KEY-----'."\nnot-a-key\n".'-----END PRIVATE KEY-----', self::ecKey('secp384r1')] as $pem) {
            $this->onZone('admin')->actingAs($admin)->from('/admin/settings')->post('/admin/settings/social', $this->form(['apple' => $apple + ['secret' => $pem]]))
                ->assertRedirect('/admin/settings')
                ->assertSessionHasErrors(['providers.apple.secret' => __('portal.settings.social.apple_key_invalid')]);
            $this->assertNull(data_get(session('_old_input'), 'providers.apple.secret'));
            $this->assertSame('com.example.portal', data_get(session('_old_input'), 'providers.apple.client_id'));
            $this->assertNull(SocialSettings::secret('apple'));
        }

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form(['apple' => ['team_id' => 'SHORT', 'key_id' => 'KEY-123456']]))
            ->assertSessionHasErrors(['providers.apple.team_id', 'providers.apple.key_id']);

        // A P-256 key with Windows line endings is accepted; IDs are stored upper case.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form(['apple' => $apple + ['secret' => str_replace("\n", "\r\n", self::appleKey())]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(trim(self::appleKey()), SocialSettings::secret('apple'));
        $this->assertSame('TEAM123456', SocialSettings::provider('apple')['team_id']);
        $this->assertTrue(SocialSettings::isReady('apple'));
        $this->assertNoSecretsIn(json_encode(AuditLog::pluck('data')).implode("\n", $this->logs));
    }

    public function test_apple_client_secret_is_an_es256_jwt(): void
    {
        $jwt = AppleClientSecret::make('com.example.portal', 'TEAM123456', 'KEY1234567', self::appleKey(), 300);
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::appleKey()))['key'];
        $claims = (array) JWT::decode($jwt, new Key($public, 'ES256'));
        $header = json_decode(base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/')), true);

        $this->assertSame('KEY1234567', $header['kid']);
        $this->assertSame('ES256', $header['alg']);
        $this->assertSame('TEAM123456', $claims['iss']);
        $this->assertSame('com.example.portal', $claims['sub']);
        $this->assertSame(AppleClientSecret::AUDIENCE, $claims['aud']);
        $this->assertSame(300, $claims['exp'] - $claims['iat']);

        $this->expectException(\InvalidArgumentException::class);
        AppleClientSecret::make('com.example.portal', 'TEAM123456', 'KEY1234567', self::ecKey('secp384r1'));
    }

    public function test_google_check_outcomes(): void
    {
        $this->configureAll();
        $admin = $this->admin();
        $answers = [
            ['invalid_grant', 400, ProviderCheck::OK],
            ['redirect_uri_mismatch', 400, ProviderCheck::OK],
            ['invalid_client', 401, ProviderCheck::REJECTED],
            ['server_error', 503, ProviderCheck::UNREACHABLE],
        ];
        $sequence = Http::sequence();
        foreach ($answers as [$error, $status]) {
            $sequence->push(['error' => $error], $status);
        }
        Http::fake(['oauth2.googleapis.com/*' => $sequence]);
        foreach ($answers as [, , $expected]) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/google/check')
                ->assertRedirect(route('admin.settings').'#social-google')
                ->assertSessionHas('social_check.status', $expected)
                ->assertSessionHas('social_check.provider', 'google');
        }
        $this->assertSame(__('portal.settings.social.check.unexpected', ['status' => 503]), session('social_check.message'));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === ProviderCheck::URLS['google'] && $r->method() === 'POST'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === ProviderCheck::CODE && $r['client_id'] === 'google-client.apps.example.com'
            && $r['client_secret'] === self::GOOGLE_SECRET && $r['redirect_uri'] === 'https://portal.example.com/portal/social/google/callback');
        $this->assertSame(['ok', 'ok', 'rejected', 'unreachable'], AuditLog::where('action', 'settings.social_checked')->orderBy('id')->get()
            ->map(fn ($a) => $a->data['status'])->all());
        $this->assertSame(['provider' => 'google', 'status' => 'ok'], AuditLog::where('action', 'settings.social_checked')->first()->data);
        $this->assertNoSecretsIn(json_encode(AuditLog::pluck('data')).implode("\n", $this->logs).json_encode(session('social_check')));

        // The result is shown in the provider card.
        $this->onZone('admin')->actingAs($admin)->withSession(['social_check' => ['provider' => 'google', 'status' => 'rejected',
            'message' => __('portal.settings.social.check.rejected')]])->get('/admin/settings')
            ->assertSee('data-social-check="rejected"', false)->assertSee(__('portal.settings.social.check.rejected'));
    }

    public function test_github_and_facebook_check_outcomes(): void
    {
        $this->configureAll();
        $admin = $this->admin();
        Http::fake([
            'github.com/*' => Http::sequence()
                ->push(['error' => 'bad_verification_code'])
                ->push(['error' => 'incorrect_client_credentials']),
            'graph.facebook.com/*' => Http::sequence()
                ->push(['access_token' => '123|app-token', 'token_type' => 'bearer'])
                ->push(['error' => ['message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1]], 400)
                ->push(['error' => ['message' => 'Invalid client_id', 'code' => 101]], 400)
                ->push('', 302, ['Location' => 'https://elsewhere.example.net/']),
        ]);
        $cases = [
            ['github', ProviderCheck::OK], ['github', ProviderCheck::REJECTED],
            ['facebook', ProviderCheck::OK], ['facebook', ProviderCheck::REJECTED], ['facebook', ProviderCheck::REJECTED], ['facebook', ProviderCheck::UNREACHABLE],
        ];
        foreach ($cases as [$provider, $expected]) {
            $this->onZone('admin')->actingAs($admin)->post("/admin/settings/social/$provider/check")
                ->assertSessionHas('social_check.status', $expected);
        }

        Http::assertSent(fn (HttpRequest $r) => $r->url() === ProviderCheck::URLS['github'] && $r->hasHeader('Accept', 'application/json')
            && $r['client_id'] === 'gh-client-id' && $r['client_secret'] === self::GITHUB_SECRET && $r['code'] === ProviderCheck::CODE);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET' && str_starts_with($r->url(), ProviderCheck::URLS['facebook'].'?')
            && $r['client_id'] === '1234567890' && $r['client_secret'] === self::FACEBOOK_SECRET && $r['grant_type'] === 'client_credentials');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'elsewhere.example.net'));
        $this->assertNoSecretsIn(implode("\n", $this->logs).json_encode(AuditLog::pluck('data')));
    }

    public function test_apple_check_signs_a_client_secret(): void
    {
        $this->configureAll();
        $admin = $this->admin();

        Http::fake(['appleid.apple.com/*' => Http::sequence()->push(['error' => 'invalid_grant'], 400)->push(['error' => 'invalid_client'], 400)]);
        foreach ([ProviderCheck::OK, ProviderCheck::REJECTED] as $expected) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/apple/check')->assertSessionHas('social_check.status', $expected);
        }

        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::appleKey()))['key'];
        Http::assertSent(function (HttpRequest $r) use ($public) {
            if ($r->url() !== ProviderCheck::URLS['apple'] || $r['code'] !== ProviderCheck::CODE || $r['client_id'] !== 'com.example.portal') {
                return false;
            }
            $claims = JWT::decode($r['client_secret'], new Key($public, 'ES256'));

            return $claims->iss === 'TEAM123456' && $claims->sub === 'com.example.portal';
        });

        // A stored key that cannot sign (e.g. pasted before validation existed) reports "private key invalid" without a request.
        Http::fake();
        SocialSettings::save([], ['apple' => 'not-a-key']);
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/apple/check')
            ->assertSessionHas('social_check.status', ProviderCheck::INCOMPLETE)
            ->assertSessionHas('social_check.message', __('portal.settings.social.check.key_invalid'));
        Http::assertNothingSent();
        $this->assertNoSecretsIn(implode("\n", $this->logs));
    }

    public function test_incomplete_and_unreachable_checks(): void
    {
        $admin = $this->admin();
        Http::fake();

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/google/check')
            ->assertSessionHas('social_check', ['provider' => 'google', 'status' => ProviderCheck::INCOMPLETE,
                'message' => __('portal.settings.social.check.missing_client_id')]);
        SocialSettings::save(['providers' => ['google' => ['client_id' => 'google-client']]]);
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/google/check')
            ->assertSessionHas('social_check.message', __('portal.settings.social.check.missing_secret'));
        Setting::put(SocialSettings::secretSetting('google'), 'not-a-ciphertext');
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/google/check')
            ->assertSessionHas('social_check.message', __('portal.settings.social.check.secret_broken'));
        SocialSettings::save(['providers' => ['apple' => ['client_id' => 'com.example.portal']]], ['apple' => self::appleKey()]);
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/apple/check')
            ->assertSessionHas('social_check.message', __('portal.settings.social.check.missing_apple_ids'));
        Http::assertNothingSent();

        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/twitter/check')->assertNotFound();

        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        SocialSettings::save([], ['google' => self::GOOGLE_SECRET]);
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social/google/check')
            ->assertSessionHas('social_check', ['provider' => 'google', 'status' => ProviderCheck::UNREACHABLE,
                'message' => __('portal.settings.social.check.unreachable')]);
        $this->assertSame(['provider' => 'google', 'status' => 'unreachable'], AuditLog::where('action', 'settings.social_checked')->latest('id')->first()->data);
    }

    public function test_secrets_are_reencrypted_after_a_data_key_rotation(): void
    {
        $this->configureAll();
        $old = app(DataKey::class)->currentId();

        $new = app(FederatedSecrets::class)->rotate();

        $this->assertNotSame($old, $new);
        foreach (SocialSettings::PROVIDERS as $provider) {
            $this->assertSame($new, DataKey::keyIdOf(Setting::get(SocialSettings::secretSetting($provider))));
        }
        $this->assertSame(self::GOOGLE_SECRET, SocialSettings::secret('google'));
        $this->assertSame(self::appleKey(), SocialSettings::secret('apple'));
        $this->assertSame(0, SocialSettings::reencryptSecrets());
    }

    // --- Display order (v0.25.0, decision 2026-11-04) -----------------------------------------------

    public function test_provider_order_is_saved_moved_and_shown(): void
    {
        $admin = $this->admin();
        $rows = function () use ($admin): array {
            $html = $this->onZone('admin')->actingAs($admin)->get('/admin/settings')->assertOk()->getContent();
            preg_match_all('/data-social-provider="([a-z]+)"/', $html, $m);

            return $m[1];
        };
        $this->assertSame(SocialSettings::PROVIDERS, SocialSettings::order());
        $this->assertSame(SocialSettings::PROVIDERS, $rows());

        $order = ['github', 'facebook', 'google', 'apple'];
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form() + ['order' => $order])
            ->assertSessionHasNoErrors();
        $this->assertSame($order, SocialSettings::order());
        $this->assertSame($order, $rows());
        $this->assertSame($order, AuditLog::where('action', 'settings.social')->sole()->data['order']);

        // Arrow button without JS; a save without order fields (and SocialSettings::save() without order) keeps it.
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form() + ['move' => 'google:up'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['github', 'google', 'facebook', 'apple'], SocialSettings::order());
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form())->assertSessionHasNoErrors();
        SocialSettings::save(['duration_s' => 3600]);
        $this->assertSame(['github', 'google', 'facebook', 'apple'], SocialSettings::order());
    }

    public function test_invalid_provider_order_is_rejected(): void
    {
        $admin = $this->admin();
        foreach ([['google', 'apple', 'facebook'], ['google', 'google', 'facebook', 'github'], ['google', 'apple', 'facebook', 'code'], 'google'] as $order) {
            $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form() + ['order' => $order])
                ->assertSessionHasErrors(['order' => __('portal.settings.order.invalid')]);
        }
        $this->onZone('admin')->actingAs($admin)->post('/admin/settings/social', $this->form() + ['move' => 'code:up'])
            ->assertSessionHasErrors('move');
        $this->assertSame(0, AuditLog::where('action', 'settings.social')->count());
        $this->assertSame(SocialSettings::PROVIDERS, SocialSettings::order());

        // Stored values are read leniently (older versions, replicated values).
        Setting::put(SocialSettings::KEY, ['order' => ['apple', 'nope', 'apple']]);
        $this->assertSame(['apple', 'google', 'facebook', 'github'], SocialSettings::order());
        Setting::put(SocialSettings::KEY, ['order' => 'apple']);
        $this->assertSame(SocialSettings::PROVIDERS, SocialSettings::order());
    }

    public function test_provider_order_sorts_the_offered_providers_but_not_the_walled_garden(): void
    {
        LoginMethods::save(['code' => true, 'social' => true]);
        $this->configureAll();
        $hosts = WalledGarden::hosts();
        $this->assertSame(SocialSettings::PROVIDERS, SocialSettings::enabledProviders());

        SocialSettings::save(['order' => ['facebook', 'github', 'apple', 'google']]);
        $this->assertSame(['facebook', 'github', 'apple', 'google'], SocialSettings::enabledProviders());
        $this->assertSame($hosts, WalledGarden::hosts());
        // Reordering does not ask for the router script.
        $this->onZone('admin')->actingAs($this->admin())->post('/admin/settings/social', $this->form([
            'google' => ['enabled' => '1', 'client_id' => 'google-client.apps.example.com'],
            'apple' => ['enabled' => '1', 'client_id' => 'com.example.portal', 'team_id' => 'TEAM123456', 'key_id' => 'KEY1234567'],
            'facebook' => ['enabled' => '1', 'client_id' => '1234567890'],
            'github' => ['enabled' => '1', 'client_id' => 'gh-client-id'],
        ]) + ['move' => 'google:up'])->assertSessionHasNoErrors()->assertSessionMissing('warning');
        $this->assertSame(['facebook', 'github', 'google', 'apple'], SocialSettings::enabledProviders());
    }
}
