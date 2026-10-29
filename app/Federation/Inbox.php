<?php

namespace App\Federation;

use App\Jobs\PushEvents;
use App\Models\Instance;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Entry for remote events (push and pull, CONCEPT.md §10.2): checks the Ed25519 signature of every
 * event with its origin's public key before the apply engine sees it. Unsigned or invalid events pause
 * the origin (the valid events before them are applied). An origin whose key is not known yet waits
 * (normally its registration arrives with the hub's events); an origin introduces its key with its own
 * instance row only when no key is known. Events of removed instances are not accepted.
 */
class Inbox
{
    public function __construct(private LocalInstance $instance, private Applier $applier, private Topology $topology) {}

    /**
     * @param  iterable<array<string, mixed>>  $events  wire events (any origins)
     * @param  ?string  $first  origin to apply first (the sender: its events register new instances)
     * @return array<string, ApplyResult> per origin
     */
    public function receive(iterable $events, ?string $first = null): array
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
            $results[$origin] = $result = $this->applyVerified((string) $origin, $list);
            $applied += $result->applied;
        }
        if ($applied > 0 && $this->topology->isHub()) {
            // Relay: the other instances get these events right away.
            PushEvents::dispatchIfFederated();
        }

        return $results;
    }

    /** @param  list<array<string, mixed>>  $events */
    private function applyVerified(string $origin, array $events): ApplyResult
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
            $eventKey = $key ?? $this->introducedKey($origin, $wire);
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
        if ($failure !== null && $result->status === 'ok') {
            $this->applier->pauseOrigin($origin, $failure);
            $result->stop('paused', $failure);
            Log::warning('Federation event rejected', ['origin' => $origin, 'reason' => $failure]);
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
