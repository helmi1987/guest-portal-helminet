<?php

namespace App\Auth\Social;

use App\Support\OutboundHttp;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use LogicException;
use Throwable;

/**
 * Sign in with Apple for Socialite (CONCEPT.md §7.2 D): authorization with scope "name email" and
 * response_mode=form_post (required by Apple for these scopes), token request with the ES256 client
 * secret (AppleClientSecret), user from the ID token of the token response. The ID token is verified
 * against Apple's JWKS (RS256, cached for an hour, refetched at most once a minute for an unknown key
 * ID): signature, iss, aud = client ID, exp/iat, nonce and sub. Apple has no userinfo endpoint; the name
 * is sent only on the first authorisation, as the `user` JSON of the form post.
 */
class AppleProvider extends AbstractProvider
{
    public const ISSUER = 'https://appleid.apple.com';

    public const AUTH_URL = 'https://appleid.apple.com/auth/authorize';

    public const TOKEN_URL = 'https://appleid.apple.com/auth/token';

    public const JWKS_URL = 'https://appleid.apple.com/auth/keys';

    /** Clock skew tolerated for exp/iat (seconds). */
    public const LEEWAY = 60;

    private const JWKS_CACHE = 'social.apple.jwks';

    protected $scopes = ['name', 'email'];

    protected $scopeSeparator = ' ';

    protected $encodingType = PHP_QUERY_RFC3986;

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::AUTH_URL, $state);
    }

    protected function getCodeFields($state = null)
    {
        return ['response_mode' => 'form_post'] + parent::getCodeFields($state);
    }

    protected function getTokenUrl()
    {
        return self::TOKEN_URL;
    }

    protected function getUserByToken($token)
    {
        // Apple has no userinfo endpoint: use userFromIdToken() with the ID token of the token response.
        throw new LogicException('Sign in with Apple has no userinfo endpoint.');
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => (string) ($user['sub'] ?? ''),
            'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?: null,
            'email' => $user['email'] ?? null,
        ]);
    }

    /**
     * User from a verified ID token; names from the `user` JSON of the first authorisation (if any).
     *
     * @param  array<string, mixed>  $userJson  decoded `user` form field: {name: {firstName, lastName}, email}
     */
    public function userFromIdToken(string $idToken, string $nonce, array $userJson = []): User
    {
        $claims = $this->verifyIdToken($idToken, $nonce);
        $name = is_array($userJson['name'] ?? null) ? $userJson['name'] : [];

        return $this->mapUserToObject($claims + [
            'first_name' => is_string($name['firstName'] ?? null) ? $name['firstName'] : '',
            'last_name' => is_string($name['lastName'] ?? null) ? $name['lastName'] : '',
        ]);
    }

    /**
     * Verified claims of an Apple ID token.
     *
     * @return array<string, mixed>
     */
    public function verifyIdToken(string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        $header = count($parts) === 3 ? json_decode((string) JWT::urlsafeB64Decode($parts[0]), true) : null;
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256' || ! is_string($header['kid'] ?? null)) {
            throw new SocialLoginException('Apple ID token malformed or not RS256 with a key ID');
        }
        $keys = $this->keys($header['kid'], false) ?? $this->keys($header['kid'], true)
            ?? throw new SocialLoginException('Apple ID token signed with an unknown key');

        $leeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $claims = json_decode((string) json_encode(JWT::decode($jwt, $keys)), true);
        } catch (Throwable $e) {
            throw new SocialLoginException('Apple ID token rejected: '.class_basename($e));
        } finally {
            JWT::$leeway = $leeway;
        }

        if (! is_array($claims) || ($claims['iss'] ?? null) !== self::ISSUER) {
            throw new SocialLoginException('Apple ID token issuer mismatch');
        }
        if (! in_array($this->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new SocialLoginException('Apple ID token audience mismatch');
        }
        if (! is_numeric($claims['exp'] ?? null) || ! is_numeric($claims['iat'] ?? null)) {
            throw new SocialLoginException('Apple ID token without exp or iat');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SocialLoginException('Apple ID token nonce mismatch');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new SocialLoginException('Apple ID token without sub');
        }

        return $claims;
    }

    /** @return array<string, Key>|null Apple's keys when the set contains $kid */
    private function keys(string $kid, bool $fresh): ?array
    {
        if ($fresh) {
            // Key rotation: refetch once, but not more often than every minute.
            if (! Cache::add(self::JWKS_CACHE.'.refetch', true, 60)) {
                return null;
            }
            Cache::forget(self::JWKS_CACHE);
        }
        $jwks = Cache::remember(self::JWKS_CACHE, 3600, function () {
            try {
                $response = OutboundHttp::client()->acceptJson()->get(self::JWKS_URL);
            } catch (Throwable $e) {
                throw new SocialLoginException('Apple JWKS request failed: '.class_basename($e));
            }
            $json = $response->successful() ? $response->json() : null;
            if (! is_array($json) || ! is_array($json['keys'] ?? null)) {
                throw new SocialLoginException('Apple JWKS answered HTTP '.$response->status());
            }

            return $json;
        });
        try {
            $keys = JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable) {
            return null;
        }

        return isset($keys[$kid]) ? $keys : null;
    }
}
