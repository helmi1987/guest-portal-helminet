<?php

namespace App\Federation;

use App\Jobs\DisconnectSession;
use App\Models\FederationPeer;
use App\Models\Instance;
use App\Models\SessionSummary;
use App\Support\LocalInstance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Sessions of other instances (CONCEPT.md §10.4, decision: session summaries stay local): the admin UI
 * asks the serving instances live. Star topology: an instance asks the hub with relay=1, the hub
 * answers with its own sessions and those of its other peers; a disconnect goes to the serving
 * instance, through the hub when it is not a direct peer.
 */
class RemoteSessions
{
    /** Grants per query. */
    public const MAX_GRANTS = 100;

    private const DOWN_KEY = 'federation:live-down:';

    /** Seconds an unreachable peer is skipped by live queries. */
    private const DOWN_SECONDS = 30;

    public function __construct(private LocalInstance $instance, private Topology $topology, private PeerClient $client) {}

    /**
     * Sessions of these grants served by other instances.
     *
     * @param  list<string>  $grantIds
     * @return array{sessions: Collection<int, SessionSummary>, unreachable: list<string>} unreachable: instance names
     */
    public function fetch(array $grantIds, ?string $exceptPeer = null, bool $relay = true): array
    {
        $sessions = collect();
        $unreachable = [];
        $grantIds = array_slice(array_values(array_unique($grantIds)), 0, self::MAX_GRANTS);
        if ($grantIds === []) {
            return ['sessions' => $sessions, 'unreachable' => $unreachable];
        }
        $isHub = $this->topology->isHub();
        foreach ($this->topology->directPeers() as $peer) {
            if ($peer->id === $exceptPeer) {
                continue;
            }
            // A peer that just failed is not asked again for a while: the list stays fast.
            $state = FederationPeer::query()->find($peer->id);
            if (Cache::has(self::DOWN_KEY.$peer->id)
                || ($state?->last_error !== null && $state->last_attempt_at?->gt(now()->subSeconds(self::DOWN_SECONDS)))) {
                $unreachable[] = $peer->name;

                continue;
            }
            try {
                $response = $this->client->to($peer, (int) config('portal.federation.live_timeout', 4))
                    ->get('/federation/sessions', ['grant_ids' => $grantIds, 'relay' => ! $isHub && $relay ? 1 : 0]);
                if (! $response->successful() || ! is_array($response->json('sessions'))) {
                    throw new RuntimeException('status '.$response->status());
                }
                foreach ($response->json('sessions') as $data) {
                    if (is_array($data) && ($data['serving_instance_id'] ?? null) !== $this->instance->id()) {
                        $sessions->push(self::hydrate($data));
                    }
                }
                $unreachable = array_merge($unreachable, array_filter((array) $response->json('unreachable'), 'is_string'));
            } catch (Throwable) {
                Cache::put(self::DOWN_KEY.$peer->id, true, self::DOWN_SECONDS);
                $unreachable[] = $peer->name;
            }
        }

        return ['sessions' => $sessions->unique('id')->values(), 'unreachable' => array_values(array_unique($unreachable))];
    }

    /** @param  list<string>  $grantIds */
    public function local(array $grantIds): array
    {
        return SessionSummary::query()->whereIn('grant_id', array_slice($grantIds, 0, self::MAX_GRANTS))
            ->withSum('accounting', 'bytes_in')->withSum('accounting', 'bytes_out')->withMax('accounting', 'last_update_at')
            ->latest('started_at')->limit(500)->get()
            ->map(fn (SessionSummary $s) => [
                'id' => $s->id,
                'grant_id' => $s->grant_id,
                'site_id' => $s->site_id,
                'serving_instance_id' => $s->serving_instance_id ?? $this->instance->id(),
                'mac' => $s->mac,
                'ip' => $s->ip,
                'started_at' => $s->started_at?->toIso8601String(),
                'stopped_at' => $s->stopped_at?->toIso8601String(),
                'bytes_in' => $s->accounting_sum_bytes_in === null ? null : (int) $s->accounting_sum_bytes_in,
                'bytes_out' => $s->accounting_sum_bytes_out === null ? null : (int) $s->accounting_sum_bytes_out,
                'last_update_at' => $s->accounting_max_last_update_at === null ? null
                    : Carbon::parse($s->accounting_max_last_update_at)->toIso8601String(),
            ])->all();
    }

    /**
     * Disconnect a session at its serving instance (directly or through the hub).
     *
     * @return string sent | not_online | not_found
     *
     * @throws RuntimeException instance unreachable or unknown
     */
    public function disconnect(string $servingId, string $sessionId): string
    {
        if ($servingId === $this->instance->id()) {
            return $this->disconnectLocal($sessionId);
        }
        $via = $this->topology->isDirectPeer($servingId) ? Instance::query()->find($servingId)
            : ($this->topology->isHub() ? null : $this->topology->hub());
        if ($via === null || ! $this->topology->isDirectPeer($via->id)) {
            throw new RuntimeException('no route to instance');
        }
        $response = $this->client->to($via, (int) config('portal.federation.live_timeout', 4))
            ->post('/federation/sessions/'.rawurlencode($sessionId).'/disconnect', ['instance' => $servingId]);
        $status = $response->json('status');
        if (! $response->successful() || ! in_array($status, ['sent', 'not_online', 'not_found'], true)) {
            throw new RuntimeException('status '.$response->status().' '.mb_substr((string) $response->json('error'), 0, 100));
        }

        return $status;
    }

    public function disconnectLocal(string $sessionId): string
    {
        $session = SessionSummary::query()->find($sessionId);
        if ($session === null) {
            return 'not_found';
        }
        if ($session->stopped_at !== null) {
            return 'not_online';
        }
        $session->update(['kicked_at' => now()]);
        DisconnectSession::dispatch($session->id);

        return 'sent';
    }

    /** Remote session as a (not persisted) model for the views. */
    private static function hydrate(array $data): SessionSummary
    {
        $session = new SessionSummary;
        $session->forceFill([
            'id' => (string) ($data['id'] ?? ''),
            'grant_id' => $data['grant_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'serving_instance_id' => $data['serving_instance_id'] ?? null,
            'mac' => $data['mac'] ?? null,
            'ip' => $data['ip'] ?? null,
            'started_at' => isset($data['started_at']) ? Carbon::parse($data['started_at']) : null,
            'stopped_at' => isset($data['stopped_at']) ? Carbon::parse($data['stopped_at']) : null,
        ]);
        $session->setAttribute('accounting_sum_bytes_in', $data['bytes_in'] ?? null);
        $session->setAttribute('accounting_sum_bytes_out', $data['bytes_out'] ?? null);
        $session->setAttribute('accounting_max_last_update_at', $data['last_update_at'] ?? null);
        $session->setAttribute('remote', true);

        return $session;
    }
}
