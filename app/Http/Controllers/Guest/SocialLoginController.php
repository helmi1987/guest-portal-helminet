<?php

namespace App\Http\Controllers\Guest;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\SocialIdentity;
use App\Auth\Social\SocialLogin;
use App\Auth\Social\SocialLoginException;
use App\Auth\Social\SocialSettings;
use App\Enums\GrantType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Guest\Concerns\GuestLogin;
use App\Models\Guest;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Nas\HotspotContext;
use App\Services\GrantManager;
use App\Services\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * Guest social login (CONCEPT.md §7.2 D, decision 2026-11-03): terms accepted on the social page, then
 * POST start (flow with state, nonce and PKCE verifier in the session, single use, 10 minutes) → GET go
 * (302 to the provider; never the direct answer of the form POST, guest CSP form-action) → callback on the
 * same host (Apple: form_post bounced to the GET callback) → optional name completion → guest, terms
 * acceptance, grant of type social and the router handoff like the other methods.
 */
class SocialLoginController extends Controller
{
    use GuestLogin;

    /** Session key of the running login attempt (start → callback). */
    public const FLOW = 'social_login';

    /** Session key of a verified identity whose name is being completed. */
    public const PENDING = 'social_pending';

    /** Lifetime of a login attempt and of a pending name completion. */
    public const TTL_MINUTES = 10;

    /** Max. age of an Apple form_post bounce (seconds). */
    private const BOUNCE_TTL = 300;

    public function start(Request $request, string $provider, LoginThrottle $throttle): Response|RedirectResponse
    {
        if ($refused = $this->providerUnavailable($provider)) {
            return $refused;
        }
        $data = $request->validate([
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:26'],
        ]);

        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete()) {
            return redirect()->route('guest.portal');
        }
        // The guest must have accepted the version that is current now (it may change meanwhile).
        $terms = TermsVersion::current(app()->getLocale());
        if (($data['terms_version'] ?? null) !== $terms?->id) {
            throw ValidationException::withMessages(['terms' => __('portal.guest.errors.terms_changed')]);
        }
        if ($throttle->isLocked($context->mac, $request->ip())) {
            return $this->error('locked', 429);
        }
        // The provider returns to the redirect URI; it must be this host, where the session cookie lives,
        // and it must be registered at the provider (one URI per portal origin, shown in the settings).
        $origin = self::origin($request->getSchemeAndHttpHost());
        if (! in_array($origin, array_map(self::origin(...), SocialSettings::origins()), true)) {
            Log::warning('Social login refused: the guest host is not a portal origin (APP_URL or a site portal URL)',
                ['host' => $request->getHost(), 'provider' => $provider]);

            return $this->error('misconfigured');
        }

        $request->session()->put(self::FLOW, [
            'provider' => $provider,
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
            'redirect_uri' => $origin.'/portal/social/'.$provider.'/callback',
            'terms_version' => $terms?->id,
            'site' => $site->id,
            'mac' => $context->mac,
            'started_at' => now()->getTimestamp(),
        ]);

