<?php

namespace App\Tls;

use App\Models\CertificateSource;
use App\Models\LocalSetting;
use App\Support\LocalInstance;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Certificate source per target (CONCEPT.md §6), a replicated record per instance (only this
 * instance's own record is used here; status stays in local settings):
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
        $source = self::record($target);

        return [
            'type' => in_array($source?->type, self::TYPES, true) ? $source->type : 'none',
            'url' => $source?->url,
            'name' => $source?->name,
            'ca_pem' => $source?->ca_pem,
            'has_api_key' => $source !== null && ! empty($source->getRawOriginal('api_key')),
        ];
    }

    public static function apiKey(string $target): ?string
    {
        try {
            $key = self::record($target)?->api_key;
        } catch (Throwable) {
            return null;
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** $apiKey null keeps the stored key, '' removes it. */
    public static function put(string $target, string $type, ?string $url, ?string $name, ?string $caPem, ?string $apiKey): void
    {
        self::key($target);
        $local = app(LocalInstance::class)->id();
        $source = CertificateSource::withTrashed()->find(CertificateSource::idFor($local, $target))
            ?? new CertificateSource(['instance_id' => $local, 'target' => $target]);
        $source->fill(['type' => $type, 'url' => $url, 'name' => $name, 'ca_pem' => $caPem]);
        if ($apiKey !== null) {
            $source->api_key = $apiKey === '' ? null : $apiKey;
        }
        $source->deleted_at = null;
        $source->save();
    }

    /**
     * Take over the source of another instance (replacement instance): type, URL, name, CA and API
     * key are copied into this instance's record for the same target.
     */
    public static function copyFrom(string $instanceId, string $target): bool
    {
        $from = CertificateSource::query()->find(CertificateSource::idFor($instanceId, $target));
        if ($from === null) {
            return false;
        }
        self::put($target, $from->type, $from->url, $from->name, $from->ca_pem, $from->api_key ?? '');

        return true;
    }

    /** Sources of other instances (for the take-over choice). */
    public static function others(string $target): Collection
    {
        return CertificateSource::query()->with('instance')->where('target', $target)
            ->where('instance_id', '!=', app(LocalInstance::class)->id())->where('type', '!=', 'none')->get();
    }

    private static function record(string $target): ?CertificateSource
    {
        self::key($target);

        return CertificateSource::query()->find(CertificateSource::idFor(app(LocalInstance::class)->id(), $target));
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
