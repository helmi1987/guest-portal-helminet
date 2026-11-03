<?php

namespace App\Auth\Social;

use Firebase\JWT\JWT;
use InvalidArgumentException;

/**
 * Client secret for Sign in with Apple (CONCEPT.md §9): a short-lived ES256 JWT signed with the
 * private key (.p8, EC P-256) of the Apple developer account; issuer = team ID, subject = services ID
 * (client ID), header `kid` = key ID. Never log the key or the result.
 */
final class AppleClientSecret
{
    public const AUDIENCE = 'https://appleid.apple.com';

    /** @throws InvalidArgumentException when the key is not a readable EC P-256 private key */
    public static function make(string $clientId, string $teamId, string $keyId, #[\SensitiveParameter] string $privateKeyPem, int $ttl = 300): string
    {
        $key = @openssl_pkey_get_private($privateKeyPem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC
            || ($details['ec']['curve_name'] ?? null) !== 'prime256v1') {
            throw new InvalidArgumentException('The Apple private key is not a readable EC P-256 key.');
        }
        $now = time();

        return JWT::encode([
            'iss' => $teamId,
            'iat' => $now,
            'exp' => $now + $ttl,
            'aud' => self::AUDIENCE,
            'sub' => $clientId,
        ], $privateKeyPem, 'ES256', $keyId);
    }
}
