<?php

namespace App\Http\Controllers\Admin;

use App\Approval\ApprovalSettings;
use App\Auth\Directory\DirectorySettings;
use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcSettings;
use App\Auth\Social\AppleClientSecret;
use App\Auth\Social\LoginMethods;
use App\Auth\Social\ProviderCheck;
use App\Auth\Social\SecretExpiry;
use App\Auth\Social\SocialSettings;
use App\Auth\Social\WalledGarden;
use App\Auth\TwoFactor;
use App\Branding\BrandingSettings;
use App\Federation\Topology;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Instance;
use App\Models\Setting;
use App\Sms\Drivers\SmsDrivers;
use App\Sms\OtpService;
use App\Sms\PhoneNumber;
use App\Sms\SmsProviders;
use App\Sms\SmsSettings;
use App\Support\Audit;
use App\Support\DisplayOrder;
use App\Support\LocalInstance;
use App\Support\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Settings (admin only): notification and SMS settings, channels and SMS providers (CONCEPT.md §9).
 * Appearance (branding) is saved by Admin\BrandingController.
 */
class SettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings', [
            'channels' => Channel::query()->where('kind', Channel::KIND_NOTIFICATION)->orderBy('name')->get(),
            'smsChannels' => $smsChannels = SmsProviders::ordered(),
            'smsBadges' => SmsProviders::badges($smsChannels),
            'smsDown' => $smsChannels->mapWithKeys(fn (Channel $c) => [$c->id => SmsProviders::downSince($c)])->filter()->all(),
            'smsOutage' => SmsProviders::outage(),
            'smsCooldownS' => SmsSettings::failoverCooldownS(),
            'smsDurationHours' => round(SmsSettings::durationS() / 3600, 2),
            'smsCountries' => implode(', ', SmsSettings::countries()),
            'smsCaps' => SmsSettings::caps(),
            'smsTexts' => collect(config('portal.locales'))->mapWithKeys(fn ($l) => [$l => SmsSettings::text($l)])->all(),
            'adminUrl' => Setting::get('admin_url'),
            'adminUrlMissing' => ApprovalSettings::adminUrlMissing(),
            'adminUrlFallback' => ApprovalSettings::fallbackAdminUrl(),
            'locale' => ApprovalSettings::locale(),
            'telegramOwner' => ApprovalSettings::telegramOwnerId() ?? ApprovalSettings::TELEGRAM_OWNER_NONE,
            'instances' => Instance::query()->orderBy('name')->get(),
            'hubId' => app(Topology::class)->hubId() ?? app(LocalInstance::class)->id(),
            'require2fa' => TwoFactor::required(),
            'oidc' => OidcSettings::all(),
            'oidcHasSecret' => OidcSettings::hasClientSecret(),
            'oidcSecretBroken' => OidcSettings::clientSecretBroken(),
            'oidcRedirectUri' => OidcSettings::redirectUri(),
            'branding' => BrandingSettings::global(),
            'loginMethods' => LoginMethods::all(),
            'loginMethodOrder' => LoginMethods::order(),
            'loginMethodHints' => $this->loginMethodHints(),
            'socialDurationHours' => round(SocialSettings::durationS() / 3600, 2),
            'accountDurationHours' => round(DirectorySettings::durationS() / 3600, 2),
            'social' => $this->socialStatus(),
            'socialOrigins' => SocialSettings::origins(),
            'socialSecretAttention' => SecretExpiry::attention(),
        ]);
    }

    /**
     * Why a switched-on login method is not offered to guests (prerequisite missing), per method.
     *
     * @return array<string, string> method => lang key
     */
    private function loginMethodHints(): array
    {
        $flags = LoginMethods::all();
        $available = LoginMethods::available();
        $limited = app(Topology::class)->limitedMode();
        $hints = [];
        foreach (LoginMethods::ALL as $method) {
            if (! $flags[$method] || $available[$method]) {
                continue;
            }
            $hints[$method] = match (true) {
                $method === LoginMethods::SMS && ! OtpService::enabled() => 'portal.settings.login_methods.hint_sms',
                $limited => 'portal.settings.login_methods.hint_limited',
                $method === LoginMethods::APPROVAL => 'portal.settings.login_methods.hint_approval',
                $method === LoginMethods::ACCOUNT => 'portal.settings.login_methods.hint_account',
                default => 'portal.settings.login_methods.hint_social',
            };
        }

        return $hints;
    }

    /** @return array<string, array<string, mixed>> provider => settings, secret state, readiness, missing items, redirect URIs */
    private function socialStatus(): array
    {
        $out = [];
        $providers = SocialSettings::all()['providers'];
        foreach (SocialSettings::order() as $provider) {
            $config = $providers[$provider];
            // secretBroken() first: secret() logs a warning for an unreadable secret on every call.
            $broken = SocialSettings::secretBroken($provider);
            $hasSecret = ! $broken && SocialSettings::hasSecret($provider);
            $missing = [];
            if (! $config['enabled']) {
                $missing[] = 'enabled';
            }
            if ($config['client_id'] === '') {
                $missing[] = 'client_id';
            }
            if (! $hasSecret) {
                $missing[] = 'secret';
            }
            if ($provider === SocialSettings::APPLE && ($config['team_id'] === '' || $config['key_id'] === '')) {
                $missing[] = 'apple_ids';
            }
            if ($provider === SocialSettings::MICROSOFT && ! SocialSettings::validTenant($config['tenant'])) {
                $missing[] = 'tenant';
            }
            $out[$provider] = $config + [
                'expiry' => SecretExpiry::status($provider),
                'has_secret' => $hasSecret,
                'secret_broken' => $broken,
                'ready' => $missing === [],
                'missing' => $missing,
                'redirect_uris' => SocialSettings::redirectUris($provider),
            ];
        }

        return $out;
    }

    /**
     * Validation rules of the display order fields (decision 2026-11-04): "order[]" lists every key once in
     * the new order (optional, absent keeps the stored order); "move" is an arrow button pressed without
     * JS ("<key>:up" / "<key>:down"), applied to that order on save.
     *
     * @param  list<string>  $keys
     * @return array<string, list<mixed>>
     */
    private static function orderRules(array $keys): array
    {
        return [
            'order' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) use ($keys) {
                if (! DisplayOrder::isPermutation($value, $keys)) {
                    $fail(__('portal.settings.order.invalid'));
                }
            }],
            'move' => ['nullable', 'string', Rule::in(DisplayOrder::moveValues($keys))],
        ];
    }

    /**
     * New display order from the validated "order[]" and "move" fields; null when neither was sent.
     *
     * @param  list<string>  $current
     * @return list<string>|null
     */
    private static function orderFrom(Request $request, array $current): ?array
    {
        $order = $request->has('order') && $request->input('order') !== null ? array_values($request->input('order')) : null;
        $move = $request->input('move');
        if (is_string($move) && $move !== '') {
            [$key, $direction] = explode(':', $move, 2);
            $order = DisplayOrder::move($order ?? $current, $key, $direction);
        }

        return $order;
    }

    /**
     * Guest login methods (CONCEPT.md §9, decision 2026-11-03): one switch per method, at least one stays on;
     * display order (decision 2026-11-04); default access duration of the account login (v0.24.0; a site
     * may set its own).
     */
    public function updateLoginMethods(Request $request): RedirectResponse
    {
        $request->validate(collect(LoginMethods::ALL)->mapWithKeys(fn ($m) => ["methods.$m" => ['boolean']])->all()
            + ['account_duration_hours' => ['nullable', 'numeric', 'between:'.round(DirectorySettings::MIN_DURATION_S / 3600, 4).','.(DirectorySettings::MAX_DURATION_S / 3600)]]
            + self::orderRules(LoginMethods::ALL),
            [], ['account_duration_hours' => __('portal.settings.login_methods.account_duration')]);
        $flags = collect(LoginMethods::ALL)->mapWithKeys(fn ($m) => [$m => $request->boolean("methods.$m")])->all();
        if (! in_array(true, $flags, true)) {
            throw ValidationException::withMessages(['methods' => __('portal.settings.login_methods.at_least_one')]);
        }
        $hostsBefore = WalledGarden::hosts();
        LoginMethods::save($flags, self::orderFrom($request, LoginMethods::order()));
        if ($request->filled('account_duration_hours')) {
            DirectorySettings::saveDurationS((int) round((float) $request->input('account_duration_hours') * 3600));
        }
        // Lists, not flags by method name: Audit strips a key named "code".
        Audit::log('settings.login_methods', null, ['enabled' => array_keys(array_filter($flags)),
            'disabled' => array_keys(array_diff_key($flags, array_filter($flags))), 'account_duration_s' => DirectorySettings::durationS(),
            'order' => LoginMethods::order()]);

        return $this->withWalledGardenHint(back()->with('status', __('portal.saved')), $hostsBefore);
    }

    /**
     * Social login providers (CONCEPT.md §9, decision 2026-11-03). An empty secret field keeps the stored
     * secret, "remove" deletes it; secrets are never logged, audited or flashed back as old input. Display
     * order of the providers: decision 2026-11-04. Microsoft tenant and the optional expiry date of a secret
     * (all providers but Apple): decision 2026-11-05, v0.31.0; removing a secret also removes its date.
     */
    public function updateSocial(Request $request): RedirectResponse
    {
        $secretFields = array_map(fn ($p) => "providers.$p.secret", SocialSettings::PROVIDERS);
        $rules = ['social_duration_hours' => ['required', 'numeric', 'between:0.25,8760']];
        foreach (SocialSettings::PROVIDERS as $provider) {
            $rules += [
                "providers.$provider.enabled" => ['boolean'],
                "providers.$provider.client_id" => ['nullable', 'string', 'max:255'],
                "providers.$provider.secret" => ['nullable', 'string', $provider === SocialSettings::APPLE ? 'max:10000' : 'max:500'],
                "providers.$provider.secret_clear" => ['boolean'],
            ];
            if (in_array($provider, SocialSettings::EXPIRING, true)) {
                $rules["providers.$provider.secret_expires_at"] = ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01',
                    'before_or_equal:'.now()->addYears(30)->format('Y-m-d')];
            }
        }
        $rules += [
            'providers.apple.team_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10}$/'],
            'providers.apple.key_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{10}$/'],
            'providers.microsoft.tenant' => ['nullable', 'string', 'max:253', function (string $attribute, mixed $value, \Closure $fail) {
                if (! SocialSettings::validTenant(trim((string) $value))) {
                    $fail(__('portal.settings.social.tenant_invalid'));
                }
            }],
        ] + self::orderRules(SocialSettings::PROVIDERS);
        $validator = Validator::make($request->all(), $rules, [
            'providers.apple.team_id.regex' => __('portal.settings.social.apple_id_invalid'),
            'providers.apple.key_id.regex' => __('portal.settings.social.apple_id_invalid'),
            ...collect(SocialSettings::EXPIRING)->flatMap(fn ($p) => collect(['date_format', 'after_or_equal', 'before_or_equal'])
                ->mapWithKeys(fn ($r) => ["providers.$p.secret_expires_at.$r" => __('portal.social_secret.invalid')]))->all(),
        ], [
            'social_duration_hours' => __('portal.settings.social.duration'),
            ...collect(SocialSettings::PROVIDERS)->mapWithKeys(fn ($p) => ["providers.$p.client_id" => __('portal.settings.social.providers.'.$p).': '
                .__($p === SocialSettings::APPLE ? 'portal.settings.social.services_id' : 'portal.settings.social.client_id')])->all(),
        ]);
        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput($request->except($secretFields));
        }
        $data = $validator->validated();

        $config = ['duration_s' => (int) round($data['social_duration_hours'] * 3600), 'providers' => []];
        if (($order = self::orderFrom($request, SocialSettings::order())) !== null) {
            $config['order'] = $order;
        }
        $secrets = [];
        foreach (SocialSettings::PROVIDERS as $provider) {
            $input = $data['providers'][$provider] ?? [];
            $config['providers'][$provider] = [
                'enabled' => $request->boolean("providers.$provider.enabled"),
                'client_id' => trim((string) ($input['client_id'] ?? '')),
            ];
            if ($provider === SocialSettings::APPLE) {
                $config['providers'][$provider]['team_id'] = strtoupper(trim((string) ($input['team_id'] ?? '')));
                $config['providers'][$provider]['key_id'] = strtoupper(trim((string) ($input['key_id'] ?? '')));
            }
            if ($provider === SocialSettings::MICROSOFT) {
                $tenant = trim((string) ($input['tenant'] ?? ''));
                $config['providers'][$provider]['tenant'] = $tenant === '' ? 'common'
                    : (in_array(strtolower($tenant), SocialSettings::MICROSOFT_TENANTS, true) ? strtolower($tenant) : $tenant);
            }
            if (in_array($provider, SocialSettings::EXPIRING, true)) {
                $date = trim((string) ($input['secret_expires_at'] ?? ''));
                $config['providers'][$provider]['secret_expires_at'] = $date === '' ? null : $date;
            }
            $secret = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['secret'] ?? '')));
            $secrets[$provider] = $request->boolean("providers.$provider.secret_clear") ? '' : ($secret !== '' ? $secret : null);
        }
        if (is_string($secrets[SocialSettings::APPLE]) && $secrets[SocialSettings::APPLE] !== ''
            && ! $this->isAppleKey($secrets[SocialSettings::APPLE])) {
            return back()->withErrors(['providers.apple.secret' => __('portal.settings.social.apple_key_invalid')])
                ->withInput($request->except($secretFields));
        }

        $hostsBefore = WalledGarden::hosts();
        SocialSettings::save($config, $secrets);
        // Audit keys must not look like secrets (Audit strips them), hence "credential_changed"; no values.
        Audit::log('settings.social', null, [
            'duration_s' => $config['duration_s'],
            'providers' => collect(SocialSettings::PROVIDERS)->mapWithKeys(fn ($p) => [$p => [
                'enabled' => $config['providers'][$p]['enabled'],
                'credential_changed' => $secrets[$p] !== null,
            ] + (in_array($p, SocialSettings::EXPIRING, true) ? ['valid_until' => SocialSettings::secretExpiresAt($p)] : [])])->all(),
            'order' => SocialSettings::order(),
        ]);

        return $this->withWalledGardenHint(back()->with('status', __('portal.saved')), $hostsBefore);
    }

    /**
     * The walled-garden hosts the routers must allow follow the enabled providers (WalledGarden::hosts()):
     * when a save changes them, the admin is told to apply the router script of the active sites again.
     *
     * @param  list<string>  $hostsBefore
     */
    private function withWalledGardenHint(RedirectResponse $response, array $hostsBefore): RedirectResponse
    {
        if (WalledGarden::hosts() === $hostsBefore) {
            return $response;
        }

        return $response->with('warning', __('portal.settings.social.walled_garden_changed'))
            ->with('warning_link', [route('admin.sites.index'), __('portal.sites.title')]);
    }

    /** The pasted .p8 content must be an EC P-256 private key that can sign the Apple client secret. */
    private function isAppleKey(#[\SensitiveParameter] string $pem): bool
    {
        try {
            AppleClientSecret::make('check', 'ABCDEFGHIJ', 'ABCDEFGHIJ', $pem);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Check button of a provider (ProviderCheck): the result is flashed next to the provider. */
    public function checkSocial(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, SocialSettings::PROVIDERS, true), 404);
        $result = ProviderCheck::run($provider);
        Audit::log('settings.social_checked', null, ['provider' => $provider, 'status' => $result['status']]);

        return redirect()->to(route('admin.settings').'#social-'.$provider)->with('social_check', [
            'provider' => $provider,
            'status' => $result['status'],
            'message' => __($result['message'], ['status' => $result['http'] ?? '–']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'admin_url' => ['nullable', 'url:https,http', 'max:255'],
            'notification_locale' => ['required', Rule::in(config('portal.locales'))],
            // Instance that polls Telegram (CONCEPT.md §10.5) or "none".
            'telegram_owner' => ['required', 'string', Rule::in([ApprovalSettings::TELEGRAM_OWNER_NONE,
                ...Instance::query()->pluck('id')->all()])],
        ]);
        Setting::put('admin_url', isset($data['admin_url']) ? rtrim($data['admin_url'], '/') : null);
        Setting::put('notification_locale', $data['notification_locale']);
        Setting::put('telegram_owner', $data['telegram_owner']);
        Audit::log('settings.updated', null, ['keys' => ['admin_url', 'notification_locale', 'telegram_owner']]);

        return back()->with('status', __('portal.saved'));
    }

    /** Security: require TOTP for all admin UI users (CONCEPT.md §9). */
    public function updateSecurity(Request $request): RedirectResponse
    {
        $request->validate(['require_2fa' => ['boolean']]);
        Setting::put(TwoFactor::REQUIRED_SETTING, $request->boolean('require_2fa'));
        Audit::log('settings.security', null, ['require_2fa' => $request->boolean('require_2fa')]);

        return back()->with('status', __('portal.saved'));
    }

    /**
     * OIDC login for the admin UI (CONCEPT.md §9). An empty secret field keeps the stored secret; the
     * secret is never logged or audited.
     */
    public function updateOidc(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'oidc_enabled' => ['boolean'],
            'oidc_label' => ['required', 'string', 'max:50'],
            'oidc_issuer' => ['nullable', 'required_if_accepted:oidc_enabled', 'url:https', 'max:255'],
            'oidc_client_id' => ['nullable', 'required_if_accepted:oidc_enabled', 'string', 'max:255'],
            'oidc_client_secret' => ['nullable', 'string', 'max:500'],
            'oidc_client_secret_clear' => ['boolean'],
            'oidc_scopes' => ['required', 'string', 'max:255', 'regex:/(^|\s)openid(\s|$)/'],
            'oidc_groups_claim' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_:.\/-]+$/'],
            'oidc_admin_group' => ['required', 'string', 'max:255'],
            'oidc_manager_group' => ['required', 'string', 'max:255', 'different:oidc_admin_group'],
            'oidc_totp_mode' => ['required', Rule::in(OidcSettings::TOTP_MODES)],
            'oidc_acr' => ['nullable', 'string', 'max:255'],
            'oidc_max_auth_age' => ['nullable', 'integer', 'between:60,2592000'],
            'oidc_link_by_email' => ['boolean'],
        ]);
        $old = OidcSettings::all();
        $config = [
            'enabled' => $request->boolean('oidc_enabled'),
            'label' => trim($data['oidc_label']),
            'issuer' => trim((string) ($data['oidc_issuer'] ?? '')),
            'client_id' => trim((string) ($data['oidc_client_id'] ?? '')),
            'scopes' => trim((string) preg_replace('/\s+/', ' ', $data['oidc_scopes'])),
            'groups_claim' => $data['oidc_groups_claim'],
            'admin_group' => trim($data['oidc_admin_group']),
            'manager_group' => trim($data['oidc_manager_group']),
            'totp_mode' => $data['oidc_totp_mode'],
            'acr' => trim((string) ($data['oidc_acr'] ?? '')),
            'max_auth_age' => isset($data['oidc_max_auth_age']) ? (int) $data['oidc_max_auth_age'] : null,
            'link_by_email' => $request->boolean('oidc_link_by_email'),
        ];
        $secret = $request->boolean('oidc_client_secret_clear') ? '' : (trim((string) ($data['oidc_client_secret'] ?? '')) ?: null);
        OidcSettings::save($config, $secret);
        OidcClient::forgetCache($old['issuer']);
        OidcClient::forgetCache($config['issuer']);
        // Audit keys must not look like secrets (Audit strips them), hence "mfa_mode" / "credential_changed".
        Audit::log('settings.oidc', null, ['enabled' => $config['enabled'], 'issuer' => $config['issuer'],
            'scopes' => $config['scopes'], 'groups_claim' => $config['groups_claim'], 'admin_group' => $config['admin_group'],
            'manager_group' => $config['manager_group'], 'mfa_mode' => $config['totp_mode'], 'acr' => $config['acr'],
            'max_auth_age' => $config['max_auth_age'], 'link_by_email' => $config['link_by_email'],
            'credential_changed' => $secret !== null]);

        return back()->with('status', __('portal.saved'));
    }

    public function updateSms(Request $request): RedirectResponse
    {
        $caps = array_keys(config('portal.sms.caps'));
        $data = $request->validate([
            'sms_duration_hours' => ['required', 'numeric', 'between:0.25,8760'],
            'sms_countries' => ['required', 'string', 'max:500'],
            ...collect($caps)->mapWithKeys(fn ($k) => ["sms_caps.$k" => ['required', 'integer', 'between:0,100000']])->all(),
            'sms_failover_cooldown_s' => ['sometimes', 'required', 'integer', 'between:'.SmsSettings::COOLDOWN_MIN.','.SmsSettings::COOLDOWN_MAX],
            'sms_text' => ['sometimes', 'array'],
            ...collect(config('portal.locales'))->mapWithKeys(fn ($l) => ["sms_text.$l" => ['nullable', 'string', 'max:2000']])->all(),
        ]);
        $texts = [];
        foreach (config('portal.locales') as $locale) {
            $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($data['sms_text'][$locale] ?? '')));
            // The code must also survive without a valid host (OtpService drops every line with :host then)
            // and be preceded by the word "code" (IT "codice"), which iOS needs to detect it.
            $codeLine = collect(explode("\n", $text))->first(fn ($l) => ! str_contains($l, ':host') && str_contains($l, ':code')
                && preg_match('/\b(code|codice)\b/iu', str_replace([':code', ':minutes'], '', strstr($l, ':code', true))));
            if ($text !== '' && $codeLine === null) {
                throw ValidationException::withMessages(["sms_text.$locale" => __('portal.settings.sms_text_missing_code')]);
            }
            if (mb_strlen($text) > SmsSettings::TEXT_MAX) {
                throw ValidationException::withMessages(["sms_text.$locale" => __('validation.max.string', ['attribute' => strtoupper($locale), 'max' => SmsSettings::TEXT_MAX])]);
            }
            // Only texts that differ from the language default are stored, so default updates still apply.
            if ($text !== '' && $text !== SmsSettings::defaultText($locale)) {
                $texts[$locale] = $text;
            }
        }
        $countries = array_values(array_unique(array_filter(array_map(fn ($c) => strtoupper(trim($c)),
            preg_split('/[\s,;]+/', $data['sms_countries'])))));
        if ($countries === [] || collect($countries)->contains(fn ($c) => ! preg_match('/^[A-Z]{2}$/', $c))) {
            throw ValidationException::withMessages(['sms_countries' => __('portal.settings.sms_countries_invalid')]);
        }
        Setting::put('sms_duration_s', (int) round($data['sms_duration_hours'] * 3600));
        Setting::put('sms_countries', $countries);
        Setting::put('sms_caps', array_map('intval', $data['sms_caps']));
        if (isset($data['sms_failover_cooldown_s'])) {
            Setting::put('sms_failover_cooldown_s', (int) $data['sms_failover_cooldown_s']);
        }
        if ($request->has('sms_text')) {
            Setting::put('sms_text', $texts);
        }
        Audit::log('settings.sms', null, ['duration_s' => (int) round($data['sms_duration_hours'] * 3600),
            'countries' => $countries, 'caps' => array_map('intval', $data['sms_caps']),
            'own_text' => array_keys($texts), 'failover_cooldown_s' => SmsSettings::failoverCooldownS()]);

        return back()->with('status', __('portal.saved'));
    }

    /**
     * Test button: an SMS channel sends a test SMS (costs one SMS), a notification channel posts a
     * test message. Errors are shown as HTTP status only; the full error is logged redacted.
     */
    public function testChannel(Request $request, Channel $channel): RedirectResponse
    {
        if ($channel->kind === Channel::KIND_NOTIFICATION) {
            return $this->testNotificationChannel($request, $channel);
        }
        abort_unless($channel->kind === Channel::KIND_SMS, 404);
        $phone = $request->validate(['phone' => ['required', 'string', 'max:30']])['phone'];
        [$e164, $error] = PhoneNumber::normalise($phone, SmsSettings::countries());
        if ($e164 === null) {
            throw ValidationException::withMessages(['phone' => __('portal.guest.errors.phone_'.$error,
                ['countries' => implode(', ', SmsSettings::countries())])]);
        }
        try {
            $id = $channel->smsDriver()->send($channel, $e164, __('portal.sms.test'));
        } catch (Throwable $e) {
            $message = Redact::secrets($e->getMessage(), $channel->secretValues());
            Log::warning('Test SMS failed', ['channel' => $channel->id, 'phone' => Redact::phone($e164), 'error' => $message]);
            Audit::log('channel.tested', $channel, ['result' => 'failed']);

            return back()->withErrors(['phone' => __('portal.channels.test_failed', ['error' => $message])]);
        }
        Log::info('Test SMS sent', ['user' => $request->user()->id, 'channel' => $channel->id, 'phone' => Redact::phone($e164), 'provider_id' => $id]);
        Audit::log('channel.tested', $channel, ['result' => 'ok']);

        return back()->with('status', __('portal.channels.test_sent', ['id' => $id ?? '–']));
    }

    private function testNotificationChannel(Request $request, Channel $channel): RedirectResponse
    {
        try {
            $channel->driver()->test($channel, __('portal.channels.test_message', [], ApprovalSettings::locale()));
        } catch (Throwable $e) {
            $message = Redact::secrets($e->getMessage(), $channel->secretValues());
            Log::warning('Test notification failed', ['channel' => $channel->id, 'driver' => $channel->driver, 'error' => $message]);
            Audit::log('channel.tested', $channel, ['result' => 'failed']);
            $error = match (true) {
                $e instanceof RequestException => 'HTTP '.$e->response->status(),
                (bool) preg_match('/status code (\d{3})/', $message, $m) => 'HTTP '.$m[1],
                $e instanceof ConnectionException => __('portal.channels.test_connection'),
                default => __('portal.channels.test_error'),
            };

            return back()->withErrors(['test' => __('portal.channels.test_notify_failed', ['error' => $error])]);
        }
        Log::info('Test notification sent', ['user' => $request->user()->id, 'channel' => $channel->id]);
        Audit::log('channel.tested', $channel, ['result' => 'ok']);

        return back()->with('status', __('portal.channels.test_notify_sent'));
    }

    public function createChannel(Request $request): View
    {
        $driver = $request->query('driver');
        abort_unless(Channel::kindOf($driver) !== null, 404);

        return view('admin.channel', ['channel' => new Channel(['driver' => $driver, 'enabled' => true])]);
    }

    public function editChannel(Channel $channel): View
    {
        return view('admin.channel', ['channel' => $channel]);
    }

    public function storeChannel(Request $request): RedirectResponse
    {
        $driver = (string) $request->input('driver');
        abort_unless(Channel::kindOf($driver) !== null, 404);

        $channel = new Channel(['kind' => Channel::kindOf($driver), 'driver' => $driver]);
        $sms = $channel->kind === Channel::KIND_SMS;
        // SMS: store the current list first (keeps the active provider of a list from before 0.26.0),
        // then append the new provider as last backup (or active, if none is enabled).
        $sms && SmsProviders::normalise();
        $this->fill($request, $channel);
        $sms && SmsProviders::normalise();
        Audit::log('channel.created', $channel, ['driver' => $driver, 'name' => $channel->name]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function updateChannel(Request $request, Channel $channel): RedirectResponse
    {
        $this->fill($request, $channel);
        Audit::log('channel.updated', $channel, ['name' => $channel->name, 'enabled' => $channel->enabled]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    public function destroyChannel(Request $request, Channel $channel): RedirectResponse
    {
        $channel->delete();
        Audit::log('channel.deleted', $channel, ['name' => $channel->name]);

        return redirect()->route('admin.settings')->with('status', __('portal.deleted'));
    }

    /** SMS provider list: make this channel the active provider (enables it). */
    public function smsPrimary(Channel $channel): RedirectResponse
    {
        abort_unless($channel->kind === Channel::KIND_SMS && ! in_array($channel->driver, SmsDrivers::TEST_ONLY, true), 404);
        SmsProviders::makePrimary($channel);
        Audit::log('channel.sms_primary', $channel, ['name' => $channel->name]);

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    /** SMS provider list: move a backup one place up or down. */
    public function smsMove(Request $request, Channel $channel): RedirectResponse
    {
        abort_unless($channel->kind === Channel::KIND_SMS, 404);
        $direction = $request->validate(['direction' => ['required', 'in:up,down']])['direction'];
        if (SmsProviders::move($channel, $direction === 'up' ? -1 : 1)) {
            Audit::log('channel.sms_order', $channel, ['name' => $channel->name, 'direction' => $direction]);
        }

        return redirect()->route('admin.settings')->with('status', __('portal.saved'));
    }

    /** Validate and save; empty secret fields keep the stored value. */
    private function fill(Request $request, Channel $channel): void
    {
        $fields = Channel::fieldsOf($channel->driver);
        $old = $channel->exists ? ($channel->config ?? []) : [];
        $rules = ['name' => ['required', 'string', 'max:100'], 'enabled' => ['boolean']];
        foreach ($fields as $field => $spec) {
            $required = ($spec['required'] ?? false) && ! (($spec['secret'] ?? false) && ! empty($old[$field]));
            $rules["config.$field"] = [$required ? 'required' : 'nullable', 'string', 'max:500',
                ...(in_array($field, ['url', 'api_url'], true) ? ['url:https,http'] : []),
                ...(isset($spec['options']) ? [Rule::in($spec['options'])] : [])];
        }
        $data = $request->validate($rules);

        // Reserved keys (SMS role and order, SmsProviders) are not form fields and are kept.
        $config = SmsProviders::reservedOf($old);
        foreach ($fields as $field => $spec) {
            $value = trim((string) ($data['config'][$field] ?? ''));
            $config[$field] = ($spec['secret'] ?? false) && $value === '' ? ($old[$field] ?? null) : $value;
        }
        $channel->fill(['name' => $data['name'], 'enabled' => $request->boolean('enabled'), 'config' => $config])->save();
    }
}
