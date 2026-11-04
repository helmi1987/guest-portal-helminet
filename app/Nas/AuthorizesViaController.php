<?php

namespace App\Nas;

use App\Models\Grant;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Optional interface next to NasDriver (CONCEPT.md §5.1, decision 2026-11-04, v0.27.0) for captive portals
 * that let a guest out through a controller API instead of a RADIUS login on the NAS (e.g. Ubiquiti UniFi
 * with an external portal server). The core checks it with instanceof:
 *
 * - GuestLogin::handoff() calls authorizeClient() instead of rendering the browser POST of loginHandoff(),
 *   binds the device and creates the session summary from the answer;
 * - the status page does not wait for a RADIUS accept and shows clientStatus();
 * - DisconnectSession and portal:enforce-grants call deauthorizeClient(); an extension or shortening of a
 *   running grant authorizes again with the new remaining time;
 * - portal:poll-controllers updates the session summaries from clientStatus() (no RADIUS accounting).
 *
 * MAC whitelist and roaming (RADIUS MAC authentication) are not available on such sites.
 */
interface AuthorizesViaController
{
    /**
     * Validation rules of the per-site controller inputs (site setting "controller"; secrets are handled
     * by the driver's settings class and never part of these rules' stored values).
     *
     * @return array<string, array<int, mixed>>
     */
    public function controllerFields(): array;

    /**
     * Let the client of $context out for $seconds after a successful portal login. Checks first that the
     * client is connected to the controller as a guest (and on the AP of the redirect, when known).
     * Never throws for controller errors: the result says why it failed.
     */
    public function authorizeClient(Site $site, HotspotContext $context, Grant $grant, int $seconds): ControllerAuthorization;

    /**
     * End the client's access (revoke, shorten, rebind). True when the controller confirmed it or reports
     * the client as not connected (nothing left to end as far as it can tell); false on errors.
     */
    public function deauthorizeClient(Site $site, string $mac): bool;

    /**
     * Current state of a client for the status page and the poller: null when the controller cannot be
     * asked (error) or does not know the client.
     *
     * @return array{connected: bool, authorized: bool, ip: ?string, bytes_in: ?int, bytes_out: ?int, expires_at: ?Carbon}|null
     */
    public function clientStatus(Site $site, string $mac): ?array;

    /**
     * Check button of the site page: login, site lookup, version and number of clients.
     *
     * @return array{status: string, message: string}
     */
    public function checkController(Site $site): array;
}
