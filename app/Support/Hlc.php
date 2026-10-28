<?php

namespace App\Support;

/**
 * Hybrid logical clock used for the `version` column of replicated rows.
 * Format: <13-digit unix ms>.<5-digit counter>.<instance ULID>, sortable as a string.
 * M1 keeps the state per process; receiving remote versions follows with federation (M7).
 */
class Hlc
{
    private int $lastMs = 0;

    private int $counter = 0;

    public function __construct(private LocalInstance $instance) {}

    public function now(): string
    {
        $ms = (int) floor(microtime(true) * 1000);

        if ($ms > $this->lastMs) {
            $this->lastMs = $ms;
            $this->counter = 0;
        } else {
            $this->counter++;
        }

        return sprintf('%013d.%05d.%s', $this->lastMs, $this->counter, $this->instance->id());
    }
}
