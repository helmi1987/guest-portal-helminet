<?php

namespace App\Federation;

use App\Models\Instance;
use App\Support\LocalInstance;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * HTTP client for calls to other instances (CONCEPT.md §10.2): client certificate of this instance
 * (mTLS), the peer's public key pinned (CURLOPT_PINNEDPUBLICKEY; the self-signed certificate is not
 * checked against a CA), no redirects, short timeouts.
 */
class PeerClient
{
    public function __construct(private LocalInstance $instance) {}

    /** @param  array<int, mixed>  $curl  extra curl options set on the handle (e.g. CURLOPT_MAXFILESIZE) */
    public function to(Instance $peer, ?int $timeout = null, array $curl = []): PendingRequest
    {
        if (! $peer->federation_url || ! PeerCertificate::isPin($peer->cert_fingerprint)) {
            throw new InvalidArgumentException("Instance {$peer->id} has no federation URL or pin.");
        }

        return $this->pinned($peer->federation_url, $peer->cert_fingerprint, $timeout, $curl);
    }

    /**
     * Request to a URL with a known pin (pairing, before the peer is registered).
     *
     * @param  array<int, mixed>  $curl
     */
    public function pinned(string $url, string $pin, ?int $timeout = null, array $curl = []): PendingRequest
    {
        if (! PeerCertificate::isPin($pin) || ! preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('Federation URL must use https and the pin sha256//<base64>.');
        }
        // The certificate is self-signed: trust comes from the pinned public key only.
        $options = ['verify' => false];
        if (is_readable(PeerCertificate::certPath()) && is_readable(PeerCertificate::keyPath())) {
            $options['cert'] = PeerCertificate::certPath();
            $options['ssl_key'] = PeerCertificate::keyPath();
        }

        return Http::baseUrl(rtrim($url, '/'))
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout((int) config('portal.federation.connect_timeout', 3))
            ->timeout($timeout ?? (int) config('portal.federation.timeout', 10))
            ->withHeaders([
                'X-Federation-Instance' => $this->instance->id(),
                'X-Federation-Protocol' => (string) Federation::FEDERATION_PROTOCOL,
            ])
            ->withOptions($options)
            ->setHandler(new CurlHandler(['handle_factory' => new PinningCurlFactory($pin, $curl)]));
    }
}