        return redirect()->route('guest.social.go', $provider);
    }

    /** Second step of the start: the 302 to the provider is the answer of a GET, not of the form POST. */
    public function go(Request $request, string $provider, SocialLogin $social): Response|RedirectResponse
    {
        if ($refused = $this->providerUnavailable($provider)) {
            return $refused;
        }
        $flow = $request->session()->get(self::FLOW);
        if (! $this->fresh($flow) || $flow['provider'] !== $provider) {
            return redirect()->route('guest.portal', ['method' => LoginMethods::SOCIAL]);
        }
        try {
            $url = $social->authorizationUrl($provider, $flow['redirect_uri'], $flow['state'], $flow['nonce'], $flow['verifier']);
        } catch (Throwable $e) {
            Log::warning('Social login start failed', ['provider' => $provider, 'error' => class_basename($e)]);

            return $this->error('social_failed', 403);
        }

        return redirect()->away($url);
    }

    /**
     * Apple's response_mode=form_post arrives as a cross-site POST without the SameSite=Lax session cookie
     * (route without session and CSRF). A minimal page forwards code, state, error and Apple's `user` JSON
     * (sent only on the first authorisation) to the GET callback as a same-site navigation; the values are
     * encrypted with the instance key and valid for 5 minutes, so neither the code nor the name and email
     * appear in access logs.
     */
    public function bounce(Request $request, string $provider): Response
    {
        $field = fn (string $name, int $max) => is_string($value = $request->input($name)) ? mb_substr($value, 0, $max) : null;
        $payload = Crypt::encryptString((string) json_encode([
            'code' => $field('code', 2048), 'state' => $field('state', 128), 'error' => $field('error', 128),
            'user' => $field('user', 4096), 'at' => now()->getTimestamp(),
        ]));
        $url = route('guest.social.callback', $provider, false).'?'.http_build_query(['bounce' => $payload]);
        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
            .'<meta http-equiv="refresh" content="0;url='.e($url).'"><title>'.e(__('portal.guest.title')).'</title></head>'
            .'<body><p><a href="'.e($url).'">'.e(__('portal.guest.continue')).'</a></p></body></html>';

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function callback(Request $request, string $provider, SocialLogin $social, GrantManager $grants, LoginThrottle $throttle): View|Response|RedirectResponse
    {
        // Single use: the attempt is consumed by the first callback, whatever its outcome.
        $flow = $request->session()->pull(self::FLOW);
        $input = $this->callbackInput($request);
        $log = ['provider' => $provider];
        if (! $this->fresh($flow) || $flow['provider'] !== $provider || $input === null) {
            Log::warning('Social login callback without a matching login attempt (expired, used or other provider)', $log);

            return $this->error('social_failed', 403);
        }
        if ($input['error'] !== null) {
            // Only the OAuth error code (e.g. access_denied when the guest cancelled).
            Log::warning('Social login: the provider returned an error', $log + ['error' => substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $input['error']), 0, 64)]);

            return $this->error('social_failed', 403);
        }
        if ($input['state'] === null || ! hash_equals($flow['state'], $input['state']) || $input['code'] === null) {
            Log::warning('Social login callback with a wrong state or without a code', $log);

            return $this->error('social_failed', 403);
        }
        if ($refused = $this->providerUnavailable($provider)) {
            return $refused;
        }
        [$site, $context] = $this->current($request);
        if (! $this->sameDevice($flow, $site, $context)) {
            return redirect()->route('guest.portal');
        }

        try {
            $identity = $social->identity($provider, $flow['redirect_uri'], $input['code'], $flow['nonce'], $flow['verifier'], $input['user']);
        } catch (SocialLoginException $e) {
            Log::warning('Social login failed', $log + ['site' => $site->slug, 'reason' => $e->getMessage()]);

            return $this->error('social_failed', 403);
        }
        $identity = new SocialIdentity($identity->provider, mb_substr($identity->subject, 0, 191), $identity->firstName,
            $identity->lastName, $identity->email);

        if (! $identity->hasName()) {
            $request->session()->put(self::PENDING, ['identity' => $identity->toArray()]
                + array_intersect_key($flow, array_flip(['terms_version', 'site', 'mac', 'started_at'])));

            return redirect()->route('guest.social.name');
        }

        return $this->complete($request, $identity, $flow['terms_version'], $site, $context, $grants, $throttle);
    }

    /** "Complete your name": the provider did not report a first or last name. */
    public function name(Request $request): View|Response|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! $this->fresh($pending)) {
            return redirect()->route('guest.portal', ['method' => LoginMethods::SOCIAL]);
        }
        $identity = SocialIdentity::fromArray($pending['identity']);

        return view('guest.social-name', [
            'provider' => SocialLogin::name($identity->provider),
            'first' => $identity->firstName,
            'last' => $identity->lastName,
        ]);
    }

    public function storeName(Request $request, GrantManager $grants, LoginThrottle $throttle): View|Response|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);
        if (! $this->fresh($pending)) {
            return redirect()->route('guest.portal', ['method' => LoginMethods::SOCIAL]);
        }
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
        ]);
        [$site, $context] = $this->current($request);
        if (! $this->sameDevice($pending, $site, $context)) {
            return redirect()->route('guest.portal');
        }
        $identity = SocialIdentity::fromArray($pending['identity'])
            ->withName(SocialIdentity::clean($data['first_name']), SocialIdentity::clean($data['last_name']));
        if (! $identity->hasName()) {
            throw ValidationException::withMessages(['first_name' => __('validation.required', ['attribute' => __('portal.guest.first_name')])]);
        }

        return $this->complete($request, $identity, $pending['terms_version'] ?? null, $site, $context, $grants, $throttle);
    }

    /** Guest, terms acceptance, grant of type social and handoff, like the SMS verify path. */
    private function complete(Request $request, SocialIdentity $identity, ?string $termsVersion, Site $site, HotspotContext $context,
        GrantManager $grants, LoginThrottle $throttle): View|Response|RedirectResponse
    {
        if ($refused = $this->providerUnavailable($identity->provider)) {
            return $refused;
        }
        // The version accepted at the start must still be current.
        $terms = TermsVersion::current(app()->getLocale());
        if ($termsVersion !== $terms?->id) {
            $request->session()->forget([self::FLOW, self::PENDING]);

            return redirect()->route('guest.portal', ['method' => LoginMethods::SOCIAL])
                ->withErrors(['terms' => __('portal.guest.errors.terms_changed')]);
        }

        $mac = $context->mac;
        // Lockout check and recording like the other methods; a verified identity always succeeds.
        $result = $throttle->attempt($mac, $request->ip(), $site->id, 'social', fn () => DB::transaction(function () use ($identity, $site, $mac, $terms, $grants) {
            $guest = Guest::create([
                'first_name' => $identity->firstName,
                'last_name' => $identity->lastName,
                'locale' => app()->getLocale(),
                'social_provider' => $identity->provider,
                'social_subject' => $identity->subject,
                'email' => $identity->email,
            ]);
            // Each login gets its own grant (like SMS), time running from now, restricted to the site.
            $grant = $grants->createTimedGrant(GrantType::Social, $site, SocialSettings::durationS(), SocialLogin::name($identity->provider));
            TermsAcceptance::create([
                'guest_id' => $guest->id,
                'terms_version_id' => $terms?->id,
                'grant_id' => $grant->id,
                'site_id' => $site->id,
                'mac' => $mac,
                'accepted_at' => now(),
            ]);
            [$token, $password] = $grants->issueToken($grant, $guest, $site, $mac);

            return [$grant, $token, $password];
        }));
        $request->session()->forget([self::FLOW, self::PENDING]);
        if ($result === false) {
            return $this->error('locked', 429);
        }
        [$grant, $token, $password] = $result;
        Log::info('Guest social login', ['grant' => $grant->id, 'site' => $site->slug, 'mac' => $mac,
            'provider' => $identity->provider, 'terms_version' => $terms?->number]);

        return $this->handoff($request, $grant, $site, $context, $token, $password);
    }

    /** Error page unless social login is available and the provider is offered to guests. */
    private function providerUnavailable(string $provider): ?Response
    {
        return $this->methodUnavailable(LoginMethods::SOCIAL)
            ?? (in_array($provider, SocialSettings::enabledProviders(), true) ? null : $this->error('method_disabled', 403));
    }

    /** A login attempt or pending completion of this session that has not expired. */
    private function fresh(mixed $entry): bool
    {
        return is_array($entry) && is_int($entry['started_at'] ?? null)
            && $entry['started_at'] > now()->subMinutes(self::TTL_MINUTES)->getTimestamp();
    }

    /** The callback belongs to the device and site the attempt was started for. */
    private function sameDevice(array $entry, ?Site $site, HotspotContext $context): bool
    {
        return $site !== null && $context->isComplete() && $site->id === ($entry['site'] ?? null) && $context->mac === ($entry['mac'] ?? null);
    }

    /**
     * code, state, error and (Apple) the decoded `user` JSON of the callback; from the encrypted bounce
     * parameter after an Apple form_post. Null when a bounce parameter is invalid or too old.
     *
     * @return array{code: ?string, state: ?string, error: ?string, user: array<string, mixed>}|null
     */
    private function callbackInput(Request $request): ?array
    {
        $values = $request->query();
        if (array_key_exists('bounce', $values)) {
            try {
                $values = json_decode(Crypt::decryptString((string) $request->query('bounce')), true);
            } catch (Throwable) {
                return null;
            }
            if (! is_array($values) || ! is_int($values['at'] ?? null) || $values['at'] < now()->getTimestamp() - self::BOUNCE_TTL) {
                return null;
            }
        }
        $string = fn (string $key) => is_string($values[$key] ?? null) && $values[$key] !== '' ? $values[$key] : null;
        $user = is_string($values['user'] ?? null) ? json_decode($values['user'], true) : null;

        return ['code' => $string('code'), 'state' => $string('state'), 'error' => $string('error'), 'user' => is_array($user) ? $user : []];
    }

    /** scheme://host[:port] in lower case, default ports removed. */
    private static function origin(string $url): string
    {
        return (string) preg_replace(['#^(https://[^/]+):443$#', '#^(http://[^/]+):80$#'], '$1', strtolower(rtrim($url, '/')));
    }
}
