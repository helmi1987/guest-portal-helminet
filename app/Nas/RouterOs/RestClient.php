<?php

namespace App\Nas\RouterOs;

use App\Federation\PinningCurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * RouterOS REST API (service www-ssl, path /rest), print only: POST /rest/<menu>/print with
 * .proplist and .query. TLS: the server's public key must match the pin (CURLOPT_PINNEDPUBLICKEY,
 * curl aborts before sending the credentials); the certificate chain and host name are not checked
 * because the router is addressed by IP and the pin is the stronger check.
 */
final class RestClient implements RouterOsClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $pin,
        private readonly int $connectTimeout,
        private readonly int $timeout,
    ) {}

    public function print(string $menu, array $proplist, array $where = []): array
    {
        $segments = Menus::guard($menu, $proplist, $where);
        $body = ['.proplist' => array_values($proplist)];
        if ($where !== []) {
            $body['.query'] = count($where) > 1 ? [...$where, '#'.str_repeat('|', count($where) - 1)] : $where;
        }
        try {
            $response = $this->request()->post('/rest/'.implode('/', $segments).'/print', $body);
        } catch (ConnectionException $e) {
            throw self::connectionError($e->getMessage());
        }

        $status = $response->status();
        if ($status === 401) {
            throw new RouterOsException('auth', 'login refused (HTTP 401)');
        }
        if ($status === 403) {
            throw new RouterOsException('denied', 'not permitted (HTTP 403): the user group needs the policies read and rest-api');
        }
        if ($status >= 400) {
            $message = (string) ($response->json('detail') ?: $response->json('message') ?: 'HTTP '.$status);
            // Unknown menu (package not installed): "no such command" / "no such command prefix"
            throw new RouterOsException($status === 400 || $status === 404 ? 'menu' : 'protocol', mb_substr($message, 0, 200));
        }
        $rows = $response->json();
        if (! is_array($rows)) {
            throw new RouterOsException('protocol', 'unexpected answer (no JSON)');
        }
        // Single-item menus (e.g. /system resource) may answer with one object
        if ($rows !== [] && ! array_is_list($rows)) {
            $rows = [$rows];
        }

        return array_values(array_map(fn ($row) => is_array($row) ? array_map(fn ($v) => is_scalar($v) ? (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v) : '', $row) : [], $rows));
    }

    public function close(): void {}

    private function request(): PendingRequest
    {
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return Http::baseUrl("https://{$host}:{$this->port}")
            ->withBasicAuth($this->user, $this->password)
            ->acceptJson()
            ->asJson()
            ->withoutRedirecting()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout)
            // Trust from the pinned public key only (see class comment); never verify=false without the pin
            ->withOptions(['verify' => false])
            ->setHandler(new CurlHandler(['handle_factory' => new PinningCurlFactory($this->pin)]));
    }

    /** curl error codes: 28 timeout, 90 pinned key mismatch, 35/51/53-60 TLS, else connection. */
    private static function connectionError(string $message): RouterOsException
    {
        $code = preg_match('/cURL error (\d+)/', $message, $m) ? (int) $m[1] : 0;
        $detail = preg_replace('/\s*\(see https?:\/\/[^)]*\)/', '', $message);

        return match (true) {
            $code === 28 => new RouterOsException('timeout', $detail),
            $code === 90 => new RouterOsException('tls', 'the router certificate does not match the pinned key'),
            in_array($code, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83], true) => new RouterOsException('tls', $detail),
            default => new RouterOsException('connect', $detail),
        };
    }
}
