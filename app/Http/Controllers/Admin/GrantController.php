<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExpiryMode;
use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\Site;
use App\Services\GrantManager;
use App\Services\VoucherQr;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'quantity' => ['required', 'integer', 'between:1,100'],
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

        $settings = [
            'duration_s' => $attributes['duration_s'], 'expiry_mode' => $attributes['expiry_mode'],
            'label' => $attributes['label'], 'sites' => $attributes['site_ids'],
        ];
        $grants = DB::transaction(function () use ($data, $attributes, $settings, $manager, $request) {
            $grants = collect(range(1, $data['quantity']))->map(fn () => $manager->createCode($attributes, $request->user()));
            // One entry per batch; never the codes themselves (CONCEPT.md §9).
            $grants->count() === 1
                ? Audit::log('grant.created', $grants->first(), $settings)
                : Audit::log('grant.bulk_created', null, ['count' => $grants->count(), ...$settings]);

            return $grants;
        });

        return redirect()->route('admin.grants.print', ['ids' => $grants->pluck('id')->all()]);
    }

    public function edit(Grant $grant, VoucherQr $qr): View
    {
        return view('admin.grants.edit', [
            'qr' => $qr,
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
        Audit::log('grant.updated', $grant, [
            'changed' => array_values(array_diff(array_keys($grant->getChanges()), ['updated_at', 'version'])),
            'sites' => $data['site_ids'] ?? [],
        ]);

        return back()->with('status', __('portal.saved'));
    }

    /** Extend (positive) or shorten (negative) by hours. */
    public function extend(Request $request, Grant $grant, GrantManager $manager): RedirectResponse
    {
        abort_if($grant->revoked_at !== null, 422, __('portal.grants.already_revoked'));
        $hours = (int) $request->validate(['hours' => ['required', 'integer', 'between:-8760,8760', 'not_in:0']])['hours'];
        if ($hours > 0 && ! $grant->canExtend()) {
            return back()->withErrors(['hours' => __('portal.grants.max_extensions_reached')]);
        }

        $manager->extend($grant, $hours * 3600);
        Audit::log('grant.extended', $grant, ['hours' => $hours, 'expires_at' => $grant->expires_at?->toIso8601String()]);

        return back()->with('status', __('portal.saved'));
    }

    public function expiry(Request $request, Grant $grant, GrantManager $manager): RedirectResponse
    {
        $data = $request->validate(['expires_at' => ['required', 'date']]);
        $manager->setExpiry($grant, Carbon::parse($data['expires_at']));
        Audit::log('grant.expiry', $grant, ['expires_at' => $grant->expires_at?->toIso8601String()]);

        return back()->with('status', __('portal.saved'));
    }

    public function revoke(Request $request, Grant $grant, GrantManager $manager): RedirectResponse
    {
        $manager->revoke($grant);
        Audit::log('grant.revoked', $grant);

        return back()->with('status', __('portal.grants.revoked'));
    }

    public function print(Request $request, VoucherQr $qr): View
    {
        return view('admin.grants.print', ['grants' => $this->selected($request), 'qr' => $qr]);
    }

    /** CSV of the selected codes (admin only). */
    public function export(Request $request): StreamedResponse
    {
        $grants = $this->selected($request);
        $sites = Site::query()->pluck('name', 'id');
        Audit::log('grant.exported', null, ['count' => $grants->count()]);

        return response()->streamDownload(function () use ($grants, $sites) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['code', 'label', 'status', 'duration_hours', 'expiry_mode', 'valid_from', 'redeem_until', 'expires_at', 'sites', 'created_at'], escape: '');
            foreach ($grants as $grant) {
                fputcsv($out, array_map(self::csvCell(...), [
                    $grant->code, $grant->label, $grant->status()->value, round($grant->duration_s / 3600, 2),
                    $grant->expiry_mode->value, $grant->valid_from?->toIso8601String(), $grant->redeem_until?->toIso8601String(),
                    $grant->expires_at?->toIso8601String(),
                    $grant->siteLinks->map(fn ($l) => $sites[$l->site_id] ?? $l->site_id)->join(', ') ?: 'all',
                    $grant->created_at?->toIso8601String(),
                ]), escape: '');
            }
            fclose($out);
        }, 'codes-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Code grants by ids[] (max. 100), in creation order. */
    private function selected(Request $request): Collection
    {
        $ids = array_slice(array_filter((array) $request->query('ids', []), 'is_string'), 0, 100);

        return Grant::query()->with('siteLinks')->whereIn('id', $ids)->where('type', GrantType::Code)
            ->orderBy('created_at')->orderBy('id')->get();
    }

    /** Neutralise spreadsheet formulas in free-text cells (also behind leading whitespace or line breaks). */
    private static function csvCell(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $dangerous = preg_match('/^[\t\r\n]/', $value) === 1
            || preg_match('/^[=+\-@]/', ltrim($value, " \t\r\n\0\x0B")) === 1;

        return $dangerous ? "'".$value : $value;
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
}
