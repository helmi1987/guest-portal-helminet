<?php

namespace App\Console\Commands;

use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\LoginAttempt;
use App\Models\MacWhitelist;
use App\Models\MacWhitelistSite;
use App\Models\OtpChallenge;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\Setting;
use App\Models\SmsMessage;
use App\Models\TermsAcceptance;
use Illuminate\Console\Command;

/**
 * Retention purge (CONCEPT.md §9, §11). Minimal version: removes guest-related records older than
 * the retention period (including terms acceptances and the audit log); grants that are still usable
 * are kept. Tombstone handling with federation (M7).
 */
class PurgeData extends Command
{
    protected $signature = 'portal:purge';

    protected $description = 'Delete guest data older than the retention period';

    public function handle(): int
    {
        $days = (int) Setting::get('retention_days', config('portal.retention_days'));
        $cutoff = now()->subDays(max(1, $days));

        // One-time credentials are needed only for minutes.
        RadiusToken::query()->where('created_at', '<', now()->subDay())->delete();
        LoginAttempt::query()->where('created_at', '<', $cutoff)->delete();
        ApprovalRequest::withTrashed()->where('created_at', '<', $cutoff)->forceDelete();
        TermsAcceptance::withTrashed()->where('accepted_at', '<', $cutoff)->forceDelete();
        OtpChallenge::withTrashed()->where('created_at', '<', $cutoff)->forceDelete();
        SmsMessage::query()->where('created_at', '<', $cutoff)->delete();
        AuditLog::withTrashed()->where('created_at', '<', $cutoff)->forceDelete();

        // Session summaries cascade to raw accounting.
        SessionSummary::withTrashed()->whereNotNull('stopped_at')->where('stopped_at', '<', $cutoff)->forceDelete();
        Device::withTrashed()->whereNotNull('unbound_at')->where('unbound_at', '<', $cutoff)->forceDelete();

        // Ended grants (cascade: devices, site links, summaries).
        Grant::withTrashed()->where(fn ($q) => $q->where('revoked_at', '<', $cutoff)->orWhere('expires_at', '<', $cutoff))
            ->forceDelete();

        // Deleted MAC whitelist entries and removed site links (tombstones; entries cascade to links).
        MacWhitelist::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();
        MacWhitelistSite::onlyTrashed()->where('deleted_at', '<', $cutoff)->forceDelete();

        // Guests without remaining devices or tokens.
        Guest::withTrashed()->where('created_at', '<', $cutoff)
            ->whereNotIn('id', Device::withTrashed()->whereNotNull('guest_id')->select('guest_id'))
            ->forceDelete();

        $this->line("Purged data older than $days days.");

        return self::SUCCESS;
    }
}
