<?php

namespace App\Services;

use App\Models\LoginAttempt;

/**
 * Guest login brute-force protection based on recorded failures (CONCEPT.md §8):
 * per MAC, per IP and global, within a sliding window.
 */
class LoginThrottle
{
    public function isLocked(?string $mac, ?string $ip): bool
    {
        $limits = config('portal.login_limits');
        $since = now()->subMinutes($limits['window_minutes']);
        $failures = fn () => LoginAttempt::query()->where('success', false)->where('created_at', '>=', $since);

        return ($mac !== null && $failures()->where('mac', $mac)->count() >= $limits['per_mac'])
            || ($ip !== null && $failures()->where('ip', $ip)->count() >= $limits['per_ip'])
            || $failures()->count() >= $limits['global'];
    }

    public function record(?string $mac, ?string $ip, ?string $siteId, string $method, bool $success): void
    {
        LoginAttempt::create([
            'mac' => $mac,
            'ip' => $ip,
            'site_id' => $siteId,
            'method' => $method,
            'success' => $success,
            'created_at' => now(),
        ]);
    }
}
