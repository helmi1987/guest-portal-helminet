<?php

namespace App\Tls;

/**
 * Display data of a certificate (first certificate of a PEM): subject, issuer, DNS names, validity,
 * SHA-256 fingerprint.
 */
final class CertificateInfo
{
    /** @param  list<string>  $names  subjectAltName DNS/IP entries */
    private function __construct(
        public readonly string $subject,
        public readonly string $issuer,
        public readonly array $names,
        public readonly int $validFrom,
        public readonly int $validTo,
        public readonly string $fingerprint,
    ) {}

    /** @throws CertificateException */
    public static function fromPem(string $pem): self
    {
        $data = @openssl_x509_parse($pem);
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
        if ($data === false || $fingerprint === false) {
            throw new CertificateException('certificate cannot be read');
        }
        $names = [];
        foreach (array_map('trim', explode(',', (string) ($data['extensions']['subjectAltName'] ?? ''))) as $entry) {
            if (preg_match('/^(?:DNS|IP Address):(.+)$/', $entry, $m)) {
                $names[] = trim($m[1]);
            }
        }

        return new self(
            self::name($data['subject'] ?? []),
            self::name($data['issuer'] ?? []),
            $names,
            (int) $data['validFrom_time_t'],
            (int) $data['validTo_time_t'],
            implode(':', str_split(strtoupper($fingerprint), 2)),
        );
    }

    /** Info of a certificate file, null when missing or unreadable. */
    public static function fromFile(string $path): ?self
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        try {
            return self::fromPem((string) file_get_contents($path));
        } catch (CertificateException) {
            return null;
        }
    }

    /** Whether the certificate covers a host name (exact or single-label wildcard). */
    public function covers(string $host): bool
    {
        $host = strtolower($host);
        foreach ($this->names ?: [$this->subjectCn()] as $name) {
            $name = strtolower($name);
            if ($name === $host || (str_starts_with($name, '*.') && substr_count($host, '.') === substr_count($name, '.')
                && str_ends_with($host, substr($name, 1)))) {
                return true;
            }
        }

        return false;
    }

    public function expired(): bool
    {
        return $this->validTo <= time();
    }

    private function subjectCn(): string
    {
        return preg_match('/(?:^|, )CN=([^,]+)/', $this->subject, $m) ? $m[1] : '';
    }

    private static function name(array $parts): string
    {
        $out = [];
        foreach (['CN', 'O', 'C'] as $key) {
            if (isset($parts[$key])) {
                $out[] = $key.'='.(is_array($parts[$key]) ? implode('/', $parts[$key]) : $parts[$key]);
            }
        }

        return implode(', ', $out);
    }
}
