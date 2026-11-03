<?php

namespace App\Nas\RouterOs;

use App\Models\NasClient;
use App\Models\Site;

/**
 * NAS driver that can read and evaluate the router state for the setup assistant (CONCEPT.md §9).
 */
interface RouterStateDriver
{
    /**
     * Menus to print: menu => ['props' => list of properties, 'where' => optional OR conditions].
     *
     * @return array<string, array{props: list<string>, where?: list<string>}>
     */
    public function stateMenus(Site $site): array;

    /**
     * Expected objects of the site compared with a stored state, by block: [block => [key => [path,
     * name, status (present|foreign|missing|differs|legacy|unmanaged|leftover|unknown), diff => [property => [expected, actual]],
     * adopt (only with unmanaged: one-time command that sets the comment key, null when ambiguous)]]].
     */
    public function compareState(Site $site, array $state): array;

    /**
     * One-time clean-up commands for objects of an earlier selection found in a stored state (e.g. Wi-Fi objects of
     * a mode no longer selected, reported with status "leftover" by compareState()), null when there are none.
     */
    public function leftoverCommands(Site $site, array $state): ?string;

    /**
     * Why the RouterOS container cannot run on the router of a stored state: "package" (container package
     * missing), "device_mode" (device mode does not allow containers) or null (fine or unknown).
     */
    public function containerProblem(array $state): ?string;

    /**
     * Setup assistant inputs derivable from the state: [field => [value, source]] (source: short
     * English hint where the value comes from).
     *
     * @return array<string, array{0: string|int, 1: string}>
     */
    public function deriveSetup(Site $site, array $state): array;

    /**
     * Display state per line of a generated script (unmanaged|missing|update|ok|null; uninstall scripts:
     * remove|kept|null), from a map comment key (or hotspot:server|profile) => compare status. Display only.
     *
     * @param  array<string, string>  $statusByKey
     * @return list<?string>
     */
    public function lineStatuses(string $script, array $statusByKey, bool $uninstall = false): array;

    /**
     * Placeholder suggestions from the stored router states and prefill values from the site.
     *
     * @param  iterable<NasClient>  $routers
     * @return array{options: array<string, list<string>>, values: array<string, string>}
     */
    public function placeholderHints(Site $site, iterable $routers): array;

    /**
     * Guest Wi-Fi masters selectable per Wi-Fi mode from the stored router states (setup assistant).
     *
     * @param  iterable<NasClient>  $routers
     * @return array<string, list<string>> mode => names
     */
    public function wifiMasterOptions(iterable $routers): array;

    /**
     * Stored passwords for the script placeholders (escaped for quoted strings), only for the admin's
     * explicit "Insert passwords": WIFI_PASSWORD and API_PASSWORD per router id.
     *
     * @param  iterable<NasClient>  $routers
     * @return array{WIFI_PASSWORD: ?string, API_PASSWORD: array<string, ?string>}
     */
    public function scriptSecrets(Site $site, iterable $routers): array;
}
