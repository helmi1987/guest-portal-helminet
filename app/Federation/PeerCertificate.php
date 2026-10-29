<?php

namespace App\Federation;

/**
 * Federation TLS identity (CONCEPT.md §10.2): each instance has its own key and self-signed
 * certificate; peers pin the public key as `sha256//<base64 of the SPKI sha256>` (the format of
 * curl's CURLOPT_PINNEDPUBLICKEY), stored in instances.cert_fingerprint.
 */
final class PeerCertificate
{
    /** Pin of a PEM certificate, null if it cannot be parsed. */
    public static function pin(?string $pem): ?string
    {
        if ($pem === null || ! str_contains($pem, '-----BEGIN CERTIFICATE-----')) {
            return null;
        }
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (! is_array($details) || ! isset($details['key'])) {
            return null;
        }
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $details['key']), true);

        return $der === false || $der === '' ? null : 'sha256//'.base64_encode(hash('sha256', $der, true));
    }

    public static function isPin(?string $value): bool
    {
        return is_string($value) && preg_match('#^sha256//[A-Za-z0-9+/]{43}=$#', $value) === 1;
    }

    public static function certPath(): string
    {
        return rtrim((string) config('portal.federation.dir'), '/').'/federation.crt';
    }

    public static function keyPath(): string
    {
        return rtrim((string) config('portal.federation.dir'), '/').'/federation.key';
    }

    /** Pin of this instance's certificate; null while it does not exist (e.g. development). */
    public static function localPin(): ?string
    {
        $path = self::certPath();

        return is_file($path) && is_readable($path) ? self::pin((string) file_get_contents($path)) : null;
    }
}
