<?php

namespace App\Auth\Social;

use App\Support\OutboundHttp;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\FacebookProvider;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User;
use Throwable;

/**
 * Provider side of the guest social login (CONCEPT.md §7.2 D) on top of laravel/socialite: authorization
 * URL and code exchange per provider, profile mapped to a SocialIdentity. State, nonce and the PKCE
 * verifier belong to the portal's own login flow (one session entry per attempt, SocialLoginController),
 * so Socialite runs stateless and never touches the session. PKCE (S256) is sent to the providers that
 * document it (Google, GitHub, Microsoft). Every request goes through a Guzzle client with TLS verification, 10 s
 * timeout, 5 s connect timeout and no redirects, built on the Laravel HTTP client's handler stack.
 */
class SocialLogin
{
    /** Scopes per provider. */
    public const SCOPES = [
        SocialSettings::GOOGLE => ['openid', 'email', 'profile'],
        SocialSettings::APPLE => ['name', 'email'],
        SocialSettings::FACEBOOK => ['email', 'public_profile'],
        SocialSettings::GITHUB => ['read:user', 'user:email'],
        SocialSettings::MICROSOFT => ['openid', 'profile', 'email'],
    ];

    /** Providers that get PKCE (S256). */
    public const PKCE = [SocialSettings::GOOGLE, SocialSettings::GITHUB, SocialSettings::MICROSOFT];

    /** Providers that get a nonce (checked in their ID token). */
    public const NONCE = [SocialSettings::GOOGLE, SocialSettings::APPLE, SocialSettings::MICROSOFT];

    /** Providers whose identity comes from the ID token of the token response (no access token needed). */
    private const ID_TOKEN = [SocialSettings::APPLE, SocialSettings::MICROSOFT];

    /** Display names (brands, not translated). */
    public const NAMES = [
        SocialSettings::GOOGLE => 'Google',
        SocialSettings::APPLE => 'Apple',
        SocialSettings::FACEBOOK => 'Facebook',
        SocialSettings::GITHUB => 'GitHub',
        SocialSettings::MICROSOFT => 'Microsoft',
    ];

    private const GOOGLE_ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    public static function name(string $provider): string
    {
        return self::NAMES[$provider] ?? $provider;
    }

    /** URL of the provider's authorization page for this login attempt. */
    public function authorizationUrl(string $provider, string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        $params = ['state' => $state];
        if (in_array($provider, self::NONCE, true)) {
            $params['nonce'] = $nonce;
        }
        if (in_array($provider, self::PKCE, true)) {
            $params['code_challenge'] = JWT::urlsafeB64Encode(hash('sha256', $verifier, true));
            $params['code_challenge_method'] = 'S256';
        }

        return $this->provider($provider, $redirectUri, false)->with($params)->redirect()->getTargetUrl();
    }

    /**
     * Exchange the authorization code and read the profile.
     *
     * @param  array<string, mixed>  $appleUser  Apple only: decoded `user` JSON of the first authorisation
     *
     * @throws SocialLoginException
     */
    public function identity(string $provider, string $redirectUri, string $code, string $nonce, string $verifier, array $appleUser = []): SocialIdentity
    {
        $label = self::name($provider);
        try {
            $driver = $this->provider($provider, $redirectUri, true);
            if (in_array($provider, self::PKCE, true)) {
                $driver->with(['code_verifier' => $verifier]);
            }
            $tokens = $driver->getAccessTokenResponse($code);
            if (! is_array($tokens)) {
                throw new SocialLoginException("$label token endpoint answered without JSON");
            }
            $tokenField = in_array($provider, self::ID_TOKEN, true) ? 'id_token' : 'access_token';
            if (! is_string($tokens[$tokenField] ?? null) || $tokens[$tokenField] === '') {
                // Only the OAuth error code: the body is not logged.
                throw new SocialLoginException("$label token response without $tokenField, error=".self::errorCode($tokens['error'] ?? null));
            }

            return match ($provider) {
                SocialSettings::GOOGLE => $this->google($driver, $tokens, $nonce),
                SocialSettings::APPLE => $this->apple($driver, $tokens, $nonce, $appleUser),
                SocialSettings::FACEBOOK => $this->facebook($driver, $tokens),
                SocialSettings::GITHUB => $this->github($driver, $tokens),
                SocialSettings::MICROSOFT => $this->microsoft($driver, $tokens, $nonce),
            };
        } catch (SocialLoginException $e) {
            throw $e;
        } catch (RequestException $e) {
            // Never the exception message: it carries request URLs (Facebook sends the access token in the query).
            throw new SocialLoginException("$label request failed: HTTP ".($e->getResponse()?->getStatusCode() ?? '-'));
        } catch (Throwable $e) {
            throw new SocialLoginException("$label login failed: ".class_basename($e));
        }
    }

