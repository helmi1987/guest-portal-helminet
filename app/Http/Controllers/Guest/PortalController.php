<?php

namespace App\Http\Controllers\Guest;

use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Http\Controllers\Controller;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\RadiusToken;
use App\Models\Site;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Nas\HotspotContext;
use App\Services\GrantManager;
use App\Services\LoginThrottle;
use Carbon\CarbonInterval;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Guest login with a code (CONCEPT.md §7.1, §7.2 A), status page and error pages.
 */
class PortalController extends Controller
{
    /** Entry from the router's login.html redirect; parameters are kept in the session. */
    public function show(Request $request, LoginThrottle $throttle): View|Response
    {
        if ($request->filled('site')) {
            $site = Site::query()->where('slug', $request->query('site'))->first();
            $context = $site?->driver()->parseRedirect($request, $site);
            $request->session()->put('portal', $site ? ['site' => $site->id, 'context' => $context->toArray()] : null);
            // Router redirect with a login URL that is not a configured hotspot host, or an unknown site.
            $request->session()->put('portal_misconfigured', $site === null
                || ($request->filled('link-login-only') && $context->loginUrl === null));
        }

        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete()) {
            return $this->error($request->session()->get('portal_misconfigured') ? 'misconfigured' : 'no_hotspot');
        }
        if ($throttle->isLocked($context->mac, $request->ip())) {
            return $this->error('locked', 429);
        }

        return view('guest.portal', [
            'site' => $site,
            'terms' => TermsVersion::current(app()->getLocale()),
            'routerFailed' => $this->previousHandoffFailed($request),
        ]);
    }

    public function redeem(Request $request, GrantManager $grants, LoginThrottle $throttle): View|Response|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:26'],
            'code' => ['required', 'string', 'max:20'],
        ]);

        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete()) {
            return redirect()->route('guest.portal');
        }

        // The guest must have accepted the version that is current now (it may change meanwhile).
        $terms = TermsVersion::current(app()->getLocale());
        if (($data['terms_version'] ?? null) !== $terms?->id) {
            throw ValidationException::withMessages(['terms' => __('portal.guest.errors.terms_changed')]);
        }

        $mac = $context->mac;
        $ip = $request->ip();
        $code = preg_replace('/\D/', '', $data['code']);

        // Check, lookup and record atomically so parallel guesses cannot exceed the limits.
        $grant = $throttle->attempt($mac, $ip, $site->id, 'code',
            fn () => strlen($code) === 6 ? $grants->findByCode($code, $site) : null);

        if ($grant === false) {
            return $this->error('locked', 429);
        }
        if ($grant === null) {
            // Known but ended codes get a specific page; the attempt still counts as a failure.
            $ended = strlen($code) === 6 ? $this->endedStatus($code, $site) : null;
            if ($ended !== null) {
                return $this->error($ended->value, 403);
            }
            throw ValidationException::withMessages(['code' => __('portal.guest.errors.invalid_code')]);
        }

        [$token, $password] = DB::transaction(function () use ($data, $grants, $grant, $site, $mac, $terms) {
            $guest = Guest::create([
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'locale' => app()->getLocale(),
            ]);
            TermsAcceptance::create([
                'guest_id' => $guest->id,
                'terms_version_id' => $terms?->id,
                'grant_id' => $grant->id,
                'site_id' => $site->id,
                'mac' => $mac,
                'accepted_at' => now(),
            ]);

            return $grants->issueToken($grant, $guest, $site, $mac);
        });
        Log::info('Guest code redeemed', ['grant' => $grant->id, 'site' => $site->slug, 'mac' => $mac,
            'terms_version' => $terms?->number]);

        $request->session()->put('handoff_token', $token->id);
        $statusUrl = url(URL::signedRoute('guest.status', ['grant' => $grant->id, 'token' => $token->id], absolute: false));

        return view('guest.handoff', $site->driver()->loginHandoff($context, $token->username, $password, $statusUrl));
    }

    /** Target of the router after the login (dst): remaining time of the grant. Signed URL, no cookie needed. */
    public function status(Request $request, string $grant, int $token): View|Response
    {
        $grant = Grant::find($grant);
        $token = RadiusToken::query()->where('grant_id', $grant?->id)->find($token);
        if ($grant === null) {
            return $this->error('expired', 403);
        }

        $status = $grant->status();
        if (in_array($status, [GrantStatus::Revoked, GrantStatus::Expired], true)) {
            return $this->error($status->value, 403);
        }
        if ($token !== null && $token->used_at === null) {
            // The router has not confirmed the login (yet): wait briefly, then report a failure.
            return $token->expires_at->isFuture()
                ? view('guest.waiting')
                : $this->error('router_failed');
        }

        $remaining = $grant->remainingSeconds();

        return view('guest.status', [
            'remaining' => $remaining === null ? null : CarbonInterval::seconds(max(60, $remaining))->cascade()
                ->locale(app()->getLocale())->forHumans(['parts' => 2, 'join' => true]),
        ]);
    }

    private function error(string $kind, int $status = 200): Response
    {
        return response()->view('guest.error', ['kind' => $kind], $status);
    }

    /** Status of an ended code grant for this site (expired/revoked), null if unknown or usable. */
    private function endedStatus(string $code, Site $site): ?GrantStatus
    {
        $statuses = Grant::query()->with('siteLinks')->where('type', GrantType::Code)->where('code', $code)->get()
            ->filter(fn (Grant $g) => $g->allowsSite($site->id))
            ->map(fn (Grant $g) => $g->status());

        return $statuses->first(fn (GrantStatus $s) => $s === GrantStatus::Revoked)
            ?? $statuses->first(fn (GrantStatus $s) => $s === GrantStatus::Expired);
    }

    /** True once if the last handed-off credentials were never accepted by RADIUS (router login failed). */
    private function previousHandoffFailed(Request $request): bool
    {
        $id = $request->session()->pull('handoff_token');
        $token = $id ? RadiusToken::find($id) : null;

        return $token !== null && $token->used_at === null && $token->created_at->gt(now()->subMinutes(10));
    }

    /** @return array{0: ?Site, 1: HotspotContext} */
    private function current(Request $request): array
    {
        $portal = $request->session()->get('portal');
        $site = $portal ? Site::find($portal['site']) : null;

        return [$site, HotspotContext::fromArray($site ? $portal['context'] : [])];
    }
}
