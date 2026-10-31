<?php

namespace App\Nas\RouterOs;

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
     * name, status (present|foreign|missing|differs|legacy|unknown), diff => [property => [expected, actual]]]]].
     */
    public function compareState(Site $site, array $state): array;

    /**
     * Setup assistant inputs derivable from the state: [field => [value, source]] (source: short
     * English hint where the value comes from).
     *
     * @return array<string, array{0: string|int, 1: string}>
     */
    public function deriveSetup(Site $site, array $state): array;
}
