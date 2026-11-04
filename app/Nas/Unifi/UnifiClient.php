<?php

namespace App\Nas\Unifi;

use App\Federation\PinningCurlFactory;
use App\Support\Redact;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * UniFi Network controller client (CONCEPT.md §5.1, decision 2026-11-04, v0.27.0, experimental). Two modes
 * behind one interface: OfficialClient (Network API with an API key, `/proxy/network/integration/v1`) and
 * LegacyClient (cookie login with a local account, `/api/...`, on UniFi OS under `/proxy/network`).
 *
 * Shared: 10 s timeout (5 s to connect), redirects are errors, one retry after 2 s on HTTP 429, TLS either
 * verified against the system CAs, pinned to the controller's public key (sha256//..., like the RouterOS
 * REST client; then the chain and host name are not checked) or, when the admin switched verification off,
 * not checked at all. Errors become UnifiException with redacted messages.
 *
 * Normalised client array (both modes): id (controller id; legacy: the MAC), mac, connected, guest,
 * authorized, ap_mac (null when unknown), ip, bytes_in (from the client, upload), bytes_out (to the client,
 * download), expires_at (Carbon or null).
 */
abstract class UnifiClient
{
    public const TIMEOUT_S = 10;

    public const CONNECT_TIMEOUT_S = 5;

    public function __construct(protected readonly UnifiConfig $config) {}

    public static function make(UnifiConfig $config): self
    {
        if ($config->url === '') {
            throw new UnifiException('not_configured', 'no controller URL');
        }

        return $config->mode === UnifiSettings::MODE_LEGACY ? new LegacyClient($config) : new OfficialClient($config);
    }

    /** Controller application version (e.g. "10.0.162"), null when not reported. */
    abstract public function version(): ?string;

    /**
     * Sites of the controller.
     *
     * @return list<array{id: string, name: string, ref: string}> ref = short name of the legacy API ("default")
     */
    abstract public function sites(): array;

    /** Connected client by MAC (normalised array, see class comment), null when not connected/unknown. */
    abstract public function findClient(string $mac): ?array;

    /** AP MAC of a client (may need another call), null when unknown. */
    abstract public function apMac(array $client): ?string;

    /**
     * Authorize a guest for $minutes (1 to 1,000,000).
     *
     * @return array{expires_at: ?Carbon, raw: string}
     */
    abstract public function authorize(array $client, int $minutes, ?string $apMac = null): array;

    /**
     * End the authorization of a MAC. Official API: only a connected client can be addressed (no-op
     * otherwise); legacy API: by MAC, also when not connected.
     */
    abstract public function unauthorize(string $mac): void;

    /** Number of connected clients of the configured site. */
    abstract public function clientCount(): int;

    /** The configured site, resolved (official: UUID by name or internal reference; legacy: short name). */
    abstract public function site(): array;

    /** Options of the HTTP client for TLS (exposed for tests: verify flag and pin). */
    public function tlsOptions(): array
    {
        if ($this->config->tlsPin !== '') {
            return ['verify' => false, 'pin' => $this->config->tlsPin];
        }

        return ['verify' => $this->config->verifyTls];
    }

    protected function http(): PendingRequest
    {
        $tls = $this->tlsOptions();
        $request = Http::baseUrl($this->config->url)
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout(self::CONNECT_TIMEOUT_S)
            ->timeout(self::TIMEOUT_S)
            ->withOptions(['verify' => $tls['verify']]);
        if (isset($tls['pin'])) {
            // Trust from the pinned public key only: curl aborts before sending anything when it differs.
            $request->setHandler(new CurlHandler(['handle_factory' => new PinningCurlFactory($tls['pin'])]));
        }

        return $request;
    }

    /** One call with a single retry after 2 s on HTTP 429; transport errors become UnifiException. */
    protected function send(string $method, string $path, array $options = [], array $headers = []): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->http()->withHeaders($headers)->send($method, $path, $options);
            } catch (ConnectionException $e) {
                throw $this->connectionError($e->getMessage());
            }
            if ($response->status() !== 429 || $attempt >= 2) {
                break;
            }
            Sleep::sleep(2);
        }
        if ($response->status() === 429) {
            throw new UnifiException('rate_limited', 'rate limited by the controller (HTTP 429)', 429);
        }
        if ($response->status() >= 300 && $response->status() < 400) {
            throw new UnifiException('protocol', 'unexpected redirect (HTTP '.$response->status().'): check the URL and the mode', $response->status());
        }

        return $response;
    }

    protected function error(string $reason, string $message, ?int $status = null): UnifiException
    {
        return new UnifiException($reason, mb_substr(Redact::secrets($message, $this->config->secrets()), 0, 300), $status);
    }

    /** curl error codes: 28 timeout, 90 pinned key mismatch, 35/51/53-60 TLS, else connection. */
    private function connectionError(string $message): UnifiException
    {
        $code = preg_match('/cURL error (\d+)/', $message, $m) ? (int) $m[1] : 0;
        $detail = (string) preg_replace('/\s*\(see https?:\/\/[^)]*\)/', '', $message);

        return match (true) {
            $code === 28 => $this->error('timeout', $detail),
            $code === 90 => $this->error('tls', 'the controller certificate does not match the pinned key'),
            in_array($code, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83], true) => $this->error('tls', $detail),
            default => $this->error('connect', $detail),
        };
    }

    /** Lower-case MAC with colons, as both APIs write it. */
    protected static function apiMac(string $mac): string
    {
        return strtolower($mac);
    }
}
