<?php

namespace App\Jobs;

use App\Models\NasClient;
use App\Nas\RouterOs\RouterStateQuery;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * State query of one router for "Refresh" of the setup assistant (CONCEPT.md §9). Runs on the
 * low-priority queue "router-state" (the worker takes jobs of "default" first), one job per router so
 * other jobs wait at most one router query. Unique until processed: while the query of a router is queued
 * or running, a further "Refresh" adds no job (the running one delivers the state). The "queued" marker
 * shown on the page is set only together with a new job and removed when that job ends, so it never
 * outlives the last pending job.
 */
class QueryRouterState implements ShouldBeUnique, ShouldQueue
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

    /**
     * Queue the query of the router and mark it as queued; nothing while a query of it is queued or running
     * (unique lock, released by the queue worker when the job has been processed or has failed).
     */
    public static function request(NasClient $nas): void
    {
        $job = new self((string) $nas->id);
        $lock = new UniqueLock(Cache::store());
        if (! $lock->acquire($job)) {
            return;
        }
        Cache::put(self::markerKey($job->nasId), now()->toIso8601String(), self::MARKER_TTL);
        try {
            // Lock taken above: dispatch without PendingDispatch, which would try to take it again
            app(Dispatcher::class)->dispatch($job);
        } catch (\Throwable $e) {
            Cache::forget(self::markerKey($job->nasId));
            $lock->release($job);

            throw $e;
        }
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
