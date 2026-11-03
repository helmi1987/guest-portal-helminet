<?php

namespace App\Auth\Social;

use Firebase\JWT\JWT;

/**
 * Client secret for Sign in with Apple (CONCEPT.md §7.2 D): a short-lived ES256 JWT signed with the
 * private key (.p8) of the Apple developer account, header kid = key ID; iss = team ID, sub = client
 * (services) ID, aud = https://appleid.apple.com. Created per token request, never stored.
 */
final class AppleClientSecret
{
    public const AUDIENCE = 'https://appleid.apple.com';

    public static function make(string $clientId, string $teamId, string $keyId, string $privateKeyPem, int $ttl = 300): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + max(60, $ttl),
            'aud' => self::AUDIENCE,
            'sub' => $clientId,
        ], $privateKeyPem, 'ES256', $keyId);
    }
}
