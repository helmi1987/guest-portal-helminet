<?php

namespace App\Federation;

use App\Jobs\DisconnectSession;
use App\Models\MacWhitelist;
use App\Models\SessionSummary;
use App\Support\LocalInstance;
use Illuminate\Support\Facades\DB;

/**
 * Apply hooks for sessions served here (CONCEPT.md §10.4): a replicated change of a grant, device,
 * site code or MAC whitelist entry (also a change by a conflict rule, e.g. an older device unbound)
 * disconnects the local open sessions that are no longer allowed. Only the serving instance sends
 * Disconnect-Requests.
 */
class SessionHooks
{
    public function register(ApplyHooks $hooks): void
    {
        $hooks->register('grants', fn (AppliedChange $c) => $this->enforceGrants([$c->id]));
        $hooks->register('devices', fn (AppliedChange $c) => $this->enforceGrants(array_filter([
            $c->after['grant_id'] ?? null, $c->before['grant_id'] ?? null])));
        $hooks->register('site_codes', fn (AppliedChange $c) => $this->enforceGrants(
            DB::table('grants')->where('site_code_id', $c->id)->pluck('id')->all()));
        $hooks->register('mac_whitelist', fn (AppliedChange $c) => $this->enforceWhitelist($c->id));
        $hooks->register('mac_whitelist_site', fn (AppliedChange $c) => $this->enforceWhitelist(
            (string) ($c->after['mac_whitelist_id'] ?? $c->before['mac_whitelist_id'] ?? '')));
    }

    /** @param  list<string>  $grantIds */
    public function enforceGrants(array $grantIds): int
    {
        if ($grantIds === []) {
            return 0;
        }
        $sessions = SessionSummary::query()->whereIn('grant_id', array_unique($grantIds))->whereNull('stopped_at')
            ->where(fn ($q) => $q->where('serving_instance_id', app(LocalInstance::class)->id())->orWhereNull('serving_instance_id'))
            ->with(['grant' => fn ($q) => $q->withTrashed()])->get();
        $count = 0;
        foreach ($sessions as $session) {
            if ($session->accessEnded()) {
                DisconnectSession::dispatch($session->id)->afterCommit();
                $count++;
            }
        }

        return $count;
    }

    public function enforceWhitelist(string $entryId): int
    {
        // Raw writes bypass the model events that renew the RADIUS cache.
        MacWhitelist::flushCache();
        $entry = MacWhitelist::withTrashed()->find($entryId);

        return $entry?->disconnectInvalidSessions() ?? 0;
    }
}
