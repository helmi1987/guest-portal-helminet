<?php

namespace App\Tls;

use App\Models\LocalSetting;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Throwable;

/**
 * Certificate source per target (CONCEPT.md §6), local settings of this instance:
 *   portal -> /data/certs/portal.{crt,key} (guest and admin ports, nginx reloads via cert-watch)
 *   router -> /data/certs/router.{crt,key} (downloaded by the routers' scheduler)
 * Types: none (files placed by the operator), certwarden (private cert chain download with
 * "<cert API key>.<key API key>"), url (generic URL returning a PEM bundle, optional bearer token).
 * The API key is stored encrypted and never shown, logged or audited.
 */
final class CertificateSources
{
    public const TARGETS = ['portal', 'router'];

    public const TYPES = ['none', 'certwarden', 'url'];

    /** @return array{cert: string, key: string} */
    public static function paths(string $target): array
    {
        $config = match ($target) {
            'portal' => config('portal.portal_cert'),
            'router' => config('portal.router_cert'),
            default => throw new InvalidArgumentException("Unknown certificate target [$target]."),
        };

        return ['cert' => $config['cert'], 'key' => $config['key']];
    }

    /** @return array{type: string, url: ?string, name: ?string, ca_pem: ?string, has_api_key: bool} */
    public static function get(string $target): array
    {
        $stored = (array) LocalSetting::get(self::key($target), []);

        return [
            'type' => in_array($stored['type'] ?? null, self::TYPES, true) ? $stored['type'] : 'none',
            'url' => $stored['url'] ?? null,
            'name' => $stored['name'] ?? null,
            'ca_pem' => $stored['ca_pem'] ?? null,
            'has_api_key' => ! empty($stored['api_key']),
        ];
    }

    public static function apiKey(string $target): ?string
    {
        $encrypted = ((array) LocalSetting::get(self::key($target), []))['api_key'] ?? null;
        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }
        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    /** $apiKey null keeps the stored key, '' removes it. */
    public static function put(string $target, string $type, ?string $url, ?string $name, ?string $caPem, ?string $apiKey): void
    {
        $stored = (array) LocalSetting::get(self::key($target), []);
        $encrypted = match (true) {
            $apiKey === null => $stored['api_key'] ?? null,
            $apiKey === '' => null,
            default => Crypt::encryptString($apiKey),
        };
        LocalSetting::put(self::key($target), [
            'type' => $type,
            'url' => $url,
            'name' => $name,
            'ca_pem' => $caPem,
            'api_key' => $encrypted,
        ]);
    }

    /** @return array{checked_at?: string, result?: string, error?: ?string, fingerprint?: ?string} */
    public static function status(string $target): array
    {
        return (array) LocalSetting::get('cert_status.'.$target, []);
    }

    public static function putStatus(string $target, string $result, ?string $error = null, ?string $fingerprint = null, ?string $warning = null): void
    {
        LocalSetting::put('cert_status.'.$target, [
            'checked_at' => now()->toIso8601String(),
            'result' => $result,
            'error' => $error,
            'warning' => $warning,
            'fingerprint' => $fingerprint,
        ]);
    }

    /** Host name the portal certificate must cover: PORTAL_HOSTNAME, else the APP_URL host. */
    public static function portalHost(): ?string
    {
        $host = config('portal.hostname') ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /** URL for display and audit: the query (may carry a token) is masked. */
    public static function maskUrl(?string $url): ?string
    {
        if ($url === null || ! str_contains($url, '?')) {
            return $url;
        }

        return strstr($url, '?', true).'?***';
    }

    /** Query string parts of a source URL, for masking in error messages. */
    public static function urlSecrets(?string $url): array
    {
        $query = $url === null ? null : parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return [];
        }
        $values = [$query];
        foreach (explode('&', $query) as $pair) {
            $values[] = rawurldecode(explode('=', $pair, 2)[1] ?? $pair);
        }

        return $values;
    }

    private static function key(string $target): string
    {
        if (! in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException("Unknown certificate target [$target].");
        }

        return 'cert_source.'.$target;
    }
}
