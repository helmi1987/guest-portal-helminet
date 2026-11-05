<?php

namespace App\Nas;

use App\Jobs\CheckRouters;
use App\Models\LocalSetting;
use App\Models\NasClient;
use App\Nas\RouterOs\RouterStateDriver;
use App\Nas\RouterOs\RouterStateQuery;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduled router check (decision 2026-11-05, v0.30.0, CONCEPT.md §9): for every router this instance serves, one
 * read-only state query (routers with API access, one after another, each bounded by the query's own deadline),
 * then the freshness result and the notification transition. Routers without API access get the page fingerprint
 * only. Nothing is ever sent to a router. Runs twice a day (portal:router-state-check) and once after a portal
 * version change (container start, portal:init).
 */
class RouterCheck
{
    /** Local setting: portal version of the last check after a version change. */
    public const VERSION_KEY = 'router_check_version';

    private const LOCK = 'router-state-check';

    public function __construct(private readonly RouterStateQuery $query, private readonly RouterFreshness $freshness,
        private readonly RouterNotifier $notifier) {}

    /**
     * Check all routers served here; null when another check is running.
     *
     * @param  (callable(NasClient, array, bool): void)|null  $report  called per router with its result and whether a message was sent
     * @return int|null number of routers checked
     */
    public function run(?callable $report = null): ?int
    {
        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            return null;
        }
        try {
            $routers = NasClient::query()->with('site')->get()
                ->filter(fn (NasClient $nas) => $nas->isServedHere())
                ->sortBy(fn (NasClient $nas) => [$nas->site->name, $nas->name])->values();
            foreach ($routers as $nas) {
                if ($nas->hasApiAccess() && $nas->site->driver() instanceof RouterStateDriver) {
                    $this->query->run($nas);
                }
                $result = $this->freshness->check($nas);
                $sent = $this->notifier->transition($nas, $result);
                if ($report !== null) {
                    $report($nas, $result, $sent);
                }
            }

            return $routers->count();
        } finally {
            $lock->release();
        }
    }

    /**
     * At the container start: when the portal version differs from the one of the last check, queue one check (not
     * blocking the start) and record the version, so later starts of the same version do not check again.
     */
    public static function afterVersionChange(): bool
    {
        $version = (string) config('portal.version');
        if (LocalSetting::get(self::VERSION_KEY) === $version) {
            return false;
        }
        LocalSetting::put(self::VERSION_KEY, $version);
        CheckRouters::dispatch();

        return true;
    }
}
