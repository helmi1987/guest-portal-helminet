<?php

namespace App\Nas\RouterOs;

use App\Models\NasClient;
use Illuminate\Support\Facades\Log;

/**
 * Runs the read-only state query of one router and stores the result locally (CONCEPT.md §9). Menus
 * missing on the router (e.g. no wireless package) are recorded per menu; any other error aborts the
 * query, keeps the previous state and is stored as the router's error.
 */
class RouterStateQuery
{
    public function __construct(private readonly RouterOsClients $clients) {}

    /**
     * @param  float|null  $until  optional overall deadline (microtime) of the caller
     * @return array{kind: string, detail: string}|null error, null on success
     */
    public function run(NasClient $nas, ?float $until = null): ?array
    {
        $site = $nas->site;
        $driver = $site?->driver();
        if (! $driver instanceof RouterStateDriver) {
            $error = ['kind' => 'config', 'detail' => 'the NAS driver does not support a state query'];
            $nas->storeRouterState(null, $error);

            return $error;
        }
        $max = (int) config('portal.router_api.max_rows', 2000);
        // Own deadline, capped by the overall one of the caller ($until, microtime)
        $deadline = min(microtime(true) + (int) config('portal.router_api.deadline', 45), $until ?? PHP_FLOAT_MAX);
        $client = null;
        try {
            $client = $this->clients->for($nas, $deadline);
            $menus = [];
            foreach ($driver->stateMenus($site) as $menu => $spec) {
                if (microtime(true) > $deadline) {
                    throw new RouterOsException('timeout', 'query exceeded its time limit');
                }
                $props = array_values(array_unique(['.id', ...$spec['props']]));
                try {
                    $rows = $client->print($menu, $props, $spec['where'] ?? []);
                    $menus[$menu] = ['rows' => array_slice($rows, 0, $max)] + (count($rows) > $max ? ['truncated' => true] : []);
                } catch (RouterOsException $e) {
                    if ($e->kind !== 'menu') {
                        throw $e;
                    }
                    $menus[$menu] = ['error' => $e->getMessage()];
                }
            }
            $nas->storeRouterState(['menus' => $menus, 'host' => $nas->api_host]);

            return null;
        } catch (RouterOsException $e) {
            // Never the credentials: the detail is built without them
            Log::info('Router state query failed', ['nas' => $nas->id, 'kind' => $e->kind, 'detail' => $e->getMessage()]);
            $nas->storeRouterState(null, $e->toArray());

            return $e->toArray();
        } finally {
            $client?->close();
        }
    }
}
