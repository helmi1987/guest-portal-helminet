<?php

namespace App\Http\Controllers\Admin;

use App\Branding\BrandingSettings;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\SiteCode;
use App\Services\SiteCodeManager;
use App\Services\VoucherQr;
use App\Support\Audit;
use App\Support\LocalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Site codes (admin + manager, CONCEPT.md §8): current code and history per site, generate,
 * change, deactivate, end all devices, printable notice (A4/A6). Audited; the code itself is never
 * written to the audit log.
 */
class SiteCodeController extends Controller
{
    public function index(): View
    {
        $sites = Site::query()->orderBy('name')->get();
        $current = SiteCode::query()->active()->whereIn('site_id', $sites->pluck('id'))
            ->withCount('runningGrants')->latest()->get()->unique('site_id')->keyBy('site_id');

        return view('admin.site-codes.index', ['sites' => $sites, 'current' => $current]);
    }

    public function show(Site $site): View
    {
        $history = SiteCode::query()->where('site_id', $site->id)
            // Login counts include deleted (ended) device grants until the retention purge removes them.
            ->with(['creator', 'deactivator'])->withCount(['grants' => fn ($q) => $q->withTrashed(), 'runningGrants'])
            ->latest()->orderByDesc('id')->get();

        return view('admin.site-codes.show', [
            'site' => $site,
            'current' => $history->first(fn (SiteCode $c) => $c->isActive()),
            'history' => $history,
        ]);
    }

    public function store(Request $request, Site $site, SiteCodeManager $manager): RedirectResponse
    {
        $data = $this->validated($request);
        $siteCode = $manager->generate($site, $data['duration_s'], $data['valid_until'], $data['max_devices'], $request->user());
        Audit::log('site_code.created', $siteCode, $this->auditData($site, $siteCode));

        return redirect()->route('admin.site-codes.show', $site)->with('status', __('portal.site_codes.generated'));
    }

    /** Change duration, expiry and device limit of the current code (new logins only). */
    public function update(Request $request, Site $site): RedirectResponse
    {
        $siteCode = SiteCodeManager::current($site);
        abort_if($siteCode === null, 404);
        $data = $this->validated($request);
        $siteCode->update($data);
        Audit::log('site_code.updated', $siteCode, [
            ...$this->auditData($site, $siteCode),
            'changed' => array_values(array_diff(array_keys($siteCode->getChanges()), ['updated_at', 'version'])),
        ]);

        return back()->with('status', __('portal.saved'));
    }

    public function deactivate(Request $request, Site $site, SiteCodeManager $manager): RedirectResponse
    {
        $siteCode = SiteCodeManager::current($site);
        abort_if($siteCode === null, 404);
        $manager->deactivate($siteCode, $request->user());
        Audit::log('site_code.deactivated', $siteCode, ['site' => $site->slug]);

        return back()->with('status', __('portal.site_codes.deactivated'));
    }

    /** Revoke the running device grants of one code of this site (current or from the history). */
    public function endDevices(Request $request, Site $site, SiteCodeManager $manager): RedirectResponse
    {
        $siteCode = SiteCode::query()->where('site_id', $site->id)->findOrFail((string) $request->input('site_code'));
        $count = $manager->endDevices($siteCode);
        Audit::log('site_code.disconnect_all', $siteCode, ['site' => $site->slug, 'count' => $count]);

        return back()->with('status', trans_choice('portal.site_codes.devices_ended', $count, ['count' => $count]));
    }

    /** Printable notice (light print styling), A4 or A6. */
    public function print(Request $request, Site $site, VoucherQr $qr): View
    {
        $siteCode = SiteCodeManager::current($site);
        abort_if($siteCode === null, 404);
        $format = $request->query('format') === 'a6' ? 'a6' : 'a4';
        Audit::log('site_code.printed', $siteCode, ['site' => $site->slug, 'format' => $format]);
        $ssid = VoucherQr::ssidForSite($site);

        return view('admin.site-codes.print', [
            'site' => $site,
            'siteCode' => $siteCode,
            'format' => $format,
            'branding' => BrandingSettings::resolve($site),
            'ssid' => $ssid,
            'wifiQr' => $ssid && ! $site->wifiPasswordUnavailable() ? $qr->wifiSvg($ssid, 240, $site->wifiPassword()) : null,
            'wifiUnavailable' => $ssid && $site->wifiPasswordUnavailable(),
        ]);
    }

    /** @return array{duration_s: int, valid_until: ?Carbon, max_devices: ?int} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'duration' => ['required', Rule::in([...array_keys(config('portal.duration_presets')), 'custom'])],
            'custom_hours' => ['required_if:duration,custom', 'nullable', 'numeric', 'min:0.25', 'max:8760'],
            'valid_until' => ['nullable', 'date'],
            'max_devices' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        $validUntil = LocalTime::parse($data['valid_until'] ?? null);
        if ($validUntil?->isPast()) {
            throw ValidationException::withMessages(['valid_until' => __('portal.site_codes.valid_until_past')]);
        }

        return [
            'duration_s' => $data['duration'] === 'custom'
                ? (int) round($data['custom_hours'] * 3600)
                : config('portal.duration_presets')[$data['duration']],
            'valid_until' => $validUntil,
            'max_devices' => isset($data['max_devices']) ? (int) $data['max_devices'] : null,
        ];
    }

    private function auditData(Site $site, SiteCode $siteCode): array
    {
        return [
            'site' => $site->slug,
            'duration_s' => $siteCode->duration_s,
            'valid_until' => $siteCode->valid_until?->toIso8601String(),
            'max_devices' => $siteCode->max_devices,
        ];
    }
}
