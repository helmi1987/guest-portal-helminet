<?php

namespace App\Nas;

use App\Models\Grant;
use App\Models\RadiusAccounting;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Nas\Unifi\UnifiSettings;
use App\Services\GrantManager;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sessions of sites whose driver authorizes through a controller (AuthorizesViaController, decision
 * 2026-11-04, v0.27.0). Without RADIUS the portal itself records what an Access-Accept and an
 * Accounting-Start record otherwise: the device binding, the used one-time token and a session summary.
 * The summary gets one local accounting row (no NAS, acct_session_id "controller:<token id>") that the
 * poller (portal:poll-controllers) updates with the controller's usage counters; the guest list and
 * portal:enforce-grants read it like RADIUS accounting.
 *
 * A summary of a controller site stands for the authorization on the controller (not for the Wi-Fi
 * association): it stays open while the grant runs, also when the device is briefly offline.
 */
final class ControllerSessions
{
    public const SESSION_PREFIX = 'controller:';

    public function __construct(private GrantManager $grants) {}

    public static function supports(?Site $site): bool
    {
        return $site !== null && $site->driver() instanceof AuthorizesViaController;
    }

    /**
     * Authorize the device of $context after a portal login and record the session. Serialised per grant
     * like the RADIUS accept (a parallel login with another MAC must not bind twice). The controller call
     * runs outside the database transaction.
     */
    public function authorize(Site $site, Grant $grant, RadiusToken $token, HotspotContext $context): ControllerAuthorization
    {
        /** @var AuthorizesViaController $driver */
        $driver = $site->driver();

        return Cache::lock('grant-bind:'.$grant->id, 15)->block(5, function () use ($driver, $site, $grant, $token, $context) {
            $grant->refresh();
            if (! $grant->isUsable() || $token->used_at !== null || $token->mac !== $context->mac) {
                return ControllerAuthorization::failed('grant_not_usable');
            }
            $seconds = $grant->remainingSeconds() ?? UnifiSettings::forSite($site)['duration_default_s'];
            $result = $driver->authorizeClient($site, $context, $grant, max(1, $seconds));
            if (! $result->granted) {
                Log::warning('Controller authorization failed', ['site' => $site->slug, 'grant' => $grant->id, 'mac' => $context->mac,
                    'reason' => $result->error, 'status' => $result->rawStatus]);

                return $result;
            }

            DB::transaction(function () use ($site, $grant, $token, $context, $result) {
                $device = $this->grants->bindDevice($grant, $context->mac, $token->guest_id);
                $token->update(['used_at' => now(), 'device_id' => $device->id]);
                // A new login of the same device at this site replaces its open session (re-authorization).
                SessionSummary::query()->where('grant_id', $grant->id)->where('mac', $context->mac)->where('site_id', $site->id)
                    ->whereNull('stopped_at')->get()->each(fn (SessionSummary $s) => $s->update(['stopped_at' => now()]));
                $summary = SessionSummary::create([
                    'grant_id' => $grant->id,
                    'site_id' => $site->id,
                    'serving_instance_id' => app(LocalInstance::class)->id(),
                    'mac' => $context->mac,
                    'ip' => $result->ip,
                    'started_at' => now(),
                ]);
                RadiusAccounting::create([
                    'session_summary_id' => $summary->id,
                    'nas_client_id' => null,
                    'acct_session_id' => self::SESSION_PREFIX.$token->id,
                    'username' => $token->username,
                    'last_update_at' => now(),
                ]);
            });
            Log::info('Controller authorization granted', ['site' => $site->slug, 'grant' => $grant->id, 'mac' => $context->mac,
                'expires_at' => $result->expiresAt?->toIso8601String(), 'status' => $result->rawStatus]);

            return $result;
        });
    }

    /**
     * Authorize a running session again with the grant's remaining time (extension or shortening by an
     * admin). UniFi resets its usage counters on every authorization.
     */
    public function reauthorize(SessionSummary $summary): bool
    {
        $site = $summary->site;
        $grant = $summary->grant;
        if (! self::supports($site) || $grant === null || ! $grant->isUsable() || $summary->stopped_at !== null) {
            return false;
        }
        $context = new HotspotContext($summary->mac, null, null, null, unifiSite: UnifiSettings::forSite($site)['unifi_site']);
        $result = $site->driver()->authorizeClient($site, $context, $grant, max(1, (int) $grant->remainingSeconds()));
        Log::info('Controller re-authorization', ['site' => $site->slug, 'grant' => $grant->id, 'mac' => $summary->mac,
            'granted' => $result->granted, 'reason' => $result->error]);

        return $result->granted;
    }

    /** Usage and last contact from the controller (poller); counters reset on re-authorization. */
    public function record(SessionSummary $summary, array $status): void
    {
        $acct = $summary->accounting()->whereNull('nas_client_id')->first();
        if ($acct === null) {
            return;
        }
        $acct->fill(['last_update_at' => now()]);
        if ($status['bytes_in'] !== null) {
            $acct->bytes_in = max(0, (int) $status['bytes_in']);
        }
        if ($status['bytes_out'] !== null) {
            $acct->bytes_out = max(0, (int) $status['bytes_out']);
        }
        $acct->save();
        if (($status['ip'] ?? null) !== null && $summary->ip !== $status['ip']) {
            $summary->update(['ip' => $status['ip']]);
        }
    }
}
