<?php

namespace App\Nas\RouterOs;

/**
 * Read-only RouterOS client (setup assistant state query, CONCEPT.md §9). Operations: "print" on a whitelisted
 * menu (Menus::ALLOWED) with an explicit property list, and "run" of one script by its id (re-authentication by
 * the portal, decision 2026-11-21: only RouterReauth calls it, with the id of the own script it found by name and
 * comment key). No other verb can be expressed; the API user has no write policy, so the portal never changes a
 * router configuration through this access.
 */
interface RouterOsClient
{
    /**
     * Rows of one menu, each with the requested properties (RouterOS strings). $where: optional
     * "property=value" conditions, combined with OR.
     *
     * @param  list<string>  $proplist
     * @param  list<string>  $where
     * @return list<array<string, string>>
     *
     * @throws RouterOsException
     */
    public function print(string $menu, array $proplist, array $where = []): array;

    /**
     * Run the script with this internal id ("*<hex>", Menus::guardScriptId()) and wait for its end.
     *
     * @throws RouterOsException
     */
    public function runScript(string $id): void;

    public function close(): void;
}
