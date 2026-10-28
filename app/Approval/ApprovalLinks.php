<?php

namespace App\Approval;

use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\URL;

/**
 * Signed, time-limited links to the confirmation page on the admin port (Gotify, webhook).
 * GET only shows the page; the decision needs a POST, so link previews cannot approve.
 * Single use: once decided, the request is no longer pending.
 */
class ApprovalLinks
{
    /** @return array<string,string> choice (confirm, 8h, 1d, 1w, deny) => absolute URL */
    public static function for(ApprovalRequest $request): array
    {
        $choices = ['confirm', ...array_keys(ApprovalSettings::durations()), 'deny'];
        $links = [];
        foreach ($choices as $choice) {
            $params = ['approval' => $request->id] + ($choice === 'confirm' ? [] : ['choice' => $choice]);
            $links[$choice] = ApprovalSettings::adminUrl()
                .URL::temporarySignedRoute('approval.link', $request->expires_at, $params, absolute: false);
        }

        return $links;
    }
}
