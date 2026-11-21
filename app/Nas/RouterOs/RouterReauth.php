<?php

namespace App\Nas\RouterOs;

use App\Models\NasClient;
use App\Models\Site;
use App\Nas\MikrotikRos7Driver;
use Illuminate\Support\Facades\Log;

/**
 * Re-authentication by the portal (decision 2026-11-21, v0.33.0, CONCEPT.md §5): runs the own script
 * "guest-portal-reauth" of a router through its read-only API access. The script is found by name and comment key
 * (and must have dont-require-permissions=yes); a script of others with that name is never run. Only for sites with
 * the setting switched on, the MikroTik driver and routers served here with API access (routers() / the commands of
 * the others: command()).
 */
class RouterReauth
{
    public function __construct(private readonly RouterOsClients $clients) {}

    /** Routers of the site where the portal can run the script itself. */
    public static function canRun(NasClient $nas): bool
    {
        $site = $nas->site;

        return $site !== null && $site->driver() instanceof MikrotikRos7Driver && $site->routerReauth()
            && $nas->isServedHere() && $nas->hasApiAccess();
    }

    /** MikroTik sites (driver supports MAC authentication by the router). */
    public static function supports(?Site $site): bool
    {
        return $site !== null && $site->driver() instanceof MikrotikRos7Driver;
    }

    /** RouterOS command for the admin when the portal cannot run the script: one MAC, or all unauthorized hosts. */
    public static function command(?string $mac = null): string
    {
        $server = MikrotikRos7Driver::NAME;

        // Own hotspot server only (hosts of other hotspot servers are never touched)
        return $mac !== null && preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/D', $mac) === 1
            ? "/ip hotspot host remove [find where server={$server} && mac-address={$mac}]"
            : MikrotikRos7Driver::REAUTH_SOURCE;
    }

    /**
     * Run the script on one router.
     *
     * @return array{kind: string, detail: string}|null error, null on success
     */
    public function run(NasClient $nas): ?array
    {
        if (! self::canRun($nas)) {
            return ['kind' => 'config', 'detail' => 're-authentication by the portal is not available for this router'];
        }
        $deadline = microtime(true) + (int) config('portal.router_api.deadline', 45);
        $client = null;
        try {
            $client = $this->clients->for($nas, $deadline);
            $key = MikrotikRos7Driver::key('api', 'reauth');
            $rows = $client->print('/system script', ['.id', 'name', 'comment', 'dont-require-permissions'], ['name='.MikrotikRos7Driver::REAUTH_SCRIPT]);
            $own = collect($rows)->first(fn (array $r) => ($r['name'] ?? null) === MikrotikRos7Driver::REAUTH_SCRIPT
                && ($r['comment'] ?? null) === $key && in_array($r['dont-require-permissions'] ?? null, ['true', 'yes'], true));
            if ($own === null) {
                throw new RouterOsException('config', 'the script '.MikrotikRos7Driver::REAUTH_SCRIPT.' is not on the router (run the API access block)');
            }
            $client->runScript((string) ($own['.id'] ?? ''));

            return null;
        } catch (RouterOsException $e) {
            Log::info('Router re-authentication failed', ['nas' => $nas->id, 'kind' => $e->kind, 'detail' => $e->getMessage()]);

            return $e->toArray();
        } finally {
            $client?->close();
        }
    }
}
