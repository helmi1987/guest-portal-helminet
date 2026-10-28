<?php

namespace App\Services;

use App\Models\LoginAttempt;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Lockout check, attempt and recording under one lock (atomic). Returns false when locked,
     * otherwise the attempt's result (null = failure).
     */
    public function attempt(?string $mac, ?string $ip, ?string $siteId, string $method, callable $try): mixed
    {
        return Cache::lock('guest-login-throttle', 15)->block(10, function () use ($mac, $ip, $siteId, $method, $try) {
            if ($this->isLocked($mac, $ip)) {
                return false;
            }
            $result = $try();
            $this->record($mac, $ip, $siteId, $method, $result !== null);

            return $result;
        });
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
