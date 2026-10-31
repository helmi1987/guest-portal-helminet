<?php

namespace App\Nas\RouterOs;

use App\Federation\PeerCertificate;

/**
 * Opens TLS connections to RouterOS api-ssl. Peer verification is done by the caller against the pin
 * (ApiSslClient checks it before sending anything), so the chain and host name are not checked here:
 * the router is addressed by IP and its certificate is the one the portal manages.
 */
class ApiConnector
{
    /** @throws RouterOsException */
    public function open(string $host, int $port, int $connectTimeout, int $timeout): ApiConnection
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled' => false,
        ]]);
        $target = 'tls://'.(str_contains($host, ':') ? '['.$host.']' : $host).':'.$port;
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client($target, $errno, $error, $connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            $last = error_get_last()['message'] ?? '';
            $tls = str_contains($last, 'SSL') || str_contains($error, 'SSL') || str_contains($last, 'crypto');
            $timedOut = $errno === 110 || str_contains(strtolower($error), 'timed out');
            throw new RouterOsException($timedOut ? 'timeout' : ($tls ? 'tls' : 'connect'), trim("{$error} ({$errno})"));
        }
        stream_set_timeout($stream, $timeout);
        $cert = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
        $pem = null;
        if ($cert !== null) {
            openssl_x509_export($cert, $pem);
        }

        return new class($stream, PeerCertificate::pin($pem)) implements ApiConnection
        {
            /** @param resource $stream */
            public function __construct(private $stream, private readonly ?string $pin) {}

            public function peerPin(): ?string
            {
                return $this->pin;
            }

            public function write(string $bytes): void
            {
                while ($bytes !== '') {
                    $n = @fwrite($this->stream, $bytes);
                    if ($n === false || $n === 0) {
                        throw new RouterOsException($this->timedOut() ? 'timeout' : 'connect', 'connection closed while sending');
                    }
                    $bytes = substr($bytes, $n);
                }
            }

            public function read(int $length): string
            {
                $data = '';
                while (strlen($data) < $length) {
                    $chunk = @fread($this->stream, $length - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        if ($this->timedOut()) {
                            throw new RouterOsException('timeout', 'no answer from the router');
                        }
                        if (feof($this->stream)) {
                            throw new RouterOsException('connect', 'connection closed by the router');
                        }

                        continue;
                    }
                    $data .= $chunk;
                }

                return $data;
            }

            public function close(): void
            {
                if (is_resource($this->stream)) {
                    @fclose($this->stream);
                }
            }

            private function timedOut(): bool
            {
                return (bool) (stream_get_meta_data($this->stream)['timed_out'] ?? false);
            }
        };
    }
}
