<?php

namespace App\Jobs;

use App\Models\NasClient;
use App\Models\User;
use App\Nas\RouterOs\RouterReauth;
use App\Support\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-authentication by the portal on one router (decision 2026-11-21, v0.33.0): runs the own script through the
 * read-only API access (RouterReauth) and records the result in the audit log ("router.reauth"). One try: the
 * admin can press "Re-authenticate" again.
 */
class ReauthRouter implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    /** @param  string  $reason  what triggered it: "whitelist" (entry saved or button) or "learning" (window opened) */
    public function __construct(public string $nasId, public ?string $userId, public string $reason, public ?string $mac = null)
    {
        $this->timeout = (int) config('portal.router_api.deadline', 45) + 15;
    }

    public function handle(RouterReauth $reauth): void
    {
        $nas = NasClient::with('site')->find($this->nasId);
        if ($nas === null) {
            return;
        }
        $error = $reauth->run($nas);
        $user = $this->userId !== null ? User::withTrashed()->find($this->userId) : null;
        Audit::log('router.reauth', $nas, array_filter(['site' => $nas->site?->slug, 'name' => $nas->name, 'reason' => $this->reason, 'mac' => $this->mac,
            'ok' => $error === null, 'error' => $error['kind'] ?? null, 'detail' => $error['detail'] ?? null], fn ($v) => $v !== null), $user);
    }
}
