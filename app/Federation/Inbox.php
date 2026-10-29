<?php

namespace App\Federation;

use App\Jobs\PushEvents;
use App\Models\FederationPeer;
use App\Models\Instance;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entry for remote events (push and pull, CONCEPT.md §10.2): checks the Ed25519 signature of every
 * event with its origin's public key before the apply engine sees it. An unsigned, invalid or
 * malformed event is dropped together with the rest of that origin's batch (the valid events before
 * it are applied) and the SENDER peer is paused: it relayed or produced the bad event, the origin
 * itself is not blocked for others. An origin whose key is not known yet waits (its registration
 * arrives with the hub's events). Only without a configured hub (single instance, tests) may an origin
 * introduce its key with its own instance row. Events of removed instances are not accepted.
 */
class Inbox
{
    public function __construct(private LocalInstance $instance, private Applier $applier, private Topology $topology) {}

    /**
     * @param  iterable<array<string, mixed>>  $events  wire events (any origins)
     * @param  ?string  $first  origin to apply first (the sender: its events register new instances)
     * @return array<string, ApplyResult> per origin
     */
    public function receive(iterable $events, ?string $first = null, ?string $sender = null): array
    {
        $byOrigin = [];
        foreach ($events as $event) {
            $event = (array) $event;
            $origin = $event['origin'] ?? null;
            if (is_string($origin) && $origin !== $this->instance->id()) {
                $byOrigin[$origin][] = $event;
            }
        }
        uksort($byOrigin, fn ($a, $b) => [$a !== $first, $a] <=> [$b !== $first, $b]);

        $results = [];
        $applied = 0;
        foreach ($byOrigin as $origin => $list) {
            $results[$origin] = $result = $this->applyVerified((string) $origin, $list, $sender);
            $applied += $result->applied;
        }
        if ($applied > 0 && $this->topology->isHub()) {
            // Relay: the other instances get these events right away.
            PushEvents::dispatchIfFederated();
        }

        return $results;
    }

    /** @param  list<array<string, mixed>>  $events */
    private function applyVerified(string $origin, array $events, ?string $sender): ApplyResult
    {
        usort($events, fn ($a, $b) => (int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0));
        $last = (int) DB::table('peer_cursors')->where('origin', $origin)->value('last_seq');
        $instance = Instance::withTrashed()->find($origin);
        if ($instance?->trashed()) {
            $result = new ApplyResult($origin);
            $result->stop('stopped', 'origin removed from the federation');

            return $result;
        }
        $key = $instance?->signing_public_key;

        $verified = [];
        $failure = null;
        foreach ($events as $event) {
            if ((int) ($event['seq'] ?? 0) <= $last) {
                continue; // already applied, not checked again
            }
            try {
                $wire = EventCodec::toWire($event);
            } catch (Throwable $e) {
                $failure = 'malformed event: '.$e->getMessage();
                break;
            }
            $eventKey = $key ?? ($this->topology->hubId() === null ? $this->introducedKey($origin, $wire) : null);
            if ($eventKey === null) {
                break; // key unknown yet: wait
            }
            if (! EventCodec::verify($wire, $eventKey)) {
                $failure = ($wire['sig'] === null ? 'unsigned' : 'invalid signature').' event at seq '.$wire['seq'];
                break;
            }
            $key = $eventKey;
            $verified[] = $wire;
        }

        $result = $verified === [] ? $this->unchanged($origin, $last) : $this->applier->apply($origin, $verified);
        if ($failure !== null) {
            Log::warning('Federation event rejected', ['origin' => $origin, 'sender' => $sender, 'reason' => $failure]);
            if ($result->status === 'ok') {
                $result->stop('stopped', $failure.' (dropped)');
            }
            if ($sender !== null) {
                $state = FederationPeer::for($sender);
                if (! $state->isPaused()) {
                    $state->fill(['paused_at' => now(), 'pause_reason' => mb_substr("sent $failure of origin $origin", 0, 255)])->save();
                    Log::error('Federation peer paused', ['peer' => $sender, 'reason' => $state->pause_reason]);
                }
            }
        } elseif ($verified === [] && $failure === null && count($events) > 0 && (int) end($events)['seq'] > $last) {
            $result->stop('stopped', 'signing key of the origin unknown');
        }

        return $result;
    }

    /** Public key of an origin's own instance row event (self-introduction of an unknown origin). */
    private function introducedKey(string $origin, array $wire): ?string
    {
        if ($wire['table'] !== 'instances' || $wire['record_id'] !== $origin) {
            return null;
        }
        $payload = json_decode($wire['payload'], true);
        $key = is_array($payload) ? ($payload['signing_public_key'] ?? null) : null;

        return SigningKey::isPublicKey($key) ? $key : null;
    }

    private function unchanged(string $origin, int $last): ApplyResult
    {
        $result = new ApplyResult($origin);
        $result->lastSeq = $last;
        $paused = DB::table('peer_cursors')->where('origin', $origin)->whereNotNull('paused_at')->value('pause_reason');
        if ($paused !== null) {
            $result->stop('paused', (string) $paused);
        }

        return $result;
    }
}
