<?php

namespace App\Nas;

use App\Models\Site;

/**
 * Optional NasDriver interface (decision 2026-11-04, v0.28.0): whether the site's NAS supports Disconnect-Request
 * at all. false: DisconnectSession completes without sending anything and without retries (access ends at the
 * Session-Timeout). A null from disconnectAttributes() on a supporting NAS stays "cannot be addressed safely now"
 * (warning, retried).
 */
interface ReportsDisconnectSupport
{
    public function supportsDisconnect(Site $site): bool;
}
