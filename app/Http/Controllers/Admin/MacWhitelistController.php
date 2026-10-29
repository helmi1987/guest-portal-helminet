<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\MacWhitelist;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Support\Audit;
use App\Support\LocalTime;
use App\Support\Mac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * MAC whitelist (admin + manager, CONCEPT.md §5, §9): devices admitted by RouterOS MAC
 * authentication without the portal. Deactivating, deleting or restricting an entry disconnects its
 * open sessions on this instance; expiry is enforced by portal:enforce-grants.
 */
class MacWhitelistController extends Controller
{
    private const STATUSES = ['active', 'inactive', 'expired'];

    public function index(Request $request): View
    {
        $q = is_string($request->query('q')) ? mb_substr(trim($request->query('q')), 0, 100) : '';
        $site = is_string($request->query('site')) ? $request->query('site') : null;
        $status = in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : null;

        $entries = MacWhitelist::query()->with(['siteLinks', 'creator',
            'sessions' => fn ($s) => $s->whereNull('stopped_at')->latest('started_at')])
            ->when($q !== '', function (Builder $query) use ($q) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $mac = Mac::normalize($q);
                $query->where(fn ($w) => $w->whereRaw("comment like ? escape '!'", [$like])
                    ->orWhereRaw("mac like ? escape '!'", [strtoupper($like)])
                    ->when($mac, fn ($w) => $w->orWhere('mac', $mac)));
            })
            ->when($site, fn ($query) => $query->where(fn ($w) => $w->doesntHave('siteLinks')
                ->orWhereHas('siteLinks', fn ($l) => $l->where('site_id', $site))))
            ->when($status === 'active', fn ($query) => $query->valid())
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($status === 'expired', fn ($query) => $query->where('expires_at', '<=', now()))
            ->orderBy('comment')->orderBy('mac')
            ->paginate(50)->withQueryString();

        return view('admin.whitelist.index', [
            'entries' => $entries,
            'filters' => ['q' => $q, 'site' => $site, 'status' => $status],
            'statuses' => self::STATUSES,
            'sites' => Site::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * Guests list "allow permanently" passes only the device id (no personal data in the URL); MAC,
     * guest name (comment) and site (latest session of the device, else the guest's latest
     * acceptance) are resolved here.
     */
    public function create(Request $request): View
    {
        $entry = new MacWhitelist(['active' => true]);
        $site = null;
        $device = is_string($request->query('device')) ? Device::query()->with('guest.latestAcceptance')->find($request->query('device')) : null;
        if ($device !== null) {
            $entry->mac = $device->mac;
            $entry->comment = $device->guest ? mb_substr($device->guest->first_name.' '.$device->guest->last_name, 0, 255) : null;
            $site = SessionSummary::query()->where('grant_id', $device->grant_id)->where('mac', $device->mac)
                ->latest('started_at')->value('site_id') ?? $device->guest?->latestAcceptance?->site_id;
        }

        return view('admin.whitelist.form', [
            'entry' => $entry,
            'selectedSites' => $site && Site::query()->whereKey($site)->exists() ? [$site] : [],
            'sites' => Site::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, new MacWhitelist);

        $entry = DB::transaction(function () use ($data, $request) {
            $entry = MacWhitelist::create([...$data['attributes'], 'created_by' => $request->user()->id]);
            $entry->syncSites($data['site_ids']);
            Audit::log('whitelist.created', $entry, $this->auditData($entry, $data['site_ids']));

            return $entry;
        });

        return redirect()->route('admin.whitelist.index', ['q' => $entry->mac])->with('status', __('portal.saved'));
    }

    public function edit(MacWhitelist $whitelist): View
    {
        return view('admin.whitelist.form', [
            'entry' => $whitelist->load(['siteLinks', 'sessions' => fn ($q) => $q->latest('started_at')->limit(20)]),
            'selectedSites' => $whitelist->siteIds(),
            'sites' => Site::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, MacWhitelist $whitelist): RedirectResponse
    {
        $data = $this->validated($request, $whitelist);

        DB::transaction(function () use ($whitelist, $data) {
            $whitelist->update($data['attributes']);
            $changed = array_values(array_diff(array_keys($whitelist->getChanges()), ['updated_at', 'version']));
            $whitelist->load('siteLinks')->syncSites($data['site_ids']);
            Audit::log('whitelist.updated', $whitelist, ['changed' => $changed, ...$this->auditData($whitelist, $data['site_ids'])]);
            $whitelist->disconnectInvalidSessions();
        });

        return back()->with('status', __('portal.saved'));
    }

    public function toggle(MacWhitelist $whitelist): RedirectResponse
    {
        DB::transaction(function () use ($whitelist) {
            $whitelist->update(['active' => ! $whitelist->active]);
            Audit::log($whitelist->active ? 'whitelist.activated' : 'whitelist.deactivated', $whitelist, ['mac' => $whitelist->mac]);
            $whitelist->disconnectInvalidSessions();
        });

        return back()->with('status', __('portal.saved'));
    }

    public function destroy(MacWhitelist $whitelist): RedirectResponse
    {
        DB::transaction(function () use ($whitelist) {
            $whitelist->delete();
            Audit::log('whitelist.deleted', $whitelist, ['mac' => $whitelist->mac]);
            $whitelist->disconnectInvalidSessions();
        });

        return redirect()->route('admin.whitelist.index')->with('status', __('portal.deleted'));
    }

    /** @return array{attributes: array, site_ids: list<string>} */
    private function validated(Request $request, MacWhitelist $entry): array
    {
        $request->merge(['mac' => Mac::normalize(is_string($request->input('mac')) ? $request->input('mac') : null)
            ?? $request->input('mac')]);
        $data = $request->validate([
            'mac' => ['required', 'string', 'regex:/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/',
                Rule::unique('mac_whitelist', 'mac')->whereNull('deleted_at')->ignore($entry->id)],
            'comment' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'active' => ['nullable', 'boolean'],
            'site_ids' => ['nullable', 'array'],
            'site_ids.*' => ['string', Rule::exists('sites', 'id')->whereNull('deleted_at')],
        ], ['mac.regex' => __('portal.whitelist.invalid_mac')]);

        return [
            'attributes' => [
                'mac' => $data['mac'],
                'comment' => $data['comment'],
                'expires_at' => LocalTime::parse($data['expires_at'] ?? null),
                'active' => (bool) ($data['active'] ?? false),
            ],
            'site_ids' => array_values($data['site_ids'] ?? []),
        ];
    }

    private function auditData(MacWhitelist $entry, array $siteIds): array
    {
        return [
            'mac' => $entry->mac,
            'active' => $entry->active,
            'expires_at' => $entry->expires_at?->toIso8601String(),
            'sites' => $siteIds,
        ];
    }
}
