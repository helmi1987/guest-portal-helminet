<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DisconnectSession;
use App\Models\LearningAttempt;
use App\Models\LearningWindow;
use App\Models\MacWhitelist;
use App\Models\SessionSummary;
use App\Models\Site;
use App\Nas\UnifiDriver;
use App\Support\Audit;
use App\Support\LocalInstance;
use App\Support\WhitelistReauth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Learning window of the MAC whitelist per site (admin + manager, decision 2026-11-21, v0.33.0, CONCEPT.md §5):
 * open for a selectable duration, close early, take recorded devices over into the whitelist or discard them.
 * Local to this instance (sites served here, not replicated). Opening triggers the re-authentication by the
 * portal on the site's routers (else the RouterOS command is shown); closing disconnects the devices not taken over
 * (portal:enforce-grants also does once the window expired).
 */
class LearningWindowController extends Controller
{
    /** Sites a learning window can be opened for: served here, MAC authentication by the router (not UniFi). */
    public static function eligible(Site $site): bool
    {
        return $site->isServedHere() && ! $site->driver() instanceof UnifiDriver;
    }

    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'site_id' => ['required', 'string', Rule::exists('sites', 'id')->whereNull('deleted_at')],
            'minutes' => ['required', 'integer', Rule::in(LearningWindow::DURATIONS)],
        ]);
        $site = Site::query()->findOrFail($data['site_id']);
        if (! self::eligible($site)) {
            throw ValidationException::withMessages(['site_id' => __('portal.learning.not_eligible')]);
        }
        if (LearningWindow::openFor($site->id) !== null) {
            throw ValidationException::withMessages(['site_id' => __('portal.learning.already_open')]);
        }
        $minutes = (int) $data['minutes'];
        $window = LearningWindow::create(['site_id' => $site->id, 'opened_by' => $request->user()->id,
            'opened_at' => now(), 'expires_at' => now()->addMinutes($minutes)]);
        Audit::log('whitelist.learning_opened', $window, ['site' => $site->slug, 'minutes' => $minutes]);

        return back()->with('status', __('portal.learning.opened', ['site' => $site->name, 'minutes' => $minutes]))
            ->with('reauth', WhitelistReauth::trigger([$site], $request->user(), 'learning'));
    }

    public function close(LearningWindow $window): RedirectResponse
    {
        if ($window->closed_at === null) {
            $window->update(['closed_at' => now()->min($window->expires_at)]);
            $count = self::disconnectUnlearned($window->site_id);
            Audit::log('whitelist.learning_closed', $window, ['site' => $window->site?->slug, 'disconnected' => $count]);
        }

        return back()->with('status', __('portal.learning.closed'));
    }

    public function takeOver(Request $request, LearningAttempt $attempt): RedirectResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'max:255']]);
        if ($attempt->status !== LearningAttempt::PENDING) {
            return back()->withErrors(['learning' => __('portal.learning.not_pending')]);
        }
        if (MacWhitelist::query()->where('mac', $attempt->mac)->exists()) {
            return back()->withErrors(['learning' => __('portal.learning.already_listed', ['mac' => $attempt->mac])]);
        }
        $entry = DB::transaction(function () use ($attempt, $data, $request) {
            $entry = MacWhitelist::create(['mac' => $attempt->mac, 'comment' => $data['comment'], 'active' => true,
                'created_by' => $request->user()->id]);
            $entry->syncSites([$attempt->site_id]);
            $attempt->update(['status' => LearningAttempt::TAKEN, 'mac_whitelist_id' => $entry->id]);
            // The device stays online: its open learning sessions now belong to the entry
            SessionSummary::query()->whereNull('stopped_at')->whereNull('grant_id')->whereNull('mac_whitelist_id')
                ->where('site_id', $attempt->site_id)->where('mac', $attempt->mac)->get()
                ->each(fn (SessionSummary $s) => $s->update(['mac_whitelist_id' => $entry->id]));
            Audit::log('whitelist.learning_taken', $entry, ['mac' => $entry->mac, 'sites' => [$attempt->site_id], 'attempts' => $attempt->count]);

            return $entry;
        });

        return back()->with('status', __('portal.learning.taken', ['mac' => $entry->mac]));
    }

    public function discard(LearningAttempt $attempt): RedirectResponse
    {
        if ($attempt->status === LearningAttempt::PENDING) {
            $attempt->update(['status' => LearningAttempt::DISCARDED]);
            $count = self::disconnectUnlearned($attempt->site_id, $attempt->mac);
            Audit::log('whitelist.learning_discarded', $attempt, ['mac' => $attempt->mac, 'site' => $attempt->site?->slug, 'disconnected' => $count]);
        }

        return back()->with('status', __('portal.learning.discarded', ['mac' => $attempt->mac]));
    }

    /**
     * Disconnect the open learning sessions (no grant, no entry) of a site served here that are no longer allowed,
     * optionally of one MAC; returns their number. Dispatched after commit.
     */
    private static function disconnectUnlearned(string $siteId, ?string $mac = null): int
    {
        $sessions = SessionSummary::query()->whereNull('stopped_at')->whereNull('grant_id')->whereNull('mac_whitelist_id')
            ->where('site_id', $siteId)->where('serving_instance_id', app(LocalInstance::class)->id())
            ->when($mac !== null, fn ($q) => $q->where('mac', $mac))->get()
            ->reject(fn (SessionSummary $s) => LearningWindow::allows($s->site_id, $s->mac));
        foreach ($sessions as $session) {
            DisconnectSession::dispatch($session->id)->afterCommit();
        }

        return $sessions->count();
    }
}
