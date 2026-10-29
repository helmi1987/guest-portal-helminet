<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Federation\RemoteSessions;
use App\Http\Controllers\Controller;
use App\Jobs\DisconnectSession;
use App\Models\Guest;
use App\Models\Instance;
use App\Models\MacWhitelist;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Support\Audit;
use App\Support\LocalInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Guests and sessions (admin + manager, CONCEPT.md §9): guests with login method, accepted terms,
 * grant, devices and RADIUS sessions. Grant actions use the GrantController routes.
 */
class GuestController extends Controller
{
    public function index(Request $request, LocalInstance $instance, RemoteSessions $remote): View
    {
        $filters = $this->filters($request);

        $guests = Guest::query()
            ->with(['latestAcceptance.termsVersion', 'latestAcceptance.grant.siteLinks', 'devices'])
            ->withExists(['approvalRequests', 'otpChallenges'])
            ->when($filters['site'] ?? null, fn ($q, $site) => $q->whereHas('acceptances', fn ($a) => $a->where('site_id', $site)))
            ->when($filters['method'] ?? null, fn ($q, $method) => match (GrantType::from($method)) {
                GrantType::Code => $q->whereHas('acceptances.grant', fn ($g) => $g->where('type', GrantType::Code)),
                GrantType::SiteCode => $q->whereHas('acceptances.grant', fn ($g) => $g->where('type', GrantType::SiteCode)),
                GrantType::Sms => $q->has('otpChallenges'),
                GrantType::Approval => $q->has('approvalRequests'),
            })
            ->when($filters['active'], fn ($q) => $q->whereHas('acceptances.grant', fn ($g) => $g->withStatus(GrantStatus::Active)))
            ->when($filters['q'], function (Builder $q, string $term) {
                // "!" as escape character works the same in SQLite and MariaDB ("\\" needs quoting in MariaDB).
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
                $q->where(fn ($w) => $w->whereRaw("first_name like ? escape '!'", [$like])
                    ->orWhereRaw("last_name like ? escape '!'", [$like])
                    ->orWhereHas('acceptances.grant', fn ($g) => $g->where('code', $term)));
            })
            ->latest()->orderByDesc('id')
            ->paginate(25)->withQueryString();

        // Sessions of the listed grants, matched to devices by grant + MAC.
        $grantIds = $guests->getCollection()->map(fn (Guest $g) => $g->latestAcceptance?->grant_id)->filter()->unique();
        $sessions = SessionSummary::query()->whereIn('grant_id', $grantIds)
            ->withSum('accounting', 'bytes_in')->withSum('accounting', 'bytes_out')->withMax('accounting', 'last_update_at')
            ->latest('started_at')->get();
        // Sessions served by other instances: asked live (session summaries are local, CONCEPT.md §10.4).
        $remoteSessions = $remote->fetch($grantIds->values()->all());
        $sessions = $sessions->concat($remoteSessions['sessions'])
            ->sortByDesc(fn (SessionSummary $s) => $s->started_at?->getTimestamp() ?? 0)->values()
            ->groupBy(fn (SessionSummary $s) => $s->grant_id.'|'.$s->mac);

        // MAC whitelist entries of the listed devices ("Whitelist" badge, or "allow permanently").
        $macs = $guests->getCollection()->flatMap(fn (Guest $g) => $g->devices->pluck('mac'))->unique();
        $whitelisted = MacWhitelist::query()->whereIn('mac', $macs)->orderBy('version')->get()->keyBy('mac');

        return view('admin.guests.index', [
            'guests' => $guests,
            'sessions' => $sessions,
            'whitelisted' => $whitelisted,
            'filters' => $filters,
            'sites' => Site::query()->orderBy('name')->pluck('name', 'id'),
            'localInstance' => $instance->id(),
            'instanceNames' => Instance::withTrashed()->pluck('name', 'id'),
            'unreachable' => $remoteSessions['unreachable'],
            'staleBefore' => now()->subSeconds((int) config('portal.radius.stale_after')),
        ]);
    }

    /**
     * List filters; invalid values fall back to "no filter" instead of a validation error.
     *
     * @return array{site: ?string, method: ?string, active: bool, q: ?string}
     */
    private function filters(Request $request): array
    {
        $string = fn (string $key, int $max) => is_string($value = $request->query($key)) && trim($value) !== '' && mb_strlen($value) <= $max
            ? trim($value) : null;
        $site = $string('site', 26);

        return [
            'site' => $site !== null && Site::query()->whereKey($site)->exists() ? $site : null,
            'method' => GrantType::tryFrom((string) $string('method', 20))?->value,
            'active' => filter_var($request->query('active'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'q' => $string('q', 100),
        ];
    }

    /** End the device's RADIUS session on the router (Disconnect-Request); the grant stays valid. */
    public function disconnect(SessionSummary $session, LocalInstance $instance): RedirectResponse
    {
        if ($session->stopped_at !== null) {
            return back()->withErrors(['session' => __('portal.guests.not_online')]);
        }
        if ($session->serving_instance_id !== $instance->id()) {
            return back()->withErrors(['session' => __('portal.guests.other_instance')]);
        }

        // Remembered: roaming MAC authentication does not log the device in again at this site.
        $session->update(['kicked_at' => now()]);
        DisconnectSession::dispatch($session->id);
        $session->mac_whitelist_id !== null
            ? Audit::log('whitelist.disconnected', $session->macWhitelist, ['session' => $session->id, 'site' => $session->site_id])
            : Audit::log('grant.disconnected', $session->grant, ['session' => $session->id, 'site' => $session->site_id]);

        return back()->with('status', __('portal.guests.disconnect_sent'));
    }

    /** Disconnect a session served by another instance: asked at the serving instance (through the hub). */
    public function disconnectRemote(Instance $instance, string $session, RemoteSessions $remote): RedirectResponse
    {
        abort_unless(preg_match('/^[0-9A-Za-z]{26}$/', $session) === 1, 404);
        try {
            $status = $remote->disconnect($instance->id, $session);
        } catch (Throwable $e) {
            Log::warning('Remote disconnect failed', ['instance' => $instance->id, 'session' => $session, 'error' => $e->getMessage()]);

            return back()->withErrors(['session' => __('portal.guests.remote_unreachable_disconnect', ['name' => $instance->name])]);
        }
        if ($status !== 'sent') {
            return back()->withErrors(['session' => __('portal.guests.not_online')]);
        }
        Audit::log('federation.remote_disconnect', $instance, ['session' => $session, 'instance' => $instance->name]);

        return back()->with('status', __('portal.guests.disconnect_sent'));
    }
}
