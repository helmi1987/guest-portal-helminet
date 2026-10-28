<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Services\GrantManager;
use App\Support\LocalInstance;
use App\Support\Mac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Endpoints called by FreeRADIUS rlm_rest (CONCEPT.md §5). Request body: rlm_rest JSON
 * ({"Attr": {"type": .., "value": [..]}}). rlm_rest maps HTTP 200 with attributes to "updated"
 * (Access-Accept in our virtual server), 401 to "reject", 204 to "ok".
 */
class RadiusController extends Controller
{
    public function authorizeAccess(Request $request, GrantManager $grants): JsonResponse
    {
        $nas = $this->nas($request);
        $username = (string) $this->attr($request, 'User-Name');
        $password = (string) $this->attr($request, 'User-Password');
        $mac = Mac::normalize($this->attr($request, 'Calling-Station-Id'));

        $reason = null;
        $reply = DB::transaction(function () use ($nas, $username, $password, $mac, $request, $grants, &$reason) {
            $token = RadiusToken::query()->where('username', $username)->lockForUpdate()->first();

            $reason = match (true) {
                $nas === null => 'unknown NAS',
                $nas->nas_identifier && $nas->nas_identifier !== $this->attr($request, 'NAS-Identifier') => 'NAS-Identifier mismatch',
                $token === null => 'unknown user',
                $token->used_at !== null => 'token already used',
                $token->expires_at->isPast() => 'token expired',
                ! hash_equals($token->password_hash, RadiusToken::hashPassword($password)) => 'wrong password',
                $mac === null || $mac !== $token->mac => 'MAC mismatch',
                $token->site_id !== $nas->site_id => 'site mismatch',
                default => null,
            };
            if ($reason !== null) {
                return null;
            }

            $grant = Grant::query()->with('siteLinks')->find($token->grant_id);
            if ($grant === null || ! $grant->isUsable() || ! $grant->allowsSite($nas->site_id)) {
                $reason = 'grant not usable';

                return null;
            }

            $device = $grants->bindDevice($grant, $mac, $token->guest_id);
            $token->update(['used_at' => now(), 'device_id' => $device->id]);

            return array_merge([
                'reply:Session-Timeout' => max(1, $grant->remainingSeconds()),
                'reply:Acct-Interim-Interval' => (int) config('portal.radius.interim_interval'),
            ], $nas->site->driver()->replyAttributes($grant));
        });

        if ($reply === null) {
            Log::info('RADIUS reject', ['reason' => $reason, 'nas' => $nas?->nas_ip, 'user' => $username]);

            return response()->json(['reply:Reply-Message' => 'Access denied'], 401);
        }

        return response()->json($reply);
    }

    public function accounting(Request $request): Response
    {
        $nas = $this->nas($request);
        $sessionId = (string) $this->attr($request, 'Acct-Session-Id');
        $status = strtolower((string) $this->attr($request, 'Acct-Status-Type'));
        $status = ['1' => 'start', '2' => 'stop', '3' => 'interim-update'][$status] ?? $status;

        if ($nas === null || $sessionId === '' || ! in_array($status, ['start', 'stop', 'interim-update', 'alive'], true)) {
            return response()->noContent();
        }

        DB::transaction(function () use ($request, $nas, $sessionId, $status) {
            $acct = RadiusAccounting::query()->where('nas_client_id', $nas->id)
                ->where('acct_session_id', $sessionId)->first() ?? $this->startSession($request, $nas, $sessionId);
            if ($acct === null) {
                return; // Not one of our sessions.
            }

            $acct->fill([
                'last_update_at' => now(),
                'bytes_in' => $this->octets($request, 'Input'),
                'bytes_out' => $this->octets($request, 'Output'),
            ]);
            if ($status === 'stop') {
                $acct->terminate_cause = $this->attr($request, 'Acct-Terminate-Cause');
                $acct->summary->update(['stopped_at' => now()]);
            }
            $acct->save();
        });

        return response()->noContent();
    }

    private function startSession(Request $request, NasClient $nas, string $sessionId): ?RadiusAccounting
    {
        $username = (string) $this->attr($request, 'User-Name');
        $token = RadiusToken::query()->where('username', $username)->whereNotNull('used_at')->first();
        if ($token === null) {
            return null;
        }

        $summary = SessionSummary::create([
            'grant_id' => $token->grant_id,
            'site_id' => $nas->site_id,
            'serving_instance_id' => app(LocalInstance::class)->id(),
            'mac' => Mac::normalize($this->attr($request, 'Calling-Station-Id')) ?? $token->mac,
            'ip' => $this->attr($request, 'Framed-IP-Address'),
            'started_at' => now(),
        ]);

        return new RadiusAccounting([
            'session_summary_id' => $summary->id,
            'nas_client_id' => $nas->id,
            'acct_session_id' => $sessionId,
            'username' => $username,
        ]);
    }

    /** NAS as identified by FreeRADIUS (client shortname "nas-<id>" from the rendered clients file). */
    private function nas(Request $request): ?NasClient
    {
        $short = (string) $this->attr($request, 'FreeRADIUS-Client-Shortname');

        return preg_match('/^nas-(\d+)$/', $short, $m) ? NasClient::with('site')->find((int) $m[1]) : null;
    }

    private function attr(Request $request, string $name): ?string
    {
        $value = $request->json($name);
        $value = is_array($value) ? ($value['value'][0] ?? null) : $value;

        return $value === null ? null : (string) $value;
    }

    private function octets(Request $request, string $dir): int
    {
        return (int) $this->attr($request, "Acct-$dir-Gigawords") * 4294967296
            + (int) $this->attr($request, "Acct-$dir-Octets");
    }
}
