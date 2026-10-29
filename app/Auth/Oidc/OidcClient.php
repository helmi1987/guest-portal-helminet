<?php

namespace App\Auth\Oidc;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * OpenID Connect relying party for the admin login (CONCEPT.md §9): discovery, Authorization Code
 * flow with PKCE (S256), ID token validation (signature via JWKS with RS256/ES256, iss, aud, azp,
 * exp/iat, nonce) and the userinfo endpoint. All requests use HTTPS with TLS verification, short
 * timeouts and no redirects. Signature checks are done by firebase/php-jwt.
 */
class OidcClient
{
    public const ALGORITHMS = ['RS256', 'ES256'];

    /** Clock skew tolerated for exp/iat/nbf (seconds). */
    public const LEEWAY = 60;

    private const CACHE_TTL = 3600;

    /** Min. seconds between JWKS refetches caused by an unknown key id. */
    private const JWKS_REFETCH_INTERVAL = 60;

    /** @param  array<string, mixed>  $config  OidcSettings::all() */
    public function __construct(private readonly array $config, private readonly ?string $secret) {}

    public static function make(): self
    {
        return new self(OidcSettings::all(), OidcSettings::clientSecret());
    }

    /** Forget the cached discovery document and the JWKS it points to. */
    public static function forgetCache(string $issuer): void
    {
        $meta = Cache::get(self::discoveryCacheKey($issuer));
        if (is_array($meta) && is_string($meta['jwks_uri'] ?? null)) {
            Cache::forget(self::jwksCacheKey($meta['jwks_uri']));
        }
        Cache::forget(self::discoveryCacheKey($issuer));
    }

