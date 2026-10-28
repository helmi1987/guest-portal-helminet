<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\LoginAttempt;
use App\Models\RadiusToken;
use App\Models\SessionSummary;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Retention purge (CONCEPT.md §9, §11). Minimal version: removes guest-related records older than
 * the retention period; grants that are still usable are kept. Terms acceptances and audit log
 * follow with their features; tombstone handling with federation (M7).
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

        // Session summaries cascade to raw accounting.
        SessionSummary::withTrashed()->whereNotNull('stopped_at')->where('stopped_at', '<', $cutoff)->forceDelete();
        Device::withTrashed()->whereNotNull('unbound_at')->where('unbound_at', '<', $cutoff)->forceDelete();

        // Ended grants (cascade: devices, site links, summaries).
        Grant::withTrashed()->where(fn ($q) => $q->where('revoked_at', '<', $cutoff)->orWhere('expires_at', '<', $cutoff))
            ->forceDelete();

        // Guests without remaining devices or tokens.
        Guest::withTrashed()->where('created_at', '<', $cutoff)
            ->whereNotIn('id', Device::withTrashed()->whereNotNull('guest_id')->select('guest_id'))
            ->forceDelete();

        $this->line("Purged data older than $days days.");

        return self::SUCCESS;
    }
}
