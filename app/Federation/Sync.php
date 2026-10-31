<?php

namespace App\Federation;

use App\Models\FederationPeer;
use App\Models\Instance;
use App\Models\LocalSetting;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Replication transport (CONCEPT.md §10.2): heartbeat, pull and push with the directly connected
 * peers. Star topology: the hub pulls each instance's own events and pushes every origin an instance
 * lacks (relay); the other instances pull every origin from the hub and push their own events.
 * Clock skew above portal.federation.skew_pause_ms or a higher protocol of the peer pauses the
 * transport with that peer until an admin resumes it.
 */
class Sync
{
    /** Pages per origin and run (pull) or per peer and run (push). */
    private const MAX_PAGES = 20;

    public function __construct(
        private LocalInstance $instance,
        private Topology $topology,
        private PeerClient $client,
        private Inbox $inbox,
        private DataKey $keys,
        private Resync $resync,
    ) {}

    /** Heartbeat, pull and push for every direct peer. @return array<string, string> peer id => outcome */
    public function syncAll(): array
    {
        $outcomes = [];
        if ($this->removed()) {
            return ['-' => 'removed from the federation: re-pair first'];
        }
        foreach ($this->topology->directPeers() as $peer) {
            $state = $this->heartbeat($peer);
            if ($this->removed()) {
                // Replaced (e.g. as hub while down): no silent resumption with the remaining peers.
                return [$peer->id => 'removed from the federation: re-pair first'];
            }
            if ($state->last_error !== null || $state->isPaused()) {
                $outcomes[$peer->id] = $state->last_error ?? 'paused: '.$state->pause_reason;

                continue;
            }
            try {
                // Restore: own events back first (CONCEPT.md §10.3).
                $this->resync->checkPeer($peer, $state->origins ?? []);
                $recovered = Resync::required() ? $this->resync->recoverFrom($peer, $state) : 0;
                $pulled = $this->pull($peer, $state);
                $pushed = $this->push($peer, $state);
                $outcomes[$peer->id] = "ok (pulled $pulled, pushed $pushed".($recovered > 0 ? ", own events recovered $recovered" : '').')';
            } catch (Throwable $e) {
                $this->fail($state, $e);
                $outcomes[$peer->id] = $state->last_error;
            }
        }
        if (Resync::required()) {
            $outcomes['resync'] = $this->resync->completeIfDone($this->topology->directPeers()->pluck('id')->all())
                ? 'finished: writes allowed again' : 'waiting for own events from every direct peer (writes blocked)';
        }

        return $outcomes;
    }

    /** Push to every direct peer with a known state (push job). */
    public function pushAll(): void
    {
        if ($this->removed()) {
            return;
        }
        foreach ($this->topology->directPeers() as $peer) {
            $state = FederationPeer::query()->find($peer->id);
            if ($state === null || $state->isPaused() || $state->acks === null) {
                continue; // first contact by the heartbeat of the next sync run
            }
            try {
                $this->push($peer, $state);
            } catch (Throwable $e) {
                $this->fail($state, $e);
            }
        }
    }

    /** GET /federation/status: reachability, clock skew, protocol, the peer's origins and cursors, data keys. */
    public function heartbeat(Instance $peer): FederationPeer
    {
        $state = FederationPeer::for($peer->id);
        $state->last_attempt_at = now();
        try {
            $sent = Hlc::wallMs();
            $response = $this->client->to($peer)->get('/federation/status');
            $received = Hlc::wallMs();
            $data = $response->json();
            if ($response->status() === 403 && is_array($data) && ($data['error'] ?? null) === 'removed') {
                // This instance was removed by the hub (e.g. replaced as hub while it was down).
                if (LocalSetting::get(Pairing::REMOVED_SETTING) === null) {
                    LocalSetting::put(Pairing::REMOVED_SETTING, now()->toIso8601String());
                    Log::error('Federation: this instance was removed from the federation', ['peer' => $peer->id]);
                }
            }
            if (! $response->successful() || ! is_array($data)) {
                throw new \RuntimeException('status '.$response->status().' '.mb_substr((string) ($data['error'] ?? ''), 0, 100));
            }
            if (($data['instance_id'] ?? null) !== $peer->id) {
                throw new \RuntimeException('unexpected instance id '.mb_substr((string) ($data['instance_id'] ?? ''), 0, 30));
            }
        } catch (Throwable $e) {
            $this->fail($state, $e);

            return $state;
        }

        $skew = (int) ($data['time_ms'] ?? 0) - intdiv($sent + $received, 2);
        $protocol = (int) ($data['protocol'] ?? 0);
        $state->fill([
            'last_seen_at' => now(),
            'last_error' => null,
            'clock_skew_ms' => $skew,
            'protocol' => $protocol,
            'origins' => self::seqMap($data['origins'] ?? []),
            'acks' => self::seqMap($data['cursors'] ?? []),
        ]);
        if (! $state->isPaused()) {
            if ($protocol > Federation::FEDERATION_PROTOCOL) {
                $this->pause($state, "protocol $protocol > ".Federation::FEDERATION_PROTOCOL.' (update this instance)');
            } elseif (abs($skew) > (int) config('portal.federation.skew_pause_ms', 300000)) {
                $this->pause($state, 'clock skew '.round($skew / 1000).' s');
            }
        }
        if (abs($skew) > (int) config('portal.federation.skew_warn_ms', 30000)) {
            Log::warning('Federation clock skew', ['peer' => $peer->id, 'skew_ms' => $skew]);
        }
        $state->save();
        if (is_array($data['data_keys'] ?? null) && ! $state->isPaused()) {
            // Keys rotated elsewhere (peer removed) reach every instance with the heartbeats.
            $this->keys->importRing($data['data_keys']);
        }

        return $state;
    }

