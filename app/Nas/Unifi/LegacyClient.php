<?php

namespace App\Nas\Unifi;

use App\Support\Mac;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

/**
 * Legacy (undocumented) UniFi controller API with a local account (docs/unifi-feasibility.md §2.1): cookie
 * login, then `/api/s/{site}/...` with the session cookie and the CSRF token. Two variants, detected by
 * trying both at login and remembered for this client (one request or command run):
 *
 * - standalone Network Application: `POST /api/login`, cookie `unifises` (+ `csrf_token`), paths `/api/...`;
 * - UniFi OS (consoles, UniFi OS Server): `POST /api/auth/login`, cookie `TOKEN` (JWT with `csrfToken`) and
 *   header `X-CSRF-Token`, Network paths under `/proxy/network/api/...`.
 *
 * Answers: `{"meta": {"rc": "ok"|"error", "msg": "api.err...."}, "data": [...]}`. The session is never kept
 * beyond this object.
 */
final class LegacyClient extends UnifiClient
{
    /** Path prefix of the Network application: '' (standalone) or '/proxy/network' (UniFi OS); null = not logged in. */
    private ?string $prefix = null;

    /** @var array<string, string> session cookies of the login */
    private array $cookies = [];

    private ?string $csrf = null;

    private ?array $site = null;

