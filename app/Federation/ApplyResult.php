<?php

namespace App\Federation;

/**
 * Outcome of applying a batch of events of one origin.
 * status: ok (all processed), stopped (retry later: gap, missing parent, version too far ahead),
 * paused (origin paused until an admin resolves it: unknown table, higher protocol, invalid event),
 * own (events of this instance, ignored).
 */
final class ApplyResult
{
    public string $status = 'ok';

    public ?string $reason = null;

    public int $applied = 0;

    public int $ignored = 0;

    public int $dropped = 0;

    public int $skipped = 0;

    public int $lastSeq = 0;

    /** @var list<string> natural-key and uniqueness conflicts resolved */
    public array $conflicts = [];

    public function __construct(public readonly string $origin) {}

    public function stop(string $status, string $reason): void
    {
        $this->status = $status;
        $this->reason = $reason;
    }
}
