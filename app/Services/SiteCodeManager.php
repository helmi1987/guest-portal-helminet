<?php

namespace App\Services;

use App\Enums\ExpiryMode;
use App\Enums\GrantType;
use App\Models\Grant;
use App\Models\RadiusToken;
use App\Models\Site;
use App\Models\SiteCode;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Site code lifecycle and guest logins (CONCEPT.md §8): one active code per site; every device that
 * logs in gets its own grant (type site_code) with the code's duration from its login, so RADIUS,
 * accounting, the guest list and extend/revoke work per device. The single-device rule of normal
 * codes does not apply.
 */
class SiteCodeManager
{
    public function __construct(private CodeGenerator $codes, private GrantManager $grants) {}

    public static function current(Site $site): ?SiteCode
    {
        return SiteCode::query()->where('site_id', $site->id)->active()->latest()->first();
    }

    /** New code for the site; the previous active one is deactivated (history is kept). */
    public function generate(Site $site, int $durationS, ?Carbon $validUntil, ?int $maxDevices, ?User $by = null): SiteCode
    {
        return DB::transaction(function () use ($site, $durationS, $validUntil, $maxDevices, $by) {
            SiteCode::query()->where('site_id', $site->id)->active()->get()
                ->each(fn (SiteCode $old) => $this->deactivate($old, $by));

            return SiteCode::create([
                'site_id' => $site->id,
                'code' => $this->codes->generate(),
                'duration_s' => $durationS,
                'valid_until' => $validUntil,
                'max_devices' => $maxDevices,
                'active' => true,
                'created_by' => $by?->id,
            ]);
        });
    }

    /**
     * Deactivate: no new logins. Device grants already issued keep running until their own expiry
     * (end them with endDevices()).
     */
    public function deactivate(SiteCode $siteCode, ?User $by = null): void
    {
        $siteCode->update(['active' => false, 'deactivated_at' => $siteCode->deactivated_at ?? now(), 'deactivated_by' => $siteCode->deactivated_by ?? $by?->id]);
    }

    /** Revoke all running device grants of the code; their open sessions are disconnected. */
    public function endDevices(SiteCode $siteCode): int
    {
        $grants = $siteCode->runningGrants()->get();
        $grants->each(fn (Grant $grant) => $this->grants->revoke($grant));

        return $grants->count();
    }

    /** Active site code of this site with the given code (valid or expired), for the guest login. */
    public function find(string $code, Site $site): ?SiteCode
    {
        return SiteCode::query()->where('site_id', $site->id)->where('code', $code)->active()->latest()->first();
    }

    /**
     * Grant for a device logging in with the site code: the still running grant this MAC got from the
     * code before (no second slot for the same device), else a new one unless the code is full.
     * Returns null when max_devices is reached.
     */
    public function grantForDevice(SiteCode $siteCode, Site $site, string $mac, string $label): ?Grant
    {
        // Serialised per code so parallel logins cannot exceed max_devices.
        return Cache::lock('site-code:'.$siteCode->id, 15)->block(10, function () use ($siteCode, $site, $mac, $label) {
            $running = $siteCode->runningGrants()->pluck('id');
            $reuse = $running->isEmpty() ? null : RadiusToken::query()->whereIn('grant_id', $running)
                ->where('mac', $mac)->latest('id')->value('grant_id');
            if ($reuse !== null) {
                return Grant::query()->with('siteLinks')->find($reuse);
            }
            if ($siteCode->max_devices !== null && $running->count() >= $siteCode->max_devices) {
                return null;
            }

            return DB::transaction(function () use ($siteCode, $site, $label) {
                $grant = Grant::create([
                    'type' => GrantType::SiteCode,
                    'code' => $siteCode->code,
                    'site_code_id' => $siteCode->id,
                    'label' => $label,
                    'duration_s' => $siteCode->duration_s,
                    'expiry_mode' => ExpiryMode::Creation,
                    'expires_at' => now()->addSeconds($siteCode->duration_s),
                    // Counted as running (max_devices) from creation; the token is issued right after.
                    'first_login_at' => now(),
                    'created_by' => $siteCode->created_by,
                ]);
                $grant->syncSites([$site->id]);

                return $grant;
            });
        });
    }
}
