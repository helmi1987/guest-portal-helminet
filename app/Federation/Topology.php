<?php

namespace App\Federation;

use App\Models\FederationPeer;
use App\Models\Instance;
use App\Models\Setting;
use App\Support\LocalInstance;
use Illuminate\Support\Collection;

/**
 * Star topology of the federation (CONCEPT.md §10.1): the hub (setting federation_hub, set to the
 * first instance on the first pairing) connects to every instance; the other instances connect only
 * to the hub, which relays events and calls. Without a hub setting this instance acts as hub.
 */
class Topology
{
    public const HUB_SETTING = 'federation_hub';

    public const OFFLINE_SMS_ONLY_SETTING = 'federation_offline_sms_only';

    public function __construct(private LocalInstance $instance) {}

    public function hubId(): ?string
    {
        $hub = Setting::get(self::HUB_SETTING);

        return is_string($hub) && $hub !== '' ? $hub : null;
    }

    public function isHub(): bool
    {
        $hub = $this->hubId();

        return $hub === null || $hub === $this->instance->id();
    }

    public function hub(): ?Instance
    {
        $hub = $this->hubId();

        return $hub === null ? null : Instance::query()->find($hub);
    }

    /** Other (not removed) instances. */
    public function others(): Collection
    {
        return Instance::query()->whereKeyNot($this->instance->id())->orderBy('name')->get();
    }

    public function isFederated(): bool
    {
        return Instance::query()->whereKeyNot($this->instance->id())->exists();
    }

    /**
     * Instances this one talks to directly: the hub talks to all, the others only to the hub. Only
     * instances with federation URL and certificate pin.
     *
     * @return Collection<int, Instance>
     */
    public function directPeers(): Collection
    {
        $peers = $this->isHub() ? $this->others() : collect([$this->hub()])->filter();

        return $peers->filter(fn (Instance $i) => $i->id !== $this->instance->id() && $i->federation_url
            && PeerCertificate::isPin($i->cert_fingerprint))->values();
    }

    public function isDirectPeer(string $instanceId): bool
    {
        return $this->directPeers()->contains(fn (Instance $i) => $i->id === $instanceId);
    }

    /** Setting "offline: SMS only" (default on). */
    public function offlineSmsOnly(): bool
    {
        return (bool) Setting::get(self::OFFLINE_SMS_ONLY_SETTING, true);
    }

    /**
     * Hub unreachable as seen by a non-hub instance: no successful heartbeat for
     * portal.federation.hub_unreachable_after seconds (never on the hub or without federation).
     */
    public function hubUnreachable(): bool
    {
        if ($this->isHub() || ($hub = $this->hub()) === null) {
            return false;
        }
        $seen = FederationPeer::query()->find($hub->id)?->last_seen_at;

        return $seen === null || $seen->lt(now()->subSeconds((int) config('portal.federation.hub_unreachable_after', 120)));
    }

    /** Limited mode (CONCEPT.md §10.1): new logins only by SMS or site code. */
    public function limitedMode(): bool
    {
        return $this->offlineSmsOnly() && $this->hubUnreachable();
    }
}
