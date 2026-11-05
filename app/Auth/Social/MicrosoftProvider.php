<?php

namespace App\Auth\Social;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;
use LogicException;

/**
 * Microsoft (Entra ID) sign-in for Socialite (CONCEPT.md §7.2 D, decision 2026-11-05, v0.31.0): authorization
 * code flow of the Microsoft identity platform v2.0 with scope "openid profile email", PKCE (S256), nonce and
 * response_mode=query; endpoints https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize and .../token,
 * tenant from the settings (common, consumers, organizations, a tenant ID or a domain).
 *
 * The user comes from the ID token of the token response, received directly from the token endpoint over TLS
 * (OpenID Connect Core 3.1.3.7, the same trust argument as Google), so no Graph call is needed. Checks: iss must be
 * https://login.microsoftonline.com/{tid}/v2.0 with the token's own `tid` claim (with tenant common, consumers or
 * organizations the issuer carries the real tenant of the account; personal Microsoft accounts use the tenant
 * 9188040d-6c67-4c5b-b112-36a304b66dad, which is not hard-coded here); with a configured tenant ID the `tid` must be
 * that tenant; aud = client ID; nonce of this attempt; non-empty sub.
 *
 * Subject: `sub`, the pairwise identifier of the user for this application (immutable, never reused). `oid` would
 * correlate the user across applications and differs per tenant as well, so it adds nothing for a guest login.
 * Names: given_name/family_name (optional claims of the app registration), else `name` split at the first space.
 * Email: `email`, else `preferred_username` only when it looks like an address (it can be a phone number or a
 * plain user name). Sources: learn.microsoft.com/entra/identity-platform/v2-oauth2-auth-code-flow,
 * id-token-claims-reference, optional-claims-reference.
 */
class MicrosoftProvider extends AbstractProvider
{
    public const LOGIN_HOST = 'https://login.microsoftonline.com';

    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    protected $encodingType = PHP_QUERY_RFC3986;

    private string $tenant = 'common';

    public function setTenant(string $tenant): static
    {
        $this->tenant = $tenant;

        return $this;
    }

    public static function authUrl(string $tenant): string
    {
        return self::LOGIN_HOST.'/'.rawurlencode($tenant).'/oauth2/v2.0/authorize';
    }

    public static function tokenUrl(string $tenant): string
    {
        return self::LOGIN_HOST.'/'.rawurlencode($tenant).'/oauth2/v2.0/token';
    }

    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(self::authUrl($this->tenant), $state);
    }

    protected function getCodeFields($state = null)
    {
        return ['response_mode' => 'query'] + parent::getCodeFields($state);
    }

    protected function getTokenUrl()
    {
        return self::tokenUrl($this->tenant);
    }

    protected function getUserByToken($token)
    {
        // The identity comes from the ID token of the token response (userFromIdToken()); no Graph call.
        throw new LogicException('Microsoft sign-in reads the user from the ID token.');
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => (string) ($user['sub'] ?? ''),
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
        ]);
    }

    /**
     * User from the ID token of the token response, after the claim checks.
     *
     * @throws SocialLoginException
     */
    public function userFromIdToken(mixed $idToken, string $nonce): User
    {
        return $this->mapUserToObject($this->verifyIdToken($idToken, $nonce));
    }

    /**
     * Checked claims of the ID token (no signature check: received directly from the token endpoint over TLS).
     *
     * @return array<string, mixed>
     *
     * @throws SocialLoginException
     */
    public function verifyIdToken(mixed $idToken, string $nonce): array
    {
        $claims = SocialLogin::jwtClaims($idToken)
            ?? throw new SocialLoginException('Microsoft token response without a readable ID token');
        $tid = $claims['tid'] ?? null;
        if (! SocialSettings::isGuid($tid) || ($claims['iss'] ?? null) !== self::LOGIN_HOST.'/'.$tid.'/v2.0') {
            throw new SocialLoginException('Microsoft ID token issuer mismatch');
        }
        if (SocialSettings::isGuid($this->tenant) && strtolower($tid) !== strtolower($this->tenant)) {
            throw new SocialLoginException('Microsoft ID token from another tenant');
        }
        if (! in_array($this->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new SocialLoginException('Microsoft ID token audience mismatch');
        }
        if (! is_string($claims['nonce'] ?? null) || ! hash_equals($nonce, $claims['nonce'])) {
            throw new SocialLoginException('Microsoft ID token nonce mismatch');
        }
        if (! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new SocialLoginException('Microsoft ID token without sub');
        }

        return $claims;
    }

    /** @return array{0: string, 1: string, 2: ?string} first name, last name, email of verified claims */
    public static function profile(array $claims): array
    {
        $first = SocialIdentity::clean($claims['given_name'] ?? '');
        $last = SocialIdentity::clean($claims['family_name'] ?? '');
        if ($first === '' && $last === '') {
            [$first, $last] = array_pad(explode(' ', SocialIdentity::clean($claims['name'] ?? ''), 2), 2, '');
        }
        $email = SocialIdentity::cleanEmail($claims['email'] ?? null);
        $username = $claims['preferred_username'] ?? null;
        if ($email === null && is_string($username) && str_contains($username, '@')) {
            $email = SocialIdentity::cleanEmail($username);
        }

        return [$first, SocialIdentity::clean($last), $email];
    }
}
