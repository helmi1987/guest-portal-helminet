<?php

namespace App\Console\Commands;

use App\Federation\Retention;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\LoginAttempt;
use App\Models\OtpChallenge;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\SmsMessage;
use App\Models\TermsAcceptance;
use Illuminate\Console\Command;

/**
 * Retention purge (CONCEPT.md §9, §10.3, §11): removes guest-related records older than the retention
 * period (including terms acceptances and the audit log); grants that are still usable are kept.
 * Local only, no federation events. Replicated rows are purged only when their last change (version)
 * is beyond the purge horizon as well, so no pending event can bring them back; tombstones and the
 * change log follow the acknowledgements of the peers (App\Federation\Retention).
 */
class PurgeData extends Command
{
    protected $signature = 'portal:purge';

    protected $description = 'Delete guest data older than the retention period';

    public function handle(Retention $retention): int
    {
        $days = Retention::days();
        $cutoff = now()->subDays($days);
        $horizon = Retention::horizonVersion();

        // One-time credentials are needed only for minutes.
        RadiusToken::query()->where('created_at', '<', now()->subDay())->delete();
        LoginAttempt::query()->where('created_at', '<', $cutoff)->delete();
        ApprovalRequest::withTrashed()->where('created_at', '<', $cutoff)->forceDelete();
        TermsAcceptance::withTrashed()->where('accepted_at', '<', $cutoff)->where('version', '<', $horizon)->forceDelete();
        OtpChallenge::withTrashed()->where('created_at', '<', $cutoff)->forceDelete();
        SmsMessage::query()->where('created_at', '<', $cutoff)->delete();
        AuditLog::withTrashed()->where('created_at', '<', $cutoff)->where('version', '<', $horizon)->forceDelete();

        // Session summaries cascade to raw accounting.
        SessionSummary::withTrashed()->whereNotNull('stopped_at')->where('stopped_at', '<', $cutoff)->forceDelete();
        Device::withTrashed()->whereNotNull('unbound_at')->where('unbound_at', '<', $cutoff)->where('version', '<', $horizon)
            ->forceDelete();

        // Ended grants (cascade: devices, site links, summaries).
        Grant::withTrashed()->where(fn ($q) => $q->where('revoked_at', '<', $cutoff)->orWhere('expires_at', '<', $cutoff))
            ->where('version', '<', $horizon)->forceDelete();

        // Guests without remaining devices or tokens.
        Guest::withTrashed()->where('created_at', '<', $cutoff)->where('version', '<', $horizon)
            ->whereNotIn('id', Device::withTrashed()->whereNotNull('guest_id')->select('guest_id'))
            ->forceDelete();

        // Tombstones (e.g. deleted MAC whitelist entries and site links) once every peer has them,
        // then the change log.
        $tombstones = array_sum($retention->purgeTombstones());
        $events = $retention->compactLog();
        $retention->purgeJoinTokens();

        $this->line("Purged data older than $days days ($tombstones tombstones, $events change log events).");

        return self::SUCCESS;
    }
}
