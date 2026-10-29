<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audit log (admin only, CONCEPT.md §9): filter by action group, user and date, newest first.
 */
class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'group' => ['nullable', 'in:'.implode(',', Audit::GROUPS)],
            'user' => ['nullable', 'string', 'max:26'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'subject' => ['nullable', 'string', 'max:64'],
        ]);

        $logs = AuditLog::query()->with('user')
            ->when($filters['group'] ?? null, fn ($q, $group) => $q->where('action', 'like', $group.'.%'))
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('user_id', $user))
            ->when($filters['subject'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', LocalTime::startOfDay($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', LocalTime::endOfDay($to)))
            ->latest()->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('admin.audit', [
            'logs' => $logs,
            'filters' => $filters,
            'users' => User::withTrashed()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
