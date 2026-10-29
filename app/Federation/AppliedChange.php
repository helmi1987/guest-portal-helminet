<?php

namespace App\Federation;

/**
 * A replicated row written by the apply engine, passed to the table hooks after commit.
 * reason: "event" (state of a remote event) or "conflict" (local row changed by a conflict rule,
 * e.g. an older device unbound or a duplicate slug renamed).
 */
final class AppliedChange
{
    /**
     * @param  array<string, mixed>|null  $before  row before the change (null: new row)
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly string $table,
        public readonly string $id,
        public readonly ?array $before,
        public readonly array $after,
        public readonly string $origin,
        public readonly string $reason = 'event',
    ) {}
}