    /** Pull missing events from the peer. @return int events applied */
    public function pull(Instance $peer, FederationPeer $state): int
    {
        $local = $this->instance->id();
        $origins = $this->topology->isHub()
            ? array_intersect_key($state->origins ?? [], [$peer->id => true])
            : $state->origins ?? [];
        uksort($origins, fn ($a, $b) => [$a !== $peer->id, $a] <=> [$b !== $peer->id, $b]);

        $applied = 0;
        foreach ($origins as $origin => $max) {
            if ($origin === $local) {
                continue;
            }
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $after = (int) DB::table('peer_cursors')->where('origin', $origin)->value('last_seq');
                if ($after >= $max) {
                    break;
                }
                $response = $this->client->to($peer)->get('/federation/events', [
                    'origin' => $origin, 'after' => $after, 'limit' => (int) config('portal.federation.batch', 500)]);
                $events = $response->successful() ? $response->json('events') : null;
                if (! is_array($events)) {
                    throw new \RuntimeException('events of '.$origin.': status '.$response->status());
                }
                if ($events === []) {
                    break;
                }
                $result = $this->inbox->receive($events, $peer->id, $peer->id)[$origin] ?? null;
                $applied += $result?->applied ?? 0;
                if ($result === null || $result->status !== 'ok' || $result->lastSeq <= $after) {
                    break;
                }
            }
        }

        return $applied;
    }

    /** Push events the peer lacks (by its acknowledged cursors). @return int events sent */
    public function push(Instance $peer, FederationPeer $state): int
    {
        $local = $this->instance->id();
        $origins = $this->topology->isHub()
            ? DB::table(Outbox::TABLE)->distinct()->pluck('origin')->reject(fn ($o) => $o === $peer->id)->values()->all()
            : [$local];
        usort($origins, fn ($a, $b) => [$a !== $local, $a] <=> [$b !== $local, $b]);

        // Fair share per page: every origin gets its part, and an origin the peer does not take (gap,
        // missing parent, unknown key) is left out of the following pages instead of blocking the others.
        $limit = (int) config('portal.federation.batch', 500);
        $stalled = [];
        $sent = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $active = array_values(array_diff($origins, $stalled));
            $share = max(1, intdiv($limit, max(1, count($active))));
            $events = [];
            $sentPerOrigin = [];
            foreach ($active as $origin) {
                $after = $state->ack($origin) ?? 0;
                foreach (DB::table(Outbox::TABLE)->where('origin', $origin)->where('seq', '>', $after)
                    ->orderBy('seq')->limit($share)->get() as $event) {
                    $events[] = EventCodec::toWire($event);
                    $sentPerOrigin[$origin] = true;
                }
            }
            if ($events === []) {
                break;
            }
            $response = $this->client->to($peer)->post('/federation/events', [
                'protocol' => Federation::FEDERATION_PROTOCOL, 'events' => $events]);
            if ($response->status() === 409) {
                // The peer paused us (clock skew, protocol): nothing to do until it resumes.
                throw new \RuntimeException('refused by peer: '.mb_substr((string) $response->json('error'), 0, 150));
            }
            $cursors = $response->successful() ? $response->json('cursors') : null;
            if (! is_array($cursors)) {
                throw new \RuntimeException('push: status '.$response->status());
            }
            $before = $state->acks ?? [];
            $state->acks = array_merge($before, self::seqMap($cursors));
            $state->last_seen_at = now();
            $state->save();
            $sent += count($events);
            foreach (array_keys($sentPerOrigin) as $origin) {
                if (($state->acks[$origin] ?? null) === ($before[$origin] ?? null)) {
                    $stalled[] = $origin; // no progress: the peer pulls the rest later
                }
            }
        }

        return $sent;
    }

    private function removed(): bool
    {
        return LocalSetting::get(Pairing::REMOVED_SETTING) !== null;
    }

    private function pause(FederationPeer $state, string $reason): void
    {
        $state->paused_at = now();
        $state->pause_reason = mb_substr($reason, 0, 255);
        Log::error('Federation peer paused', ['peer' => $state->instance_id, 'reason' => $reason]);
    }

    private function fail(FederationPeer $state, Throwable $e): void
    {
        $state->last_error = mb_substr($e->getMessage(), 0, 255);
        $state->last_attempt_at = now();
        $state->save();
        Log::warning('Federation peer call failed', ['peer' => $state->instance_id, 'error' => $state->last_error]);
    }

    /** @return array<string, int> */
    private static function seqMap(mixed $map): array
    {
        $out = [];
        foreach (is_array($map) ? $map : [] as $origin => $seq) {
            if (is_string($origin) && preg_match('/^[0-9A-Za-z]{26}$/', $origin) && is_numeric($seq)) {
                $out[$origin] = (int) $seq;
            }
        }

        return $out;
    }
}