    private function google(AbstractProvider $driver, array $tokens, string $nonce): SocialIdentity
    {
        // ID token received directly from the token endpoint over TLS: its claims are trusted without a
        // signature check (OpenID Connect Core 3.1.3.7); it carries the nonce of this attempt.
        $claims = self::jwtClaims($tokens['id_token'] ?? null)
            ?? throw new SocialLoginException('Google token response without a readable ID token');
        $config = SocialSettings::provider(SocialSettings::GOOGLE);
        if (! in_array($claims['iss'] ?? null, self::GOOGLE_ISSUERS, true) || ! in_array($config['client_id'], (array) ($claims['aud'] ?? []), true)) {
            throw new SocialLoginException('Google ID token issuer or audience mismatch');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SocialLoginException('Google ID token nonce mismatch');
        }
        $user = $driver->userFromToken($tokens['access_token']);
        $raw = $user->getRaw();
        if ((string) ($raw['sub'] ?? '') === '' || (string) ($raw['sub'] ?? '') !== (string) ($claims['sub'] ?? '')) {
            throw new SocialLoginException('Google userinfo subject does not match the ID token');
        }

        return new SocialIdentity(SocialSettings::GOOGLE, (string) $raw['sub'], SocialIdentity::clean($raw['given_name'] ?? ''),
            SocialIdentity::clean($raw['family_name'] ?? ''), SocialIdentity::cleanEmail($raw['email'] ?? null));
    }

    private function apple(AppleProvider $driver, array $tokens, string $nonce, array $appleUser): SocialIdentity
    {
        $user = $driver->userFromIdToken($tokens['id_token'], $nonce, $appleUser);
        $raw = $user->getRaw();

        return new SocialIdentity(SocialSettings::APPLE, (string) $user->getId(), SocialIdentity::clean($raw['first_name'] ?? ''),
            SocialIdentity::clean($raw['last_name'] ?? ''), SocialIdentity::cleanEmail($raw['email'] ?? null));
    }

    private function facebook(FacebookProvider $driver, array $tokens): SocialIdentity
    {
        $user = $driver->fields(['id', 'first_name', 'last_name', 'email'])->userFromToken($tokens['access_token']);
        $raw = $user->getRaw();

        return new SocialIdentity(SocialSettings::FACEBOOK, self::subject($user), SocialIdentity::clean($raw['first_name'] ?? ''),
            SocialIdentity::clean($raw['last_name'] ?? ''), SocialIdentity::cleanEmail($user->getEmail()));
    }

    private function github(GithubProvider $driver, array $tokens): SocialIdentity
    {
        $user = $driver->userFromToken($tokens['access_token']);
        // "name" is one free-text field: split at the first space; the login is the fallback first name.
        $name = SocialIdentity::clean($user->getName() ?? '');
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
        if ($first === '') {
            $first = SocialIdentity::clean($user->getNickname() ?? '');
        }

        return new SocialIdentity(SocialSettings::GITHUB, self::subject($user), $first, SocialIdentity::clean($last),
            SocialIdentity::cleanEmail($user->getEmail()));
    }

    private function microsoft(MicrosoftProvider $driver, array $tokens, string $nonce): SocialIdentity
    {
        $user = $driver->userFromIdToken($tokens['id_token'], $nonce);
        [$first, $last, $email] = MicrosoftProvider::profile($user->getRaw());

        return new SocialIdentity(SocialSettings::MICROSOFT, self::subject($user), $first, $last, $email);
    }

    /** Socialite provider for one login attempt; the secret is only needed for the code exchange. */
    private function provider(string $provider, string $redirectUri, bool $withSecret): AbstractProvider
    {
        $config = SocialSettings::provider($provider);
        $secret = $withSecret ? (SocialSettings::secret($provider)
            ?? throw new SocialLoginException(self::name($provider).' secret missing or unreadable')) : '';
        if ($provider === SocialSettings::APPLE) {
            $driver = new AppleProvider(request(), $config['client_id'], $withSecret
                ? AppleClientSecret::make($config['client_id'], $config['team_id'], $config['key_id'], $secret) : '', $redirectUri);
        } elseif ($provider === SocialSettings::MICROSOFT) {
            $driver = (new MicrosoftProvider(request(), $config['client_id'], $secret, $redirectUri))->setTenant($config['tenant']);
        } else {
            $class = match ($provider) {
                SocialSettings::GOOGLE => GoogleProvider::class,
                SocialSettings::FACEBOOK => FacebookProvider::class,
                SocialSettings::GITHUB => GithubProvider::class,
                default => throw new SocialLoginException('Unknown social login provider'),
            };
            $driver = Socialite::buildProvider($class, ['client_id' => $config['client_id'], 'client_secret' => $secret, 'redirect' => $redirectUri]);
        }

        return $driver->stateless()->setScopes(self::SCOPES[$provider])->setHttpClient(self::http());
    }

    /**
     * Guzzle client for Socialite: TLS verified, short timeouts, no redirects (CONCEPT.md §12). Built on
     * the handler stack of the Laravel HTTP client, so Http::fake() and the request log of tests apply.
     */
    public static function http(): Client
    {
        return new Client([
            'handler' => OutboundHttp::client()->buildHandlerStack(),
            'timeout' => 10,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'verify' => true,
            'http_errors' => true,
        ]);
    }

    /** @return array<string, mixed>|null payload of a JWT (no signature check) */
    public static function jwtClaims(mixed $jwt): ?array
    {
        $parts = is_string($jwt) ? explode('.', $jwt) : [];
        $claims = count($parts) === 3 ? json_decode((string) JWT::urlsafeB64Decode($parts[1]), true) : null;

        return is_array($claims) ? $claims : null;
    }

    private static function subject(User $user): string
    {
        $id = (string) $user->getId();

        return $id !== '' ? $id : throw new SocialLoginException('Profile without user ID');
    }

    private static function errorCode(mixed $error): string
    {
        return is_string($error) ? substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $error), 0, 64) : '-';
    }
}
