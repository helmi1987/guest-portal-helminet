<?php

namespace App\Tls;

/**
 * A PEM bundle (private key + certificate + chain, any order) validated for installation:
 * unencrypted key, a certificate matching the key, currently valid. The matching certificate is
 * placed first, the remaining certificates follow in their original order.
 */
final class CertificateBundle
{
    private function __construct(public readonly string $certPem, public readonly string $keyPem) {}

    /** @throws CertificateException */
    public static function parse(string $pem): self
    {
        if (str_contains($pem, 'ENCRYPTED PRIVATE KEY') || str_contains($pem, 'Proc-Type: 4,ENCRYPTED')) {
            throw new CertificateException('encrypted private keys are not supported');
        }
        preg_match_all('/-----BEGIN ((?:RSA |EC )?PRIVATE KEY)-----.+?-----END \1-----/s', $pem, $keys);
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $certs);
        if (count($keys[0]) !== 1) {
            throw new CertificateException(count($keys[0]) === 0 ? 'no private key in response' : 'more than one private key in response');
        }
        if ($certs[0] === []) {
            throw new CertificateException('no certificate in response');
        }
        $key = trim($keys[0][0]);
        if (openssl_pkey_get_private($key) === false) {
            throw new CertificateException('private key cannot be read');
        }

        $leaf = null;
        foreach ($certs[0] as $i => $cert) {
            if (@openssl_x509_read($cert) === false) {
                throw new CertificateException('certificate '.($i + 1).' cannot be read');
            }
            if ($leaf === null && @openssl_x509_check_private_key($cert, $key)) {
                $leaf = $i;
            }
        }
        if ($leaf === null) {
            throw new CertificateException('private key does not match any certificate');
        }
        $ordered = [$certs[0][$leaf], ...array_values(array_diff_key($certs[0], [$leaf => true]))];

        $info = CertificateInfo::fromPem($ordered[0]);
        if ($info->validTo <= now()->getTimestamp()) {
            throw new CertificateException('certificate expired on '.date('Y-m-d', $info->validTo));
        }
        if ($info->validFrom > now()->getTimestamp() + 300) {
            throw new CertificateException('certificate not valid before '.date('Y-m-d H:i', $info->validFrom));
        }

        return new self(implode("\n", array_map('trim', $ordered))."\n", $key."\n");
    }

    public function info(): CertificateInfo
    {
        return CertificateInfo::fromPem($this->certPem);
    }
}
