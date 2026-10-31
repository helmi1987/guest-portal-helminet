<?php

namespace App\Nas\RouterOs;

/**
 * Read-only RouterOS client (setup assistant state query, CONCEPT.md §9). The only operation is
 * "print" on a whitelisted menu (Menus::ALLOWED) with an explicit property list: no other verb can be
 * expressed, so the portal never changes a router through this access.
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

    public function close(): void;
}
