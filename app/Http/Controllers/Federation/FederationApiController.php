<?php

namespace App\Http\Controllers\Federation;

use App\Federation\ApplyResult;
use App\Federation\DataKey;
use App\Federation\EventCodec;
use App\Federation\Federation;
use App\Federation\Inbox;
use App\Federation\Outbox;
use App\Federation\Pairing;
use App\Federation\PairingException;
use App\Federation\RemoteSessions;
use App\Federation\SigningKey;
use App\Federation\Topology;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateFederationPeer;
use App\Models\FederationPeer;
use App\Models\Instance;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Federation API endpoints called by other instances (CONCEPT.md §10.2).
 */
class FederationApiController extends Controller
{
    /**
     * Heartbeat: identity, clock, protocol, highest stored sequence per origin, applied cursors (the
     * caller's acknowledgements) and the data key ring (rotations reach every instance).
     */
    public function status(Request $request, LocalInstance $instance, DataKey $keys): JsonResponse
    {
        if ($refused = $this->refusePaused($request)) {
            return $refused;
        }

        return response()->json([
            'instance_id' => $instance->id(),
            'protocol' => Federation::FEDERATION_PROTOCOL,
            'time_ms' => Hlc::wallMs(),
            'origins' => DB::table(Outbox::TABLE)->groupBy('origin')->selectRaw('origin, max(seq) as seq')
                ->pluck('seq', 'origin')->map(fn ($seq) => (int) $seq),
            'cursors' => DB::table('peer_cursors')->pluck('last_seq', 'origin')->map(fn ($seq) => (int) $seq)
                ->put($instance->id(), (int) DB::table(Outbox::TABLE)->where('origin', $instance->id())->max('seq')),
            'data_keys' => $keys->export(),
        ]);
    }

