<?php

namespace App\Tls;

use App\Models\Site;
use App\Support\Audit;
use App\Support\Redact;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Pulls a certificate bundle from the configured source, validates it and writes certificate and
 * key atomically (temp file + rename in the same folder). Unchanged content is not rewritten.
 * cert-watch (s6) picks up the files and reloads nginx; routers fetch the router certificate.
 */
class CertificatePuller
{
    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const FAILED = 'failed';

    /**
     * Pulls every target with a configured source.
     *
     * @return array<string, string> target => result
     */
    public function pullAll(): array
    {
        $results = [];
        foreach (CertificateSources::TARGETS as $target) {
            if (CertificateSources::get($target)['type'] !== 'none') {
                try {
                    $results[$target] = $this->pull($target);
                } catch (CertificateException) {
                    $results[$target] = self::FAILED;
                }
            }
        }

        return $results;
    }

    /** @throws CertificateException (message safe to show; status and log already written) */
    public function pull(string $target): string
    {
        $source = CertificateSources::get($target);
        $apiKey = CertificateSources::apiKey($target);
        try {
            if ($source['type'] === 'none') {
                throw new CertificateException('no certificate source configured');
            }
            $bundle = CertificateBundle::parse($this->fetch($source, $apiKey));
            $info = $bundle->info();
            $warning = $this->checkNames($target, $info);
            $result = $this->install($target, $bundle) ? self::UPDATED : self::UNCHANGED;
        } catch (Throwable $e) {
            $message = Redact::secrets($e instanceof CertificateException ? $e->getMessage() : 'unexpected error: '.$e->getMessage(),
                [...self::secrets($apiKey), ...CertificateSources::urlSecrets($source['url'])]);
            CertificateSources::putStatus($target, self::FAILED, $message);
            Log::warning('Certificate pull failed', ['target' => $target, 'type' => $source['type'], 'error' => $message]);

            throw new CertificateException($message);
        }

        CertificateSources::putStatus($target, $result, null, $info->fingerprint, $warning);
        if ($warning !== null) {
            Log::warning('Certificate installed with warning', ['target' => $target, 'warning' => $warning]);
        }
        if ($result === self::UPDATED) {
            Log::info('Certificate updated', ['target' => $target, 'fingerprint' => $info->fingerprint,
                'expires' => date('c', $info->validTo)]);
            Audit::log('certificate.updated', null, ['target' => $target, 'subject' => $info->subject,
                'names' => $info->names, 'expires' => date('c', $info->validTo), 'fingerprint' => $info->fingerprint]);
        }

        return $result;
    }

    /**
     * Portal: the certificate must cover the portal host name (PORTAL_HOSTNAME, else the APP_URL
     * host), otherwise it is rejected. Router: names vary (wildcards), so only a warning for hotspot
     * host names of the sites that the certificate does not cover.
     */
    private function checkNames(string $target, CertificateInfo $info): ?string
    {
        if ($target === 'portal') {
            $host = CertificateSources::portalHost();
            if ($host !== null && ! $info->covers($host)) {
                throw new CertificateException("certificate does not cover the portal host name $host (names: "
                    .(implode(', ', $info->names) ?: $info->subject).')');
            }

            return null;
        }
        $uncovered = Site::query()->get()->flatMap(fn (Site $site) => (array) ($site->settings['hotspot_hosts'] ?? []))
            ->filter(fn ($host) => is_string($host) && $host !== '' && ! $info->covers($host))->unique()->values()->all();

        return $uncovered === [] ? null : 'not covered: '.implode(', ', $uncovered);
    }

    /** HTTP GET with TLS verification (system CAs or the configured CA PEM). */
    private function fetch(array $source, ?string $apiKey): string
    {
        $url = (string) CertificateSources::downloadUrl($source);
        $headers = match ($source['type']) {
            'certwarden' => ['X-API-Key' => (string) $apiKey],
            'url' => $apiKey ? ['Authorization' => 'Bearer '.$apiKey] : [],
        };

        $caFile = null;
        try {
            // Redirects are not followed (a 3xx is an error): the API key must not reach another host.
            $options = ['allow_redirects' => false];
            if (! empty($source['ca_pem'])) {
                $caFile = tempnam(sys_get_temp_dir(), 'portal-ca-');
                if ($caFile === false || file_put_contents($caFile, $source['ca_pem']) === false) {
                    throw new CertificateException('CA certificate cannot be prepared');
                }
                $options['verify'] = $caFile;
            }
            $response = Http::withOptions($options)->withHeaders($headers)
                ->accept('application/x-pem-file, application/pem-certificate-chain, text/plain, */*')
                ->timeout((int) config('portal.cert_pull.timeout'))
                ->get($url)->throw();
        } catch (RequestException $e) {
            throw new CertificateException('HTTP '.$e->response->status().self::statusHint($e->response->status()));
        } catch (ConnectionException) {
            throw new CertificateException('connection failed (host unreachable, timeout or TLS verification)');
        } finally {
            if (is_string($caFile)) {
                @unlink($caFile);
            }
        }

        if ($response->redirect() || $response->status() !== 200) {
            throw new CertificateException('HTTP '.$response->status().($response->redirect() ? ' (redirects are not followed)' : ''));
        }
        $body = $response->body();
        if (strlen($body) > (int) config('portal.cert_pull.max_bytes')) {
            throw new CertificateException('response too large');
        }

        return $body;
    }

    /** Writes both files when their content differs; returns whether anything was written. */
    private function install(string $target, CertificateBundle $bundle): bool
    {
        $paths = CertificateSources::paths($target);
        if (@file_get_contents($paths['cert']) === $bundle->certPem && @file_get_contents($paths['key']) === $bundle->keyPem) {
            return false;
        }

        $dir = dirname($paths['cert']);
        if (! is_dir($dir) && ! @mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new CertificateException('certificate folder cannot be created');
        }
        $tmpCert = $this->writeTemp($dir, $bundle->certPem, 0644);
        $tmpKey = $this->writeTemp(dirname($paths['key']), $bundle->keyPem, 0600);
        // Rename both right after each other; cert-watch checks that the pair matches.
        if (! @rename($tmpKey, $paths['key']) || ! @rename($tmpCert, $paths['cert'])) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            throw new CertificateException('certificate files cannot be written (folder permissions?)');
        }

        return true;
    }

    private function writeTemp(string $dir, string $content, int $mode): string
    {
        $umask = umask(0077);
        try {
            $tmp = @tempnam($dir, '.tmp-');
            if ($tmp === false || realpath(dirname($tmp)) !== realpath($dir) || file_put_contents($tmp, $content) !== strlen($content)) {
                if (is_string($tmp)) {
                    @unlink($tmp);
                }
                throw new CertificateException('certificate files cannot be written (folder permissions?)');
            }
        } finally {
            umask($umask);
        }
        if (! @chmod($tmp, $mode)) {
            @unlink($tmp);
            throw new RuntimeException('cannot set file mode');
        }

        return $tmp;
    }

    /** Short English hint for the log and status (the admin UI adds a translated one). */
    private static function statusHint(int $status): string
    {
        return match ($status) {
            404 => ' (check server URL / certificate name)',
            401, 403 => ' (check API keys / order)',
            default => '',
        };
    }

    /** API key and its CertWarden parts, for masking. */
    private static function secrets(?string $apiKey): array
    {
        return $apiKey === null ? [] : [$apiKey, ...explode('.', $apiKey)];
    }
}
