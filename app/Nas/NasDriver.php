<?php

namespace App\Nas;

use App\Models\Grant;
use App\Models\NasClient;
use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Vendor-specific parts of the captive portal (CONCEPT.md §5.1). The RADIUS core is vendor-neutral.
 */
interface NasDriver
{
    public function label(): string;

    /**
     * Read the captive-portal redirect parameters. The login URL receives the one-time credentials:
     * it must point to a hotspot host configured for the site, otherwise it is dropped.
     */
    public function parseRedirect(Request $request, Site $site): HotspotContext;

    /**
     * Post-portal login handoff: where and what the guest's browser submits. After the login the router
     * should send the browser to $successUrl (portal status page).
     *
     * @return array{url: string, fields: array<string, string>}
     */
    public function loginHandoff(HotspotContext $context, string $username, string $password, string $successUrl): array;

    /** Vendor RADIUS reply attributes for an Access-Accept (rlm_rest JSON keys). */
    public function replyAttributes(Grant $grant): array;

    /** Captive-portal page installed on the router, redirecting to this portal. */
    public function loginPage(Site $site): string;

    /** Router configuration script for one router of a site. */
    public function configScript(Site $site, NasClient $nas): string;

    /**
     * Inputs of the setup assistant (CONCEPT.md §9) as validation rules: "network" (stored per site in
     * the site setting "setup") and "container" (instance-local).
     *
     * @return array{network: array<string, array>, container: array<string, array>}
     */
    public function setupFields(): array;

    /**
     * Ready-to-copy, sanitized command blocks of the setup assistant, by step and block id:
     * container, network, hotspot, diagnostics.
     *
     * @return array<string, array<string, string>>
     */
    public function setupCommands(Site $site, array $container): array;

    /** Command that makes the router download its login page (route router-login). */
    public function loginPageFetch(Site $site, NasClient $nas): string;
}
