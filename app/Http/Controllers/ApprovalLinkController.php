<?php

namespace App\Http\Controllers;

use App\Approval\ApprovalService;
use App\Approval\ApprovalSettings;
use App\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Confirmation page for signed approve/deny links (Gotify, webhook) on the admin port.
 * No login: the signature authorizes, expiry = request expiry, single use by request state.
 * GET only shows; POST decides, so link previews cannot approve.
 */
class ApprovalLinkController extends Controller
{
    public function show(Request $request, ApprovalRequest $approval): View
    {
        app()->setLocale(ApprovalSettings::locale());

        return view('approval-link', [
            'approval' => $approval->load(['guest', 'site']),
            'choice' => $request->query('choice'),
            'durations' => ApprovalSettings::durations(),
            'done' => null,
        ]);
    }

    public function decide(Request $request, ApprovalRequest $approval, ApprovalService $approvals): View
    {
        app()->setLocale(ApprovalSettings::locale());
        $durations = ApprovalSettings::durations();
        $data = $request->validate(['decision' => ['required', Rule::in([...array_keys($durations), 'deny'])]]);

        $done = $approvals->decide($approval, $data['decision'] === 'deny' ? null : $durations[$data['decision']],
            __('portal.approvals.via_link'), 'link');

        return view('approval-link', [
            'approval' => $approval->load(['guest', 'site']),
            'choice' => null,
            'durations' => $durations,
            'done' => $done,
        ]);
    }
}
