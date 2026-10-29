<?php

namespace App\Services;

use App\Enums\ExpiryMode;
use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Jobs\DisconnectSession;
use App\Models\Device;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\RadiusToken;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Grant lifecycle: create, redeem, bind, edit, revoke (CONCEPT.md §5, §7.2 A, §8).
 */
class GrantManager
{
    public function __construct(private CodeGenerator $codes) {}

    /** @param array{duration_s:int, expiry_mode:ExpiryMode|string, valid_from?:?Carbon, redeem_until?:?Carbon, label?:?string, note?:?string, max_extensions?:?int, rate_limit?:?string, site_ids?:array} $data */
    public function createCode(array $data, ?User $by = null): Grant
    {
        return DB::transaction(function () use ($data, $by) {
            $mode = $data['expiry_mode'] instanceof ExpiryMode ? $data['expiry_mode'] : ExpiryMode::from($data['expiry_mode']);
            $grant = Grant::create([
                'type' => GrantType::Code,
                'code' => $this->codes->generate(),
                'label' => $data['label'] ?? null,
                'note' => $data['note'] ?? null,
                'duration_s' => $data['duration_s'],
                'expiry_mode' => $mode,
                'valid_from' => $data['valid_from'] ?? null,
                'redeem_until' => $data['redeem_until'] ?? null,
                'expires_at' => $mode === ExpiryMode::Creation ? now()->addSeconds($data['duration_s']) : null,
                'max_extensions' => $data['max_extensions'] ?? null,
                'rate_limit' => $data['rate_limit'] ?? null,
                'created_by' => $by?->id,
            ]);
            $grant->syncSites($data['site_ids'] ?? []);

            return $grant;
        });
    }

    /** Grant for an approved request: time runs from approval, restricted to the request's site. */
    public function createApprovalGrant(Site $site, int $durationS, string $label, ?User $by = null): Grant
    {
        return $this->createTimedGrant(GrantType::Approval, $site, $durationS, $label, $by);
    }

    /** Grant whose time runs from now (approval, verified SMS code), restricted to one site. */
    public function createTimedGrant(GrantType $type, Site $site, int $durationS, string $label, ?User $by = null): Grant
    {
        return DB::transaction(function () use ($type, $site, $durationS, $label, $by) {
            $grant = Grant::create([
                'type' => $type,
                'label' => $label,
                'duration_s' => $durationS,
                'expiry_mode' => ExpiryMode::Creation,
                'expires_at' => now()->addSeconds($durationS),
                'created_by' => $by?->id,
            ]);
            $grant->syncSites([$site->id]);

            return $grant;
        });
    }

    /** Usable code grant for this site; duplicates (federation) prefer the unredeemed one. */
    public function findByCode(string $code, Site $site): ?Grant
    {
        return Grant::query()->with('siteLinks')
            ->where('type', GrantType::Code)->where('code', $code)->whereNull('revoked_at')
            ->get()
            ->filter(fn (Grant $g) => $g->isUsable() && $g->allowsSite($site->id))
            ->sortBy(fn (Grant $g) => $g->status() === GrantStatus::Unused ? 0 : 1)
            ->first();
    }

    /**
     * Start the grant on first redemption and issue one-time RADIUS credentials.
     *
     * @return array{0: RadiusToken, 1: string} token and clear-text password
     */
    public function issueToken(Grant $grant, Guest $guest, Site $site, string $mac): array
    {
        return DB::transaction(function () use ($grant, $guest, $site, $mac) {
            if ($grant->first_login_at === null) {
                $grant->first_login_at = now();
                if ($grant->expires_at === null) {
                    $grant->expires_at = now()->addSeconds($grant->duration_s);
                }
                $grant->save();
            }

            $password = Str::random(24);
            $token = RadiusToken::create([
                'grant_id' => $grant->id,
                'guest_id' => $guest->id,
                'username' => 'g'.Str::lower(Str::random(15)),
                'password_hash' => RadiusToken::hashPassword($password),
                'mac' => $mac,
                'site_id' => $site->id,
                'expires_at' => now()->addSeconds(config('portal.radius.token_ttl')),
            ]);

            return [$token, $password];
        });
    }

    /** Bind the grant to the MAC reported by RADIUS; a new MAC unbinds and disconnects the old one. */
    public function bindDevice(Grant $grant, string $mac, ?string $guestId): Device
    {
        $current = $grant->activeDevice();
        if ($current !== null && $current->mac === $mac) {
            return $current;
        }

        if ($current !== null) {
            $current->update(['unbound_at' => now()]);
        }
        $this->disconnectOpenSessions($grant, exceptMac: $mac);

        return $grant->devices()->create(['guest_id' => $guestId, 'mac' => $mac, 'bound_at' => now()]);
    }

    /** Add (or with a negative value remove) time. Before the first login the duration changes. */
    public function extend(Grant $grant, int $seconds): void
    {
        if ($grant->expires_at === null) {
            $grant->duration_s = max(60, $grant->duration_s + $seconds);
        } else {
            $grant->expires_at = $grant->expires_at->copy()->addSeconds($seconds);
        }
        if ($seconds > 0) {
            $grant->extensions_used++;
        }
        $this->saveAndEnforce($grant);
    }

    public function setExpiry(Grant $grant, Carbon $expiresAt): void
    {
        $grant->expires_at = $expiresAt;
        $this->saveAndEnforce($grant);
    }

    public function revoke(Grant $grant): void
    {
        $grant->revoked_at ??= now();
        $this->saveAndEnforce($grant);
    }

    /** Disconnect sessions of the grant served by this instance (except one MAC, e.g. after rebind). */
    public function disconnectOpenSessions(Grant $grant, ?string $exceptMac = null): int
    {
        $sessions = $grant->sessions()->whereNull('stopped_at')
            ->when($exceptMac, fn ($q) => $q->where('mac', '!=', $exceptMac))
            ->get();

        foreach ($sessions as $session) {
            DisconnectSession::dispatch($session->id)->afterCommit();
        }

        return $sessions->count();
    }

    /**
     * Ended grants disconnect immediately. A shortened but still running grant is disconnected by the
     * scheduler when it expires, since the router keeps the Session-Timeout of the original login.
     */
    private function saveAndEnforce(Grant $grant): void
    {
        $grant->save();
        if (! $grant->isUsable()) {
            $this->disconnectOpenSessions($grant);
        }
    }
}
