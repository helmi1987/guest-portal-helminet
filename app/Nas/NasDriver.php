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
     * Post-portal login handoff: where and what the guest's browser submits.
     *
     * @return array{url: string, fields: array<string, string>}
     */
    public function loginHandoff(HotspotContext $context, string $username, string $password): array;

    /** Vendor RADIUS reply attributes for an Access-Accept (rlm_rest JSON keys). */
    public function replyAttributes(Grant $grant): array;

    /** Captive-portal page installed on the router, redirecting to this portal. */
    public function loginPage(Site $site): string;

    /** Router configuration script for one router of a site. */
    public function configScript(Site $site, NasClient $nas): string;
}
