<?php

namespace App\Http\Controllers\Internal;

use App\Enums\GrantStatus;
use App\Http\Controllers\Controller;
use App\Jobs\DisconnectSession;
use App\Models\Device;
use App\Models\Grant;
use App\Models\MacWhitelist;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\TermsAcceptance;
use App\Services\GrantManager;
use App\Support\LocalInstance;
use App\Support\Mac;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
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

        // RouterOS MAC authentication (login-by=mac): User-Name is a MAC. Portal tokens never are.
        if (($userMac = Mac::strict($username)) !== null) {
            return $this->authorizeWhitelist($request, $nas, $userMac, $mac);
        }

        $reason = null;
        // Regular closure (not fn): an arrow function would capture $reason by value and lose the reject reason.
        $authorize = function () use ($nas, $username, $password, $mac, $request, $grants, &$reason) {
            return DB::transaction(function () use ($nas, $username, $password, $mac, $request, $grants, &$reason) {
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
                    // One-time credentials are local: only the site's home instance accepts them (CONCEPT.md §10.4).
                    ! $nas->site->isServedHere() => 'site served by another instance',
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
        };

        // Serialise per grant: concurrent requests with different MACs must not both bind a device.
        $grantId = RadiusToken::query()->where('username', $username)->value('grant_id');
        $reply = $grantId ? Cache::lock('grant-bind:'.$grantId, 15)->block(5, $authorize) : $authorize();

        if ($reply === null) {
            Log::info('RADIUS reject', ['reason' => $reason, 'nas' => $nas?->nas_ip, 'user' => $username]);

            return response()->json(['reply:Reply-Message' => 'Access denied'], 401);
        }

        return response()->json($reply);
    }

    /**
     * MAC authentication (CONCEPT.md §5, §10.4): accepted only when User-Name equals the
     * Calling-Station-Id the router reports, and either a valid MAC whitelist entry allows the NAS's
     * site (no Session-Timeout unless the entry expires) or the MAC is a roaming device: bound to a
     * running grant that allows this site, logged in at another site (Session-Timeout = remaining
     * time). The password is not checked (mac-auth-mode=mac-as-username sends none).
     */
    private function authorizeWhitelist(Request $request, ?NasClient $nas, string $userMac, ?string $mac): JsonResponse
    {
        $reason = match (true) {
            $nas === null => 'unknown NAS',
            $nas->nas_identifier && $nas->nas_identifier !== $this->attr($request, 'NAS-Identifier') => 'NAS-Identifier mismatch',
            $mac === null || $mac !== $userMac => 'User-Name is not the Calling-Station-Id',
            default => null,
        };
        $entry = $reason === null ? MacWhitelist::findValidCached($mac, $nas->site_id) : null;
        $roaming = $reason === null && $entry === null ? $this->roamingGrant($mac, $nas) : null;
        if ($roaming !== null) {
            Log::info('RADIUS roaming device accepted', ['grant' => $roaming->id, 'nas' => $nas->nas_ip, 'mac' => $mac]);

            $reply = ['reply:Acct-Interim-Interval' => (int) config('portal.radius.interim_interval')];
            if (($remaining = $roaming->remainingSeconds()) !== null) {
                $reply['reply:Session-Timeout'] = max(1, $remaining);
            }

            return response()->json(array_merge($reply, $nas->site->driver()->replyAttributes($roaming)));
        }

        if ($entry === null) {
            // Every unknown device triggers a MAC authentication first: debug level only.
            Log::debug('RADIUS MAC reject', ['reason' => $reason ?? 'not whitelisted', 'nas' => $nas?->nas_ip, 'mac' => $userMac]);

            return response()->json(['reply:Reply-Message' => 'Access denied'], 401);
        }

        $reply = ['reply:Acct-Interim-Interval' => (int) config('portal.radius.interim_interval')];
        if (($remaining = $entry->remainingSeconds()) !== null) {
            $reply['reply:Session-Timeout'] = max(1, $remaining);
        }

        return response()->json($reply);
    }

    /**
     * Roaming (CONCEPT.md §10.4): running grant (logged in, time left, not revoked) whose active
     * device is this MAC, allowed at the NAS's site and logged in (terms accepted) at another site.
     * At its own site the device logs in through the portal as before.
     */
    private function roamingGrant(string $mac, NasClient $nas): ?Grant
    {
        $grantIds = Device::query()->where('mac', $mac)->whereNull('unbound_at')->pluck('grant_id');
        foreach (Grant::query()->with('siteLinks')->whereIn('id', $grantIds)->withStatus(GrantStatus::Active)->get() as $grant) {
            if (! $grant->allowsSite($nas->site_id) || $grant->activeDevice()?->mac !== $mac) {
                continue;
            }
            $loginSite = TermsAcceptance::query()->where('grant_id', $grant->id)->where('mac', $mac)
                ->latest('accepted_at')->value('site_id');
            // An admin disconnected the device at this site: no automatic login again until the grant
            // changes (e.g. extended); a portal login by the guest still works.
            $kicked = SessionSummary::query()->where('grant_id', $grant->id)->where('mac', $mac)
                ->where('site_id', $nas->site_id)->where('kicked_at', '>=', $grant->updated_at)->exists();
            if ($loginSite !== null && $loginSite !== $nas->site_id && ! $kicked) {
                return $grant;
            }
        }

        return null;
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
            return $this->startWhitelistSession($request, $nas, $sessionId, $username);
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

    /**
     * Session of a MAC authentication: User-Name is the MAC and equals Calling-Station-Id. A MAC
     * whitelist entry (no grant), else a roaming device (session of its grant).
     * Without a valid entry (deactivated, deleted or expired since the Accept) the session is still
     * recorded, linked to the newest entry of the MAC if any, and disconnected after commit.
     */
    private function startWhitelistSession(Request $request, NasClient $nas, string $sessionId, string $username): ?RadiusAccounting
    {
        $mac = Mac::strict($username);
        if ($mac === null || $mac !== Mac::normalize($this->attr($request, 'Calling-Station-Id'))) {
            return null;
        }
        $entry = MacWhitelist::findValid($mac, $nas->site_id);
        if ($entry === null && ($grant = $this->roamingGrant($mac, $nas)) !== null) {
            // Roaming device: a session of its grant (enforced and disconnected like any grant session).
            $summary = SessionSummary::create([
                'grant_id' => $grant->id,
                'site_id' => $nas->site_id,
                'serving_instance_id' => app(LocalInstance::class)->id(),
                'mac' => $mac,
                'ip' => $this->attr($request, 'Framed-IP-Address'),
                'started_at' => now(),
            ]);

            return new RadiusAccounting(['session_summary_id' => $summary->id, 'nas_client_id' => $nas->id,
                'acct_session_id' => $sessionId, 'username' => $username]);
        }
        $valid = $entry !== null;
        $entry ??= MacWhitelist::withTrashed()->where('mac', $mac)->orderByDesc('version')->first();

        $summary = SessionSummary::create([
            'mac_whitelist_id' => $entry?->id,
            'site_id' => $nas->site_id,
            'serving_instance_id' => app(LocalInstance::class)->id(),
            'mac' => $mac,
            'ip' => $this->attr($request, 'Framed-IP-Address'),
            'started_at' => now(),
        ]);
        if (! $valid) {
            Log::warning('RADIUS whitelist session without valid entry, disconnecting', ['nas' => $nas->nas_ip, 'mac' => $mac]);
            DisconnectSession::dispatch($summary->id)->afterCommit();
        }

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

        $nas = preg_match('/^nas-([0-9a-z]{26})$/', $short, $m) ? NasClient::with('site')->find($m[1]) : null;
        // Routers of sites served by another instance are not ours (their copy is for recovery only).
        if ($nas !== null && ! $nas->isServedHere()) {
            $nas = null;
        }
        // FreeRADIUS has checked source IP and secret: record the contact (setup assistant status).
        $nas?->markSeen();

        return $nas;
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
