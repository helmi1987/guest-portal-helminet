<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Oidc\OidcClient;
use App\Auth\Oidc\OidcException;
use App\Auth\Oidc\OidcSettings;
use App\Auth\Oidc\OidcUserResolver;
use App\Http\Controllers\Controller;
use App\Livewire\Admin\Login;
use App\Models\User;
use App\Support\Audit;
use App\Support\Redact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * OIDC login for the admin UI (CONCEPT.md §9): redirect to the identity provider and callback.
 * The user only sees a generic error; details go redacted to the log and as a reason code to the audit log.
 */
class OidcController extends Controller
{
    private const FLOW = 'oidc.flow';

    /** Max. time between redirect and callback (seconds). */
    private const FLOW_TTL = 600;

    /** Max. auth.oidc_failed audit rows per hour (instance-wide). */
    private const FAILED_AUDIT_PER_HOUR = 100;

    /** RFC 8176 method values that count as multi-factor or phishing-resistant authentication. */
    private const MFA_AMR = ['mfa', 'otp', 'hwk', 'swk', 'sc', 'fpt', 'face', 'iris', 'retina', 'vbm'];

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(OidcSettings::enabled(), 404);

        $flow = [
            'state' => Str::random(40),
            'nonce' => Str::random(40),
            'verifier' => Str::random(64),
            'at' => now()->timestamp,
        ];
        try {
            $url = OidcClient::make()->authorizationUrl(OidcSettings::redirectUri(), $flow['state'], $flow['nonce'], $flow['verifier']);
        } catch (Throwable $e) {
            return $this->fail($e);
        }
        $request->session()->put(self::FLOW, $flow);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(OidcSettings::enabled(), 404);
        $flow = $request->session()->pull(self::FLOW);
        $secrets = [OidcSettings::clientSecret()];

        try {
            $code = $request->query('code');
            $state = $request->query('state');
            $error = $request->query('error');
            if (! is_array($flow) || now()->timestamp - (int) ($flow['at'] ?? 0) > self::FLOW_TTL) {
                throw new OidcException('state', 'No or expired login flow in the session');
            }
            if (! is_string($state) || ! hash_equals($flow['state'], $state)) {
                throw new OidcException('state', 'State mismatch');
            }
            if ($error !== null) {
                throw new OidcException('idp_error', 'Identity provider returned error='.(is_string($error) ? mb_substr($error, 0, 64) : '?'));
            }
            if (! is_string($code) || $code === '' || strlen($code) > 2048) {
                throw new OidcException('token', 'Callback without a valid code');
            }
            $secrets[] = $code;

            $config = OidcSettings::all();
            $client = OidcClient::make();
            $tokens = $client->exchangeCode($code, $flow['verifier'], OidcSettings::redirectUri());
            $idToken = $client->validateIdToken($tokens['id_token'], $flow['nonce']);
            $secrets[] = $tokens['id_token'];
            $secrets[] = is_string($tokens['access_token'] ?? null) ? $tokens['access_token'] : null;

            $claims = $idToken;
            $missing = ! isset($claims['email']) || ! isset($claims['email_verified']) || ! isset($claims[$config['groups_claim']]);
            if ($missing && is_string($tokens['access_token'] ?? null)) {
                // ID token claims win; userinfo only fills missing ones.
                $claims = $idToken + $client->userinfo($tokens['access_token'], $idToken['sub']);
            }
            $resolver = new OidcUserResolver($config);
            $user = $resolver->resolve($claims);
        } catch (Throwable $e) {
            return $this->fail($e, $secrets);
        }

        $idpMfa = $this->idpMfa($idToken, (string) $config['acr'], $config['max_auth_age']);
        // Linking an existing local user with TOTP: its own code is always asked once.
        $needsCode = $user->hasTwoFactor() && ($resolver->linked || match ($config['totp_mode']) {
            OidcSettings::TOTP_NEVER => false,
            OidcSettings::TOTP_ALWAYS => true,
            default => ! $idpMfa,
        });

        $request->session()->regenerate();
        if ($needsCode) {
            if ($resolver->roleChanged) {
                $user->invalidateSessions();
            }
            // Reuse the code step of the password login (nothing is logged in yet).
            $request->session()->put(Login::PENDING, ['user' => $user->id, 'at' => now()->timestamp, 'method' => 'oidc']);

            return redirect()->route('login');
        }

        Auth::login($user);
        $request->session()->regenerate();
        if ($resolver->roleChanged) {
            // Other sessions of this user end; the new one stays.
            $user->invalidateSessions($request->session()->getId());
        }
        Audit::log('auth.login', $user, ['method' => 'oidc', 'idp_mfa' => $idpMfa], $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    /** @param  array<string, mixed>  $idToken */
    private function idpMfa(array $idToken, string $acr, ?int $maxAuthAge): bool
    {
        // Optional freshness: the MFA must have happened within max_auth_age seconds (auth_time).
        if ($maxAuthAge !== null) {
            $authTime = $idToken['auth_time'] ?? null;
            if (! is_numeric($authTime) || now()->timestamp - (int) $authTime > $maxAuthAge + OidcClient::LEEWAY) {
                return false;
            }
        }
        $amr = array_filter((array) ($idToken['amr'] ?? []), 'is_string');
        if (array_intersect(array_map('strtolower', $amr), self::MFA_AMR) !== []) {
            return true;
        }

        return $acr !== '' && ($idToken['acr'] ?? null) === $acr;
    }

    /** @param  list<?string>  $secrets */
    private function fail(Throwable $e, array $secrets = []): RedirectResponse
    {
        $reason = $e instanceof OidcException ? $e->reason : 'error';
        $user = $e instanceof OidcException ? $e->user : null;
        $detail = $e instanceof OidcException ? $e->getMessage() : class_basename($e).': '.$e->getMessage();
        if ($e instanceof OidcException && $e->invalidateSessions && $user !== null) {
            $user->invalidateSessions();
        }
        Log::warning('OIDC login failed', ['reason' => $reason, 'detail' => Redact::secrets($detail, $secrets)]);
        $this->auditFailure($reason, $user);

        return redirect()->route('login')->with('oidc_error', __('portal.oidc.failed'));
    }

    /** Audit rows for failed OIDC logins are capped, like failed password logins. */
    private function auditFailure(string $reason, ?User $user): void
    {
        $key = 'audit-oidc-failed';
        if (RateLimiter::tooManyAttempts($key, self::FAILED_AUDIT_PER_HOUR)) {
            if (Cache::add('audit-oidc-failed-capped', true, 3600)) {
                Log::warning('Failed OIDC logins exceed the audit cap; further failures are not audited this hour.');
            }

            return;
        }
        RateLimiter::hit($key, 3600);
        Audit::log('auth.oidc_failed', $user, ['reason' => $reason], $user, $user === null ? 'oidc' : null);
    }
}
