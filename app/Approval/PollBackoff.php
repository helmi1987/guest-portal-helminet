<?php

namespace App\Approval;

use Illuminate\Support\Facades\Cache;

/**
 * Exponential backoff per Telegram channel for the poller: after a failure the channel is skipped
 * for 10 s, doubling up to 5 min; reset on success. Kept in the cache so the hourly restart of
 * the poller does not reset it.
 */
class PollBackoff
{
    public const FIRST = 10;

    public const MAX = 300;

    /** True if the channel may be polled now. */
    public function ready(string $key): bool
    {
        return $this->state($key)['until'] <= now()->getTimestamp();
    }

    /** Records a failure; returns the delay in seconds until the next attempt. */
    public function failed(string $key): int
    {
        $state = $this->state($key);
        $delay = $state['delay'] === 0 ? self::FIRST : min(self::MAX, $state['delay'] * 2);
        $this->put($key, ['delay' => $delay, 'until' => now()->getTimestamp() + $delay, 'failures' => $state['failures'] + 1]);

        return $delay;
    }

    /** Records a success; returns the number of failures before it (0 = was not failing). */
    public function succeeded(string $key): int
    {
        $failures = $this->state($key)['failures'];
        if ($failures > 0) {
            Cache::forget($this->cacheKey($key));
        }

        return $failures;
    }

    /** Seconds until the first of the given channels is ready again (0 = one is ready). */
    public function wait(array $keys): int
    {
        $now = now()->getTimestamp();

        return max(0, (int) min(array_map(fn (string $key) => $this->state($key)['until'] - $now, $keys) ?: [0]));
    }

    /** @return array{delay: int, until: int, failures: int} */
    private function state(string $key): array
    {
        $state = Cache::get($this->cacheKey($key));

        return is_array($state) ? $state + ['delay' => 0, 'until' => 0, 'failures' => 0] : ['delay' => 0, 'until' => 0, 'failures' => 0];
    }

    private function put(string $key, array $state): void
    {
        Cache::put($this->cacheKey($key), $state, now()->addHours(2));
    }

    private function cacheKey(string $key): string
    {
        return 'telegram-poll-backoff:'.$key;
    }
}
