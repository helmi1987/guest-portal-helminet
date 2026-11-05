<?php

namespace App\Nas;

use App\Http\Controllers\Admin\SetupController;
use App\Http\Controllers\RouterLoginPageController;
use App\Models\NasClient;
use App\Models\Site;
use App\Nas\RouterOs\RouterStateDriver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Is a router up to date (decision 2026-11-05, v0.30.0, CONCEPT.md §9)? Two parts, both computed on demand:
 *
 * - Hotspot pages: the fingerprint of every page the router fetched (nas_clients.hotspot_pages) against the page the
 *   driver renders now (portal URL, site slug, ...). A page never fetched counts as outdated for drivers whose router
 *   fetches all its pages from the portal (ServesHotspotPages), once the router fetched anything (a router that never
 *   fetched a page is still in setup; one that fetched before 0.30.0 has a time but no fingerprints); other drivers'
 *   pages are uploaded by hand, so only a page that was fetched once is compared.
 * - Configuration (routers with read-only API access and a RouterStateDriver): the stored router state compared with
 *   the site (compareState()); a block with a differing, legacy or missing object is outdated. The optional container
 *   block counts only when the container objects exist on the router. A state older than the setup inputs or the
 *   router's access settings is "pending" (not compared, like the setup page), two consecutive failed queries make
 *   the router "unreachable"; routers without API access are "unchecked".
 */
class RouterFreshness
{
    /** Hex characters of a page fingerprint (sha256 prefix, 128 bits). */
    public const HASH_LENGTH = 32;

    /** Consecutive failed state queries after which a router counts as unreachable. */
    public const UNREACHABLE_AFTER = 2;

    /** Statuses of compareState() that make a block outdated. */
    public const OUTDATED_STATUSES = ['differs', 'legacy', 'missing'];

    /** Configuration result: compared, nothing outdated / blocks outdated / not compared yet / no API / query failing. */
    public const CONFIG_OK = 'ok';

    public const CONFIG_OUTDATED = 'outdated';

    public const CONFIG_PENDING = 'pending';

    public const CONFIG_UNCHECKED = 'unchecked';

    public const CONFIG_UNREACHABLE = 'unreachable';

    public static function hash(string $html): string
    {
        return substr(hash('sha256', $html), 0, self::HASH_LENGTH);
    }

    /**
     * Fingerprints of the pages the site's driver renders now, page => hash.
     *
     * @return array<string, string>
     */
    public static function currentHashes(Site $site): array
    {
        return array_map(self::hash(...), RouterLoginPageController::pages($site));
    }

    /**
     * Result for one router (its site loaded or loadable):
     *  pages: outdated page names (fetch order), blocks: outdated state blocks, config: CONFIG_*,
     *  unreachableSince: first failed query while unreachable, outdated: pages or blocks outdated,
     *  unreachable: config is unreachable, upToDate: nothing outdated and the configuration ok or unchecked.
     *
     * @return array{pages: list<string>, blocks: list<string>, config: string, unreachableSince: ?Carbon, outdated: bool, unreachable: bool, upToDate: bool}
     */
    public function check(NasClient $nas): array
    {
        $site = $nas->site;
        $pages = $site !== null ? $this->outdatedPages($site, $nas) : [];
        [$config, $blocks] = $site !== null ? $this->configuration($site, $nas) : [self::CONFIG_UNCHECKED, []];
        $outdated = $pages !== [] || $blocks !== [];

        return [
            'pages' => $pages,
            'blocks' => $blocks,
            'config' => $config,
            'unreachableSince' => $config === self::CONFIG_UNREACHABLE ? $nas->router_state_failed_since : null,
            'outdated' => $outdated,
            'unreachable' => $config === self::CONFIG_UNREACHABLE,
            'upToDate' => ! $outdated && in_array($config, [self::CONFIG_OK, self::CONFIG_UNCHECKED], true),
        ];
    }

    /**
     * Routers of the given sites served by this instance that need the admin (outdated or unreachable), with their
     * result, ordered by site and router name.
     *
     * @param  iterable<Site>  $sites
     * @return Collection<int, array{router: NasClient, site: Site, result: array}>
     */
    public function attention(iterable $sites): Collection
    {
        $out = collect();
        foreach ($sites as $site) {
            if (! $site->isServedHere()) {
                continue;
            }
            foreach ($site->nasClients->sortBy('name') as $nas) {
                $nas->setRelation('site', $site);
                $result = $this->check($nas);
                if ($result['outdated'] || $result['unreachable']) {
                    $out->push(['router' => $nas, 'site' => $site, 'result' => $result]);
                }
            }
        }

        return $out;
    }

    /**
     * After a page fetch: a router that is up to date again gets its "outdated" notification mark removed, so the
     * next transition to outdated is notified again (RouterNotifier).
     */
    public function resetWhenFresh(NasClient $nas): void
    {
        $notified = (array) $nas->freshness_notified;
        if (isset($notified['outdated']) && $this->check($nas)['upToDate']) {
            unset($notified['outdated']);
            $nas->storeFreshnessNotified($notified);
        }
    }

    /** @return list<string> */
    private function outdatedPages(Site $site, NasClient $nas): array
    {
        // Never fetched any page (no time, no fingerprint): the router is still in setup, not outdated
        if ($nas->login_page_fetched_at === null && $nas->hotspot_pages === null) {
            return [];
        }
        $fetchesAll = $site->driver() instanceof ServesHotspotPages;
        $stored = (array) $nas->hotspot_pages;
        $out = [];
        foreach (self::currentHashes($site) as $page => $hash) {
            $had = $stored[$page]['hash'] ?? null;
            if ($had === null ? $fetchesAll : $had !== $hash) {
                $out[] = $page;
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: list<string>} */
    private function configuration(Site $site, NasClient $nas): array
    {
        $driver = $site->driver();
        if (! $driver instanceof RouterStateDriver || ! $nas->hasApiAccess()) {
            return [self::CONFIG_UNCHECKED, []];
        }
        if ((int) $nas->router_state_failures >= self::UNREACHABLE_AFTER) {
            return [self::CONFIG_UNREACHABLE, []];
        }
        $at = $nas->router_state_at;
        $inputsAt = SetupController::inputsChangedAt($site);
        // Same rule as the setup page (SetupController::fresh()) without its maximum age: a state older than the
        // inputs or the access settings, or of another API host, is not compared
        if (! is_array($nas->router_state) || $at === null || ($inputsAt !== null && $at->lt($inputsAt))
            || ($nas->updated_at !== null && $at->lt($nas->updated_at)) || ($nas->router_state['host'] ?? null) !== $nas->api_host) {
            return [self::CONFIG_PENDING, []];
        }
        $blocks = [];
        foreach ($driver->compareState($site, $nas->router_state) as $block => $items) {
            $statuses = array_map(fn ($item) => (string) ($item['status'] ?? 'unknown'), $items);
            // Optional RouterOS container (Docker host as alternative): only when it is installed
            if ($block === 'container' && array_diff($statuses, ['missing', 'unknown']) === []) {
                continue;
            }
            if (array_intersect($statuses, self::OUTDATED_STATUSES) !== []) {
                $blocks[] = (string) $block;
            }
        }

        return $blocks === [] ? [self::CONFIG_OK, []] : [self::CONFIG_OUTDATED, $blocks];
    }
}
