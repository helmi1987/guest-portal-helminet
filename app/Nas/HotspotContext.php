<?php

namespace App\Nas;

/**
 * Parameters passed by the router's captive-portal redirect. Untrusted UX hints only:
 * the binding MAC is the one RADIUS reports (CONCEPT.md §7.1).
 */
final readonly class HotspotContext
{
    public function __construct(
        public ?string $mac,
        public ?string $ip,
        public ?string $loginUrl,
        public ?string $origUrl,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self($data['mac'] ?? null, $data['ip'] ?? null, $data['login_url'] ?? null, $data['orig_url'] ?? null);
    }

    public function toArray(): array
    {
        return ['mac' => $this->mac, 'ip' => $this->ip, 'login_url' => $this->loginUrl, 'orig_url' => $this->origUrl];
    }

    public function isComplete(): bool
    {
        return $this->mac !== null && $this->loginUrl !== null;
    }
}
