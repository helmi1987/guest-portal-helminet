<?php

namespace App\Support;

use App\Federation\FutureVersionException;
use App\Federation\Outbox;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hybrid logical clock used for the `version` column of replicated rows (CONCEPT.md §10.3).
 * Format: <13-digit unix ms>.<5-digit counter>.<instance ULID>, sortable as a string; the instance
 * id breaks ties. Persistent: every issued or received version is stored with its event in
 * `federation_events`, so the clock resumes from the highest stored version. now() is called inside
 * the write transaction (IMMEDIATE on SQLite, i.e. serialised across processes), so two processes of
 * one instance never issue the same version.
 */
class Hlc
{
    /** Five digits in the version string. */
    public const MAX_COUNTER = 99999;

    private int $lastMs = 0;

    private int $counter = 0;

    public function __construct(private LocalInstance $instance) {}

    public function now(): string
    {
        $this->observeStored();
        $ms = self::wallMs();

        if ($ms > $this->lastMs) {
            $this->lastMs = $ms;
            $this->counter = 0;
        } elseif ($this->counter >= self::MAX_COUNTER) {
            // Counter exhausted within one millisecond: move the logical time on by 1 ms.
            $this->lastMs++;
            $this->counter = 0;
        } else {
            $this->counter++;
        }

        return self::format($this->lastMs, $this->counter, $this->instance->id());
    }

    /**
     * Merge a remote version into the clock, so later local versions sort after it.
     *
     * @throws FutureVersionException when the version is further in the future than allowed (clock skew)
     */
    public function receive(string $version): void
    {
        [$ms, $counter] = self::parse($version);
        if ($ms > self::wallMs() + (int) config('portal.federation.max_future_ms', 300000)) {
            throw new FutureVersionException('HLC version too far in the future: '.$version);
        }
        $this->observe($ms, $counter);
    }

    /** @return array{0: int, 1: int, 2: string} unix ms, counter, instance id */
    public static function parse(string $version): array
    {
        if (! preg_match('/^(\d{13})\.(\d{5})\.([0-9A-Za-z]+)$/', $version, $m)) {
            throw new RuntimeException('Invalid HLC version: '.$version);
        }

        return [(int) $m[1], (int) $m[2], $m[3]];
    }

    public static function format(int $ms, int $counter, string $instanceId): string
    {
        return sprintf('%013d.%05d.%s', $ms, $counter, $instanceId);
    }

    /** Wall clock in unix ms (follows Carbon test time). */
    public static function wallMs(): int
    {
        return (int) now()->getTimestampMs();
    }

    private function observeStored(): void
    {
        $max = DB::table(Outbox::TABLE)->max('version');
        if (is_string($max) && $max !== '') {
            [$ms, $counter] = self::parse($max);
            $this->observe($ms, $counter);
        }
    }

    private function observe(int $ms, int $counter): void
    {
        if ($ms > $this->lastMs || ($ms === $this->lastMs && $counter > $this->counter)) {
            $this->lastMs = $ms;
            $this->counter = $counter;
        }
    }
}