    /** Stored events of one origin (own and relayed) after a sequence number. */
    public function events(Request $request): JsonResponse
    {
        if ($refused = $this->refusePaused($request)) {
            return $refused;
        }
        $data = $request->validate([
            'origin' => ['required', 'string', 'size:26'],
            'after' => ['required', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ]);
        $limit = min((int) ($data['limit'] ?? 500), (int) config('portal.federation.batch', 500));
        $events = DB::table(Outbox::TABLE)->where('origin', $data['origin'])->where('seq', '>', (int) $data['after'])
            ->orderBy('seq')->limit($limit)->get()->map(fn ($e) => EventCodec::toWire($e));

        return response()->json(['events' => $events]);
    }

    /** Pushed events: signatures checked, applied per origin; answers the applied cursors. */
    public function receive(Request $request, Inbox $inbox): JsonResponse
    {
        if ($refused = $this->refusePaused($request)) {
            return $refused;
        }
        $data = $request->validate([
            'protocol' => ['required', 'integer'],
            'events' => ['present', 'array', 'max:'.(int) config('portal.federation.batch', 500)],
            'events.*' => ['array'],
        ]);
        $peer = $this->peer($request);
        if ((int) $data['protocol'] > Federation::FEDERATION_PROTOCOL) {
            $reason = "protocol {$data['protocol']} > ".Federation::FEDERATION_PROTOCOL.' (update this instance)';
            FederationPeer::for($peer->id)->fill(['paused_at' => now(), 'pause_reason' => $reason])->save();
            Log::error('Federation peer paused', ['peer' => $peer->id, 'reason' => $reason]);

            return response()->json(['error' => $reason], 409);
        }

        $results = $inbox->receive((array) $request->input('events'), $peer->id, $peer->id);

        return response()->json([
            'cursors' => collect($results)->map(fn (ApplyResult $r) => $r->lastSeq),
            'results' => collect($results)->map(fn (ApplyResult $r) => ['status' => $r->status, 'reason' => $r->reason,
                'applied' => $r->applied]),
        ]);
    }

    /**
     * Sessions of grants served here, for the admin UI of another instance (session summaries are
     * local). relay=1 (asked by a non-hub instance): the hub adds the sessions of its other peers.
     */
    public function sessions(Request $request, RemoteSessions $remote, Topology $topology): JsonResponse
    {
        $data = $request->validate([
            'grant_ids' => ['required', 'array', 'max:'.RemoteSessions::MAX_GRANTS],
            'grant_ids.*' => ['string', 'size:26'],
            'relay' => ['nullable', 'boolean'],
        ]);
        $sessions = $remote->local($data['grant_ids']);
        $unreachable = [];
        if ($request->boolean('relay') && $topology->isHub()) {
            $relayed = $remote->fetch($data['grant_ids'], exceptPeer: $this->peer($request)->id, relay: false);
            $unreachable = $relayed['unreachable'];
            foreach ($relayed['sessions'] as $session) {
                $sessions[] = array_merge($session->only(['id', 'grant_id', 'site_id', 'serving_instance_id', 'mac', 'ip']), [
                    'started_at' => $session->started_at?->toIso8601String(),
                    'stopped_at' => $session->stopped_at?->toIso8601String(),
                    'bytes_in' => $session->accounting_sum_bytes_in,
                    'bytes_out' => $session->accounting_sum_bytes_out,
                    'last_update_at' => $session->accounting_max_last_update_at,
                ]);
            }
        }

        return response()->json(['sessions' => $sessions, 'unreachable' => $unreachable]);
    }

    /**
     * Disconnect a session served here; the hub forwards a request for one of its peers (relay).
     */
    public function disconnect(Request $request, string $session, RemoteSessions $remote, Topology $topology, LocalInstance $instance): JsonResponse
    {
        $target = (string) $request->validate(['instance' => ['required', 'string', 'size:26']])['instance'];
        if ($target === $instance->id()) {
            $status = $remote->disconnectLocal($session);
            Log::info('Federation remote disconnect', ['session' => $session, 'by' => $this->peer($request)->id, 'status' => $status]);

            return response()->json(['status' => $status]);
        }
        if (! $topology->isHub() || ! $topology->isDirectPeer($target)) {
            return response()->json(['error' => 'unknown instance'], 404);
        }
        try {
            return response()->json(['status' => $remote->disconnect($target, $session)]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'instance unreachable: '.mb_substr($e->getMessage(), 0, 100)], 502);
        }
    }

    private function peer(Request $request): Instance
    {
        return $request->attributes->get(AuthenticateFederationPeer::PEER);
    }

    /** A peer paused here (clock skew, protocol) gets no data and cannot push until an admin resumes it. */
    private function refusePaused(Request $request): ?JsonResponse
    {
        $state = FederationPeer::query()->find($this->peer($request)->id);

        return $state?->isPaused() ? response()->json(['error' => 'paused: '.$state->pause_reason], 409) : null;
    }

    /** Pairing: register a fresh instance presenting a valid join token (CONCEPT.md §10.2). */
    public function join(Request $request, Pairing $pairing): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'protocol' => ['required', 'integer'],
            'instance' => ['required', 'array'],
            'instance.id' => ['required', 'string', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/i'],
            'instance.name' => ['required', 'string', 'max:100'],
            'instance.federation_url' => ['required', 'url:https', 'max:255'],
            'instance.admin_url' => ['nullable', 'url:https,http', 'max:255'],
            'instance.signing_public_key' => ['required', 'string', 'max:64'],
        ]);
        if (! SigningKey::isPublicKey($data['instance']['signing_public_key'])) {
            return response()->json(['error' => 'invalid signing key'], 422);
        }

        try {
            $response = $pairing->acceptJoin($data, (string) $request->attributes->get(AuthenticateFederationPeer::PIN));
        } catch (PairingException $e) {
            Log::warning('Federation join refused', ['reason' => $e->getMessage(), 'ip' => $request->ip()]);

            return response()->json(['error' => $e->getMessage()], $e->status);
        }
        Log::info('Federation instance joined', ['instance' => $data['instance']['id'], 'name' => $data['instance']['name']]);

        return response()->json($response);
    }
}
