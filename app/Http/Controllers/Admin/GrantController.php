<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExpiryMode;
use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\Site;
use App\Services\GrantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Codes / grants (admin + manager, CONCEPT.md §8).
 */
class GrantController extends Controller
{
    public function index(Request $request): View
    {
        $status = GrantStatus::tryFrom((string) $request->query('status'));
        $grants = Grant::query()->with(['siteLinks', 'devices' => fn ($q) => $q->whereNull('unbound_at')])
            ->when($status, fn ($q) => $q->withStatus($status))
            ->latest()->limit(500)->get();

        return view('admin.grants.index', [
            'grants' => $grants,
            'status' => $status,
            'sites' => Site::query()->pluck('name', 'id'),
        ]);
    }

    public function create(): View
    {
        return view('admin.grants.create', ['sites' => Site::query()->orderBy('name')->pluck('name', 'id')]);
    }

    public function store(Request $request, GrantManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'between:1,50'],
            'duration' => ['required', Rule::in([...array_keys(config('portal.duration_presets')), 'custom'])],
            'custom_hours' => ['required_if:duration,custom', 'nullable', 'numeric', 'min:0.25', 'max:8760'],
            'expiry_mode' => ['required', Rule::enum(ExpiryMode::class)],
            'valid_from' => ['nullable', 'date'],
            'redeem_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            ...$this->editableRules(),
        ]);

        $attributes = [
            'duration_s' => $data['duration'] === 'custom'
                ? (int) round($data['custom_hours'] * 3600)
                : config('portal.duration_presets')[$data['duration']],
            'expiry_mode' => $data['expiry_mode'],
            'valid_from' => $this->date($data['valid_from'] ?? null),
            'redeem_until' => $this->date($data['redeem_until'] ?? null),
            'label' => $data['label'] ?? null,
            'note' => $data['note'] ?? null,
            'max_extensions' => $data['max_extensions'] ?? null,
            'rate_limit' => $data['rate_limit'] ?? null,
            'site_ids' => $data['site_ids'] ?? [],
        ];

        $ids = collect(range(1, $data['quantity']))
            ->map(fn () => $manager->createCode($attributes, $request->user())->id);

        return redirect()->route('admin.grants.print', ['ids' => $ids->all()]);
    }

    public function edit(Grant $grant): View
    {
        return view('admin.grants.edit', [
            'grant' => $grant->load(['siteLinks', 'devices.guest', 'sessions' => fn ($q) => $q->latest('started_at')->limit(20)]),
            'sites' => Site::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, Grant $grant): RedirectResponse
    {
        $data = $request->validate([
            'valid_from' => ['nullable', 'date'],
            'redeem_until' => ['nullable', 'date'],
            ...$this->editableRules(),
        ]);

        $grant->update([
            'valid_from' => $this->date($data['valid_from'] ?? null),
            'redeem_until' => $this->date($data['redeem_until'] ?? null),
            'label' => $data['label'] ?? null,
            'note' => $data['note'] ?? null,
            'max_extensions' => $data['max_extensions'] ?? null,
            'rate_limit' => $data['rate_limit'] ?? null,
        ]);
        $grant->syncSites($data['site_ids'] ?? []);

        return back()->with('status', __('portal.saved'));
    }

    /** Extend (positive) or shorten (negative) by hours. */
    public function extend(Request $request, Grant $grant, GrantManager $manager): RedirectResponse
    {
        $hours = (float) $request->validate(['hours' => ['required', 'numeric', 'between:-8760,8760', 'not_in:0']])['hours'];
        if ($hours > 0 && ! $grant->canExtend()) {
            return back()->withErrors(['hours' => __('portal.grants.max_extensions_reached')]);
        }

        $manager->extend($grant, (int) round($hours * 3600));
        $this->audit('extend', $grant, $request, ['hours' => $hours]);

        return back()->with('status', __('portal.saved'));
    }

    public function expiry(Request $request, Grant $grant, GrantManager $manager): RedirectResponse
    {
        $data = $request->validate(['expires_at' => ['required', 'date']]);
        $manager->setExpiry($grant, Carbon::parse($data['expires_at']));
        $this->audit('set_expiry', $grant, $request, $data);

        return back()->with('status', __('portal.saved'));
    }

    public function revoke(Request $request, Grant $grant, GrantManager $manager): RedirectResponse
    {
        $manager->revoke($grant);
        $this->audit('revoke', $grant, $request);

        return back()->with('status', __('portal.grants.revoked'));
    }

    public function print(Request $request): View
    {
        $ids = array_slice((array) $request->query('ids', []), 0, 100);

        return view('admin.grants.print', [
            'grants' => Grant::query()->whereIn('id', $ids)->where('type', GrantType::Code)->get(),
        ]);
    }

    private function editableRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'max_extensions' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'rate_limit' => ['nullable', 'string', 'max:64', 'regex:/^[0-9A-Za-z\/ .]+$/'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['string', Rule::exists('sites', 'id')->whereNull('deleted_at')],
        ];
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }

    /** Minimal audit trail in the log until the audit log table exists (M6). */
    private function audit(string $action, Grant $grant, Request $request, array $data = []): void
    {
        Log::info('Grant '.$action, ['grant' => $grant->id, 'user' => $request->user()?->id] + $data);
    }
}