    public function version(): ?string
    {
        $version = $this->api('get', 'stat/sysinfo')[0]['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    public function sites(): array
    {
        $this->login();
        $rows = $this->data($this->request('get', $this->prefix.'/api/self/sites'));

        return array_values(array_map(fn ($row) => [
            'id' => (string) ($row['_id'] ?? ''),
            'name' => (string) ($row['desc'] ?? ''),
            'ref' => (string) ($row['name'] ?? ''),
        ], array_filter($rows, 'is_array')));
    }

    public function site(): array
    {
        if ($this->site !== null) {
            return $this->site;
        }
        $wanted = $this->config->unifiSite;
        foreach ($this->sites() as $site) {
            if (in_array($wanted, [$site['ref'], $site['name'], $site['id']], true)) {
                return $this->site = $site;
            }
        }
        throw $this->error('site_not_found', 'UniFi site not found on the controller: '.$wanted);
    }

    public function findClient(string $mac): ?array
    {
        try {
            $rows = $this->api('get', 'stat/sta/'.self::apiMac($mac));
        } catch (UnifiException $e) {
            // Unknown or offline MAC (unverified which of the two answers a controller gives).
            if (in_array($e->reason, ['not_found', 'unknown_station'], true)) {
                return null;
            }
            throw $e;
        }
        $row = is_array($rows[0] ?? null) ? $rows[0] : null;

        return $row === null ? null : self::normalise($row, $mac);
    }

    public function apMac(array $client): ?string
    {
        return $client['ap_mac'] ?? null;
    }

    public function authorize(array $client, int $minutes, ?string $apMac = null): array
    {
        $payload = ['cmd' => 'authorize-guest', 'mac' => self::apiMac($client['mac']), 'minutes' => max(1, min(1000000, $minutes))];
        if ($apMac !== null) {
            // "should result in faster authorization" (Art of WiFi client)
            $payload['ap_mac'] = self::apiMac($apMac);
        }
        $rows = $this->api('post', 'cmd/stamgr', $payload);
        $end = $rows[0]['end'] ?? null;

        return ['expires_at' => is_numeric($end) ? Carbon::createFromTimestamp((int) $end) : null, 'raw' => 'authorize-guest ok'];
    }

    public function unauthorize(string $mac): void
    {
        // By MAC: also sent when the client is not connected (whether the controller then ends the stored
        // authorization is unverified).
        $this->api('post', 'cmd/stamgr', ['cmd' => 'unauthorize-guest', 'mac' => self::apiMac($mac)]);
    }

    public function clientCount(): int
    {
        return count($this->api('get', 'stat/sta'));
    }

    /** Variant detected at login: "standalone" or "unifi_os"; null before the login. */
    public function variant(): ?string
    {
        return $this->prefix === null ? null : ($this->prefix === '' ? 'standalone' : 'unifi_os');
    }

    /** Call below /api/s/{site}/ and return its data rows. */
    private function api(string $method, string $path, array $payload = []): array
    {
        $this->login();
        $site = rawurlencode($this->site()['ref']);

        return $this->data($this->request($method, $this->prefix."/api/s/{$site}/".$path, $payload));
    }

    /** Login once per object: standalone first, then UniFi OS. */
    private function login(): void
    {
        if ($this->prefix !== null) {
            return;
        }
        if ($this->config->username === '' || $this->config->password === '') {
            throw $this->error('not_configured', 'no local account configured');
        }
        $body = ['username' => $this->config->username, 'password' => $this->config->password, 'remember' => false];
        $failure = null;
        foreach (['' => '/api/login', '/proxy/network' => '/api/auth/login'] as $prefix => $path) {
            $response = $this->send('POST', $path, ['json' => $body]);
            $status = $response->status();
            if ($status === 200 && ($response->json('meta.rc') ?? 'ok') === 'ok') {
                $this->prefix = $prefix;
                $this->cookies = self::cookies($response);
                $header = $response->header('X-CSRF-Token') ?: $response->header('X-Updated-CSRF-Token');
                $this->csrf = $header !== '' ? $header
                    : ($this->cookies['csrf_token'] ?? self::jwtCsrf($this->cookies['TOKEN'] ?? null));

                return;
            }
            // Standalone with wrong credentials: 400 api.err.Invalid; UniFi OS: 401/403. A 404 (or an
            // HTML page) means this variant does not exist here: try the other one.
            $msg = (string) ($response->json('meta.msg') ?? '');
            if ($msg === 'api.err.Invalid' || $msg === 'api.err.LoginRequired' || in_array($status, [401, 403], true)) {
                $failure = $this->error('auth', 'login refused ('.($msg !== '' ? $msg : 'HTTP '.$status).'): check user name and password, local account without 2FA', $status);
            } else {
                $failure ??= $this->error('protocol', 'no login endpoint found (HTTP '.$status.'): check the URL', $status);
            }
        }
        throw $failure;
    }

    private function request(string $method, string $path, array $payload = []): Response
    {
        $headers = ['Cookie' => implode('; ', array_map(fn ($k, $v) => $k.'='.$v, array_keys($this->cookies), $this->cookies))];
        if ($this->csrf !== null) {
            $headers['X-CSRF-Token'] = $this->csrf;
        }
        $response = $this->send(strtoupper($method), $path, $method === 'get' ? [] : ['json' => $payload], $headers);
        // UniFi OS may rotate the token.
        if (($updated = $response->header('X-Updated-CSRF-Token')) !== '') {
            $this->csrf = $updated;
        }

        return $response;
    }

    /** Data rows of an answer; meta.rc "error" and HTTP errors become UnifiException. */
    private function data(Response $response): array
    {
        $status = $response->status();
        $json = $response->json();
        $msg = (string) ($response->json('meta.msg') ?? '');
        if ($status < 400 && is_array($json) && ($json['meta']['rc'] ?? 'ok') === 'ok') {
            return is_array($json['data'] ?? null) ? array_values($json['data']) : [];
        }
        if (! is_array($json) && $status < 400) {
            throw $this->error('protocol', 'unexpected answer (no JSON): check the URL and the mode', $status);
        }
        throw match (true) {
            $msg === 'api.err.UnknownStation' => $this->error('unknown_station', 'client not known to the controller', $status),
            $msg === 'api.err.LoginRequired' || $status === 401 => $this->error('auth', 'session refused ('.($msg ?: 'HTTP 401').')', $status),
            $msg === 'api.err.NoPermission' || $status === 403 => $this->error('denied', 'not permitted ('.($msg ?: 'HTTP 403').'): the account needs a role that may authorize guests', $status),
            $msg === 'api.err.NoSiteContext' || $status === 404 => $this->error('not_found', 'not found ('.($msg ?: 'HTTP 404').')', $status),
            default => $this->error('controller', 'controller error ('.($msg !== '' ? $msg : 'HTTP '.$status).')', $status),
        };
    }

    /** @return array<string, string> name => value of the Set-Cookie headers */
    private static function cookies(Response $response): array
    {
        $cookies = [];
        foreach ((array) ($response->headers()['Set-Cookie'] ?? $response->headers()['set-cookie'] ?? []) as $line) {
            $pair = explode(';', (string) $line, 2)[0];
            if (str_contains($pair, '=')) {
                [$name, $value] = explode('=', $pair, 2);
                if (trim($name) !== '' && trim($value) !== '') {
                    $cookies[trim($name)] = trim($value);
                }
            }
        }

        return $cookies;
    }

    /** csrfToken claim of the UniFi OS TOKEN cookie (JWT payload, not verified: only echoed back). */
    private static function jwtCsrf(?string $jwt): ?string
    {
        $parts = $jwt === null ? [] : explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $token = is_array($payload) ? ($payload['csrfToken'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** stat/sta row as the normalised array. */
    private static function normalise(array $row, string $mac): array
    {
        return [
            'id' => Mac::normalize(is_string($row['mac'] ?? null) ? $row['mac'] : null) ?? $mac,
            'mac' => Mac::normalize(is_string($row['mac'] ?? null) ? $row['mac'] : null) ?? $mac,
            'connected' => true,
            'guest' => (bool) ($row['is_guest'] ?? false),
            'authorized' => (bool) ($row['authorized'] ?? false),
            // Wireless clients: ap_mac; wired clients have sw_mac and no AP.
            'ap_mac' => Mac::normalize(is_string($row['ap_mac'] ?? null) ? $row['ap_mac'] : null),
            'ip' => is_string($row['ip'] ?? null) ? $row['ip'] : null,
            // Direction of the counters unverified: tx = sent by the AP to the client (download), rx = upload.
            'bytes_in' => isset($row['rx_bytes']) ? (int) $row['rx_bytes'] : null,
            'bytes_out' => isset($row['tx_bytes']) ? (int) $row['tx_bytes'] : null,
            'expires_at' => null,
        ];
    }
}