    /** @return array<string, mixed> provider metadata (cached) */
    public function discovery(): array
    {
        $issuer = (string) $this->config['issuer'];

        return Cache::remember(self::discoveryCacheKey($issuer), self::CACHE_TTL, function () use ($issuer) {
            $url = rtrim($issuer, '/').'/.well-known/openid-configuration';
            $meta = $this->getJson($url, 'discovery');
            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
                if (! is_string($meta[$field] ?? null) || $meta[$field] === '') {
                    throw new OidcException('discovery', "Discovery document lacks $field");
                }
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'userinfo_endpoint'] as $field) {
                if (isset($meta[$field]) && ! self::isHttps($meta[$field])) {
                    throw new OidcException('discovery', "Discovery $field is not an https URL");
                }
            }
            // OIDC Discovery §4.3: the issuer must match the configured one (trailing slash tolerated).
            if (rtrim($meta['issuer'], '/') !== rtrim($issuer, '/')) {
                throw new OidcException('discovery', 'Discovery issuer does not match the configured issuer');
            }

            return $meta;
        });
    }

    public function authorizationUrl(string $redirectUri, string $state, string $nonce, string $verifier): string
    {
        $endpoint = $this->discovery()['authorization_endpoint'];
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $redirectUri,
            'scope' => $this->config['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::codeChallenge($verifier),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query;
    }

    public static function codeChallenge(string $verifier): string
    {
        return JWT::urlsafeB64Encode(hash('sha256', $verifier, true));
    }

    /** @return array<string, mixed> token response (id_token, access_token, ...) */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): array
    {
        $meta = $this->discovery();
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $verifier,
        ];
        $request = $this->http()->asForm();
        $methods = $meta['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        if ($this->secret === null) {
            $form['client_id'] = $this->config['client_id'];
        } elseif (is_array($methods) && ! in_array('client_secret_basic', $methods, true) && in_array('client_secret_post', $methods, true)) {
            $form['client_id'] = $this->config['client_id'];
            $form['client_secret'] = $this->secret;
        } else {
            // RFC 6749 §2.3.1: both values form-encoded before Basic encoding.
            $request = $request->withBasicAuth(urlencode((string) $this->config['client_id']), urlencode($this->secret));
        }

        try {
            $response = $request->post($meta['token_endpoint'], $form);
        } catch (Throwable $e) {
            throw new OidcException('token', 'Token request failed: '.class_basename($e));
        }
        if (! $response->successful()) {
            // Only status and the OAuth error code: the body is not logged.
            $error = is_string($response->json('error')) ? mb_substr($response->json('error'), 0, 64) : '-';
            throw new OidcException('token', 'Token endpoint answered HTTP '.$response->status().' error='.$error);
        }
        $tokens = $response->json();
        if (! is_array($tokens) || ! is_string($tokens['id_token'] ?? null)) {
            throw new OidcException('token', 'Token response without id_token');
        }

        return $tokens;
    }

    /**
     * Verified ID token claims.
     *
     * @return array<string, mixed>
     */
    public function validateIdToken(string $jwt, string $nonce): array
    {
        $meta = $this->discovery();
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcException('id_token', 'Malformed ID token');
        }
        $header = json_decode((string) JWT::urlsafeB64Decode($parts[0]), true);
        $alg = is_array($header) ? ($header['alg'] ?? null) : null;
        if (! in_array($alg, self::ALGORITHMS, true)) {
            throw new OidcException('id_token', 'Unsupported ID token algorithm');
        }
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
        $key = $this->signingKey($meta['jwks_uri'], $alg, $kid, false)
            ?? $this->signingKey($meta['jwks_uri'], $alg, $kid, true)
            ?? throw new OidcException('id_token', 'No matching signing key in JWKS');

        $leeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY;
        try {
            $claims = json_decode((string) json_encode(JWT::decode($jwt, $key)), true);
        } catch (Throwable $e) {
            throw new OidcException('id_token', 'ID token rejected: '.class_basename($e).' '.$e->getMessage());
        } finally {
            JWT::$leeway = $leeway;
        }

        if (! is_array($claims) || ($claims['iss'] ?? null) !== $meta['issuer']) {
            throw new OidcException('id_token', 'ID token issuer mismatch');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($this->config['client_id'], $aud, true)) {
            throw new OidcException('id_token', 'ID token audience mismatch');
        }
        if ((count($aud) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== $this->config['client_id']) {
            throw new OidcException('id_token', 'ID token azp mismatch');
        }
        if (! is_numeric($claims['exp'] ?? null) || ! is_numeric($claims['iat'] ?? null)) {
            throw new OidcException('id_token', 'ID token without exp or iat');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new OidcException('nonce', 'ID token nonce mismatch');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new OidcException('id_token', 'ID token without sub');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    public function userinfo(string $accessToken, string $subject): array
    {
        $endpoint = $this->discovery()['userinfo_endpoint'] ?? null;
        if (! is_string($endpoint)) {
            return [];
        }
        try {
            $response = $this->http()->withToken($accessToken)->get($endpoint);
        } catch (Throwable $e) {
            throw new OidcException('userinfo', 'Userinfo request failed: '.class_basename($e));
        }
        $claims = $response->successful() ? $response->json() : null;
        if (! is_array($claims)) {
            throw new OidcException('userinfo', 'Userinfo endpoint answered HTTP '.$response->status());
        }
        // OIDC Core §5.3.2: the sub must match the ID token.
        if (($claims['sub'] ?? null) !== $subject) {
            throw new OidcException('userinfo', 'Userinfo sub mismatch');
        }

        return $claims;
    }

    private function signingKey(string $jwksUri, string $alg, ?string $kid, bool $fresh): ?Key
    {
        $cacheKey = self::jwksCacheKey($jwksUri);
        if ($fresh) {
            // Key rotation: refetch once, but not more often than every minute.
            if (! Cache::add($cacheKey.'.refetch', true, self::JWKS_REFETCH_INTERVAL)) {
                return null;
            }
            Cache::forget($cacheKey);
        }
        $jwks = Cache::remember($cacheKey, self::CACHE_TTL, fn () => $this->getJson($jwksUri, 'jwks'));

        $candidates = [];
        foreach ((array) ($jwks['keys'] ?? []) as $jwk) {
            if (! is_array($jwk) || ($jwk['use'] ?? 'sig') !== 'sig' || (isset($jwk['alg']) && $jwk['alg'] !== $alg)) {
                continue;
            }
            $type = $alg === 'RS256' ? ($jwk['kty'] ?? null) === 'RSA' : ($jwk['kty'] ?? null) === 'EC' && ($jwk['crv'] ?? null) === 'P-256';
            if (! $type || ($kid !== null && ($jwk['kid'] ?? null) !== $kid)) {
                continue;
            }
            $candidates[] = $jwk;
        }
        // Without kid only an unambiguous key is used.
        if (count($candidates) !== 1) {
            return null;
        }
        try {
            return JWK::parseKey($candidates[0], $alg);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function getJson(string $url, string $reason): array
    {
        if (! self::isHttps($url)) {
            throw new OidcException($reason, 'Refusing a non-https URL');
        }
        try {
            $response = $this->http()->get($url);
        } catch (Throwable $e) {
            throw new OidcException($reason, ucfirst($reason).' request failed: '.class_basename($e));
        }
        $json = $response->successful() ? $response->json() : null;
        if (! is_array($json)) {
            throw new OidcException($reason, ucfirst($reason).' answered HTTP '.$response->status());
        }

        return $json;
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(10)->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }

    private static function isHttps(mixed $url): bool
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://') && (string) parse_url($url, PHP_URL_HOST) !== '';
    }

    private static function jwksCacheKey(string $jwksUri): string
    {
        return 'oidc.jwks.'.sha1($jwksUri);
    }

    private static function discoveryCacheKey(string $issuer): string
    {
        return 'oidc.discovery.'.sha1(rtrim($issuer, '/'));
    }
}
