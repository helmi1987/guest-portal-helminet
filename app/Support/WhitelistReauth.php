<?php

namespace App\Support;

use App\Jobs\ReauthRouter;
use App\Models\Site;
use App\Models\User;
use App\Nas\RouterOs\RouterReauth;
use Illuminate\Support\Collection;

/**
 * Trigger of the re-authentication by the portal (decision 2026-11-21, v0.33.0) for the routers of some sites: a
 * ReauthRouter job per router where the portal can run the script (RouterReauth::canRun()), else the RouterOS
 * command for the admin. Only MikroTik sites served by this instance. Result for the flash message "reauth":
 * queued router names and the routers that need the command, with the command.
 */
final class WhitelistReauth
{
    /**
     * @param  iterable<Site>  $sites
     * @return array{queued: list<string>, manual: list<string>, command: string}
     */
    public static function trigger(iterable $sites, ?User $user, string $reason, ?string $mac = null): array
    {
        $queued = [];
        $manual = [];
        foreach ($sites as $site) {
            if (! RouterReauth::supports($site) || ! $site->isServedHere()) {
                continue;
            }
            foreach ($site->nasClients()->orderBy('name')->get() as $nas) {
                $nas->setRelation('site', $site);
                $label = $site->name.' / '.$nas->name;
                if (RouterReauth::canRun($nas)) {
                    ReauthRouter::dispatch($nas->id, $user?->id, $reason, $mac);
                    $queued[] = $label;
                } else {
                    $manual[] = $label;
                }
            }
        }

        return ['queued' => $queued, 'manual' => $manual, 'command' => RouterReauth::command($mac)];
    }

    /**
     * Sites of a MAC whitelist entry: its site links, or every site (no links).
     *
     * @param  list<string>  $siteIds
     * @return Collection<int, Site>
     */
    public static function sitesOf(array $siteIds): Collection
    {
        return Site::query()->when($siteIds !== [], fn ($q) => $q->whereIn('id', $siteIds))->orderBy('name')->get();
    }
}
