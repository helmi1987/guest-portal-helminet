<?php

namespace App\Nas\Unifi;

use App\Support\Mac;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Official UniFi Network API (OpenAPI 10.6.106 / 11.0.97, docs/unifi-feasibility.md §2.2): header X-API-KEY,
 * base `<url>/proxy/network/integration/v1`. Site lookup by name, internal reference ("default") or UUID;
 * clients by `filter=macAddress.eq('…')` (connected clients only); guest actions AUTHORIZE_GUEST_ACCESS /
 * UNAUTHORIZE_GUEST_ACCESS. Not verified against a real console (no hardware, see docs/unifi-setup.md).
 */
final class OfficialClient extends UnifiClient
{
    public const BASE = '/proxy/network/integration/v1';

    private ?array $site = null;

    public function version(): ?string
    {
        $version = $this->get('/info')->json('applicationVersion');

        return is_string($version) ? $version : null;
    }

    public function sites(): array
    {
        $rows = $this->get('/sites', ['limit' => 200])->json('data');

        return array_values(array_map(fn ($row) => [
            'id' => (string) ($row['id'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'ref' => (string) ($row['internalReference'] ?? ''),
        ], array_filter(is_array($rows) ? $rows : [], 'is_array')));
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
        $siteId = $this->site()['id'];
        $rows = $this->get("/sites/{$siteId}/clients", ['filter' => "macAddress.eq('".self::apiMac($mac)."')", 'limit' => 1])->json('data');
        $row = is_array($rows) && is_array($rows[0] ?? null) ? $rows[0] : null;
        if ($row === null || ! isset($row['id'])) {
            return null;
        }
        // Details carry access.authorization (expiresAt, usage) and uplinkDeviceId.
        $details = $this->get("/sites/{$siteId}/clients/".rawurlencode((string) $row['id']))->json();

        return self::normalise(is_array($details) ? $details + $row : $row, $mac);
    }

    public function apMac(array $client): ?string
    {
        if (($client['uplink_device_id'] ?? null) === null) {
            return null;
        }
        $siteId = $this->site()['id'];
        try {
            $mac = $this->get("/sites/{$siteId}/devices/".rawurlencode($client['uplink_device_id']))->json('macAddress');
        } catch (UnifiException $e) {
            if ($e->reason === 'not_found') {
                return null;
            }
            throw $e;
        }

        return Mac::normalize(is_string($mac) ? $mac : null);
    }

    public function authorize(array $client, int $minutes, ?string $apMac = null): array
    {
        $siteId = $this->site()['id'];
        $response = $this->call('post', "/sites/{$siteId}/clients/".rawurlencode($client['id']).'/actions',
            ['action' => 'AUTHORIZE_GUEST_ACCESS', 'timeLimitMinutes' => max(1, min(1000000, $minutes))]);
        $expires = $response->json('grantedAuthorization.expiresAt');

        return ['expires_at' => self::date($expires), 'raw' => 'AUTHORIZE_GUEST_ACCESS ok'];
    }

    public function unauthorize(string $mac): void
    {
        // The API addresses clients by id, and lists connected clients only: nothing to address otherwise.
        $client = $this->findClient($mac);
        if ($client === null) {
            return;
        }
        $siteId = $this->site()['id'];
        $this->call('post', "/sites/{$siteId}/clients/".rawurlencode($client['id']).'/actions', ['action' => 'UNAUTHORIZE_GUEST_ACCESS']);
    }

    public function clientCount(): int
    {
        $siteId = $this->site()['id'];

        return (int) $this->get("/sites/{$siteId}/clients", ['limit' => 1])->json('totalCount');
    }

    private function get(string $path, array $query = []): Response
    {
        return $this->call('get', $path, $query);
    }

    private function call(string $method, string $path, array $data = []): Response
    {
        $options = $method === 'get' ? ['query' => $data] : ['json' => $data];
        $response = $this->send(strtoupper($method), self::BASE.$path, $options, ['X-API-KEY' => $this->config->apiKey]);
        $status = $response->status();
        if ($status < 400) {
            if (! is_array($response->json())) {
                throw $this->error('protocol', 'unexpected answer (no JSON): check the URL and the mode', $status);
            }

            return $response;
        }
        // Error Message schema: statusCode, statusName, code, message.
        $detail = trim((string) ($response->json('code') ?? '').' '.(string) ($response->json('message') ?? ''));
        $detail = $detail !== '' ? $detail : 'HTTP '.$status;
        throw match (true) {
            $status === 401 => $this->error('auth', 'API key refused (HTTP 401): '.$detail, $status),
            $status === 403 => $this->error('denied', 'not permitted (HTTP 403): '.$detail, $status),
            $status === 404 => $this->error('not_found', 'not found (HTTP 404): '.$detail, $status),
            default => $this->error('controller', 'controller error (HTTP '.$status.'): '.$detail, $status),
        };
    }

    /** Client details (Wireless/Wired client details with Guest access details) as the normalised array. */
    private static function normalise(array $row, string $mac): array
    {
        $access = is_array($row['access'] ?? null) ? $row['access'] : [];
        $usage = is_array($access['authorization']['usage'] ?? null) ? $access['authorization']['usage'] : [];

        return [
            'id' => (string) $row['id'],
            'mac' => Mac::normalize(is_string($row['macAddress'] ?? null) ? $row['macAddress'] : null) ?? $mac,
            'connected' => true,
            'guest' => ($access['type'] ?? null) === 'GUEST',
            'authorized' => (bool) ($access['authorized'] ?? false),
            'uplink_device_id' => is_string($row['uplinkDeviceId'] ?? null) ? $row['uplinkDeviceId'] : null,
            'ap_mac' => null,
            'ip' => is_string($row['ipAddress'] ?? null) ? $row['ipAddress'] : null,
            // rx = download (the request's rxRateLimitKbps is the "download rate limit"), tx = upload.
            'bytes_in' => isset($usage['txBytes']) ? (int) $usage['txBytes'] : null,
            'bytes_out' => isset($usage['rxBytes']) ? (int) $usage['rxBytes'] : null,
            'expires_at' => self::date($access['authorization']['expiresAt'] ?? null),
        ];
    }

    private static function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
