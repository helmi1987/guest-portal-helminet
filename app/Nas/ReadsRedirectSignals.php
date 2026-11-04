<?php

namespace App\Nas;

use App\Models\Site;
use Illuminate\Http\Request;

/**
 * Optional NasDriver interface (decision 2026-11-04, v0.28.0): what the core reads from the NAS's redirect
 * besides the HotspotContext, without knowing the vendor's parameter names. Drivers without it give no
 * signals (RedirectSignals::of()).
 */
interface ReadsRedirectSignals
{
    /**
     * has_login_url: the redirect comes from a NAS that is configured to send a login URL (the core shows
     * the "misconfigured" error page when parseRedirect() then dropped it). prefill_code: a 6-digit code
     * found in the original URL of the redirect (prefill only; RedirectSignals::codeFromUrl()).
     *
     * @return array{has_login_url: bool, prefill_code: ?string}
     */
    public function redirectSignals(Request $request, Site $site): array;
}
