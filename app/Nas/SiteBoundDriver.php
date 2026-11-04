<?php

namespace App\Nas;

use App\Models\Site;

/**
 * Optional NasDriver interface (decision 2026-11-04, v0.28.0): a driver whose methods depend on per-site
 * settings that the NasDriver signatures do not pass (loginHandoff(), replyAttributes(),
 * disconnectAttributes()). Site::driver() returns forSite($site), so every core call goes to a driver
 * bound to the site of the router or the guest.
 */
interface SiteBoundDriver
{
    /** A copy of the driver bound to $site. */
    public function forSite(Site $site): static;
}
