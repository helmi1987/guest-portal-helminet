<?php

namespace App\Jobs;

use App\Models\NasClient;
use App\Nas\RouterOs\RouterStateQuery;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * State query of one router for "Refresh" of the setup assistant (CONCEPT.md §9). Runs on the
 * low-priority queue "router-state" (the worker takes jobs of "default" first), one job per router so
 * other jobs wait at most one router query. The marker shown on the page is removed when it ends.
 */
class QueryRouterState implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const QUEUE = 'router-state';

    /** Seconds the "queued" marker (and the unique lock) live at most. */
    public const MARKER_TTL = 600;

    public int $tries = 1;

    public int $uniqueFor = self::MARKER_TTL;

    public int $timeout;

    public function __construct(public string $nasId)
    {
        $this->onQueue(self::QUEUE);
        // Above the per-router deadline of the query
        $this->timeout = (int) config('portal.router_api.deadline', 45) + 15;
    }

    public function uniqueId(): string
    {
        return $this->nasId;
    }

    public static function markerKey(string $nasId): string
    {
        return 'router-state-queued:'.$nasId;
    }

    /** Time the query of this router was queued, null when none is pending. */
    public static function queuedAt(string $nasId): ?string
    {
        $at = Cache::get(self::markerKey($nasId));

        return is_string($at) ? $at : null;
    }

    /** Mark the router as queued (keeps an existing marker) and queue its query. */
    public static function request(NasClient $nas): void
    {
        Cache::add(self::markerKey((string) $nas->id), now()->toIso8601String(), self::MARKER_TTL);
        self::dispatch((string) $nas->id);
    }

    public function handle(RouterStateQuery $query): void
    {
        try {
            $nas = NasClient::with('site')->find($this->nasId);
            if ($nas !== null && $nas->hasApiAccess()) {
                $query->run($nas);
            }
        } finally {
            Cache::forget(self::markerKey($this->nasId));
        }
    }

    public function failed(): void
    {
        Cache::forget(self::markerKey($this->nasId));
    }
}
