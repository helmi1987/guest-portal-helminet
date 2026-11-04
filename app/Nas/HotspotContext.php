<?php

namespace App\Nas;

/**
 * Parameters passed by the router's captive-portal redirect. Untrusted UX hints only:
 * the binding MAC is the one RADIUS reports (CONCEPT.md §7.1). Controller-based drivers
 * (AuthorizesViaController) have no login URL; they carry the vendor fields instead: the access
 * point MAC, the SSID, the controller's site name and the redirect's timestamp (UniFi: ap, ssid,
 * the path's site, t). Missing keys in a stored context read as null.
 */
final readonly class HotspotContext
{
    public function __construct(
        public ?string $mac,
        public ?string $ip,
        public ?string $loginUrl,
        public ?string $origUrl,
        public ?string $ap = null,
        public ?string $ssid = null,
        public ?string $unifiSite = null,
        public ?int $timestamp = null,
    ) {}

    public static function fromArray(array $data): self
    {
        $timestamp = $data['timestamp'] ?? null;

        return new self($data['mac'] ?? null, $data['ip'] ?? null, $data['login_url'] ?? null, $data['orig_url'] ?? null,
            $data['ap'] ?? null, $data['ssid'] ?? null, $data['unifi_site'] ?? null, is_int($timestamp) ? $timestamp : null);
    }

    public function toArray(): array
    {
        $data = ['mac' => $this->mac, 'ip' => $this->ip, 'login_url' => $this->loginUrl, 'orig_url' => $this->origUrl];
        // Vendor fields only when set: contexts of the MikroTik driver keep their shape.
        foreach (['ap' => $this->ap, 'ssid' => $this->ssid, 'unifi_site' => $this->unifiSite, 'timestamp' => $this->timestamp] as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /** A MAC and a way to let the client out: the NAS login URL, or a controller site (controller drivers). */
    public function isComplete(): bool
    {
        return $this->mac !== null && ($this->loginUrl !== null || $this->unifiSite !== null);
    }
}
