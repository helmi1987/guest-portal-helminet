<?php

namespace App\Federation;

use App\Branding\BrandingAssets;
use App\Branding\BrandingException;
use App\Branding\BrandingSettings;
use App\Jobs\FetchBrandingFiles;
use App\Models\Instance;
use App\Models\Setting;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Branding files of other instances (CONCEPT.md §10.3): settings reference logo/favicon by content
 * hash, the files themselves are not replicated. A referenced file missing here is fetched from the
 * instance that wrote the reference (writer of the row's version, then its origin), then from the
 * other direct peers (star: a non-hub instance asks the hub, which fetched it itself). The file must
 * pass the same checks as an upload (BrandingAssets::import(): size limit, type detection, SVG
 * sanitizing) and match its hash. Failures are retried after RETRY_SECONDS (sync run).
 */
class BrandingFiles
{
    public const RETRY_SECONDS = 300;

    private const FAILED_KEY = 'federation:branding-failed:';

    public function __construct(
        private Topology $topology,
        private PeerClient $client,
        private BrandingAssets $assets,
    ) {}

    /** Apply hooks: a changed branding reference queues a fetch of missing files. */
    public function register(ApplyHooks $hooks): void
    {
        $hooks->register('settings', function (AppliedChange $c) {
            if (($c->after['key'] ?? null) === BrandingSettings::SETTING) {
                $this->queueIfMissing();
            }
        });
        $hooks->register('sites', function (AppliedChange $c) {
            if (($c->before['settings'] ?? null) !== ($c->after['settings'] ?? null)) {
                $this->queueIfMissing();
            }
        });
    }

    public function queueIfMissing(): void
    {
        if ($this->missing() !== []) {
            FetchBrandingFiles::dispatch();
        }
    }

    /**
     * Referenced files not stored here, with the kinds they are referenced as and the instances that
     * wrote the references (most likely holders first).
     *
     * @return array<string, array{kinds: list<string>, sources: list<string>}>
     */
    public function missing(): array
    {
        $rows = [];
        $global = Setting::query()->where('key', BrandingSettings::SETTING)->first();
        if ($global !== null) {
            $rows[] = [BrandingSettings::global(), $global];
        }
        foreach (Site::withTrashed()->get() as $site) {
            $rows[] = [BrandingSettings::siteOverrides($site), $site];
        }
        $missing = [];
        foreach ($rows as [$values, $row]) {
            foreach ([BrandingAssets::LOGO, BrandingAssets::FAVICON] as $kind) {
                $asset = $values[$kind] ?? null;
                if (! BrandingAssets::valid($asset) || $this->assets->exists($asset)) {
                    continue;
                }
                $file = BrandingAssets::fileName($asset);
                $missing[$file]['kinds'][] = $kind;
                foreach ([self::writerOf((string) $row->version), $row->origin_instance_id] as $source) {
                    if (is_string($source) && $source !== '') {
                        $missing[$file]['sources'][] = $source;
                    }
                }
            }
        }

        return array_map(fn (array $m) => ['kinds' => array_values(array_unique($m['kinds'])),
            'sources' => array_values(array_unique($m['sources'] ?? []))], $missing);
    }

    /**
     * Fetch every missing file (files that failed recently are skipped unless forced).
     *
     * @return array<string, bool> file => fetched
     */
    public function fetchMissing(bool $force = false): array
    {
        $results = [];
        foreach ($this->missing() as $file => $info) {
            if (! $force && Cache::has(self::FAILED_KEY.$file)) {
                continue;
            }
            $results[$file] = $this->fetch($file, $info['kinds'], $info['sources']);
            if (! $results[$file]) {
                Cache::put(self::FAILED_KEY.$file, true, self::RETRY_SECONDS);
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $kinds
     * @param  list<string>  $sources  instance ids asked first (if direct peers)
     */
    public function fetch(string $file, array $kinds, array $sources = []): bool
    {
        $peers = $this->topology->directPeers()
            ->sortBy(fn (Instance $peer) => ($i = array_search($peer->id, $sources, true)) === false ? PHP_INT_MAX : $i)
            ->values();
        $max = max((int) config('portal.branding.logo_max_bytes'), (int) config('portal.branding.favicon_max_bytes'));
        foreach ($peers as $peer) {
            $exceeded = false;
            try {
                // curl aborts larger downloads early: CURLOPT_MAXFILESIZE by the announced length, the
                // progress callback while receiving (no Content-Length); the length is checked again below.
                $response = $this->client->to($peer, null, [CURLOPT_MAXFILESIZE => $max])
                    ->withOptions(self::downloadLimit($max, $exceeded))
                    ->accept('application/octet-stream')
                    ->get('/federation/branding/'.$file);
                if (! $response->successful()) {
                    continue;
                }
                $bytes = $response->body();
                if (strlen($bytes) > $max) {
                    throw new BrandingException('too_large');
                }
                $this->assets->import($file, $bytes, $kinds);
                Log::info('Branding file fetched from federation peer', ['file' => $file, 'peer' => $peer->id]);

                return true;
            } catch (BrandingException $e) {
                Log::warning('Branding file from federation peer rejected', ['file' => $file, 'peer' => $peer->id,
                    'reason' => $e->reason]);
            } catch (Throwable $e) {
                if ($exceeded) {
                    Log::warning('Branding file from federation peer rejected', ['file' => $file, 'peer' => $peer->id,
                        'reason' => 'too_large']);

                    continue;
                }
                Log::info('Branding file not fetched from federation peer', ['file' => $file, 'peer' => $peer->id,
                    'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return false;
    }

    /**
     * Guzzle options that abort a download once more than $max bytes arrived (the progress callback
     * returning true stops the transfer); $exceeded tells the caller why it failed.
     *
     * @return array{progress: \Closure}
     */
    public static function downloadLimit(int $max, ?bool &$exceeded): array
    {
        $exceeded = false;

        return ['progress' => function ($downloadTotal, $downloaded) use ($max, &$exceeded): bool {
            if ($downloaded > $max || $downloadTotal > $max) {
                $exceeded = true;
            }

            return $exceeded;
        }];
    }

    /** Instance id in an HLC version (<ms>.<counter>.<instance id>). */
    private static function writerOf(string $version): ?string
    {
        $parts = explode('.', $version);

        return count($parts) === 3 ? $parts[2] : null;
    }
}
