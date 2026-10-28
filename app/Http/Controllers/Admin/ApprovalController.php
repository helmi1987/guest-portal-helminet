<?php

namespace App\Http\Controllers\Admin;

use App\Approval\ApprovalService;
use App\Approval\ApprovalSettings;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Approval requests in the admin UI (admin + manager, CONCEPT.md §9).
 */
class ApprovalController extends Controller
{
    public function index(): View
    {
        $requests = ApprovalRequest::query()->with(['guest', 'site'])->latest()->limit(200)->get();

        return view('admin.approvals.index', [
            'pending' => $requests->filter->isPending(),
            'decided' => $requests->reject->isPending(),
        ]);
    }

    public function decide(Request $request, ApprovalRequest $approval, ApprovalService $approvals): RedirectResponse
    {
        $durations = ApprovalSettings::durations();
        $data = $request->validate([
            'decision' => ['required', Rule::in([...array_keys($durations), 'custom', 'deny'])],
            'custom_hours' => ['required_if:decision,custom', 'nullable', 'numeric', 'min:0.25', 'max:8760'],
        ]);
        $seconds = match ($data['decision']) {
            'deny' => null,
            'custom' => (int) round($data['custom_hours'] * 3600),
            default => $durations[$data['decision']],
        };

        $user = $request->user();
        if (! $approvals->decide($approval, $seconds, $user->name, 'admin', $user)) {
            return back()->withErrors(['decision' => __('portal.approvals.closed')]);
        }

        return back()->with('status', $approval->status === ApprovalStatus::Approved
            ? __('portal.approvals.approved') : __('portal.approvals.denied'));
    }
}
