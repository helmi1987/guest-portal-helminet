<?php

namespace App\Http\Controllers\Guest;

use App\Approval\ApprovalService;
use App\Branding\BrandingSettings;
use App\Enums\ApprovalStatus;
use App\Enums\GrantStatus;
use App\Enums\GrantType;
use App\Federation\Topology;
use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Channel;
use App\Models\Grant;
use App\Models\Guest;
use App\Models\OtpChallenge;
use App\Models\RadiusToken;
use App\Models\Site;
use App\Models\SiteCode;
use App\Models\TermsAcceptance;
use App\Models\TermsVersion;
use App\Nas\HotspotContext;
use App\Services\AccessEndedNotice;
use App\Services\GrantManager;
use App\Services\LoginThrottle;
use App\Services\SiteCodeManager;
use App\Sms\OtpService;
use App\Sms\PhoneNumber;
use App\Sms\SmsSettings;
use App\Support\Redact;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Guest login with a code, SMS code or push approval (CONCEPT.md §7.1, §7.2 A-C), status page and error pages.
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
        // Prefill only (?code=, also inside the router's link-orig); name and terms are still required.
        if (($prefill = $this->prefillCode($request)) !== null) {
            $request->session()->put('portal_code', $prefill);
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
            // Limited mode (hub unreachable, CONCEPT.md §10.1): no push approval for new logins.
            'limitedMode' => $limited = app(Topology::class)->limitedMode(),
            'approvalEnabled' => ! $limited && Channel::query()->activeNotification()->exists(),
            'smsEnabled' => OtpService::enabled(),
            'prefillCode' => $request->session()->get('portal_code'),
            // Returning device whose access ended (expired, revoked, disconnected); reason only.
            'endedNotice' => AccessEndedNotice::for($context->mac, $site),
        ]);
    }

    public function redeem(Request $request, GrantManager $grants, SiteCodeManager $siteCodes, LoginThrottle $throttle): View|Response|RedirectResponse
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

        // Check, lookup and record atomically so parallel guesses cannot exceed the limits. One input
        // for both: a normal code first, else the valid site code of this site.
        $grant = $throttle->attempt($mac, $ip, $site->id, 'code', function () use ($code, $site, $grants, $siteCodes) {
            if (strlen($code) !== 6) {
                return null;
            }
            $siteCode = $siteCodes->find($code, $site);

            return $grants->findByCode($code, $site) ?? ($siteCode?->isValid() ? $siteCode : null);
        });

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
        if ($grant instanceof Grant && ! $grant->isAllowedWhileOffline() && $grant->activeDevice()?->mac !== $mac
            && app(Topology::class)->limitedMode()) {
            // Hub unreachable: new logins only by SMS or site code; a device already bound to the code keeps working.
            throw ValidationException::withMessages(['code' => __('portal.guest.errors.limited_mode')]);
        }
        if ($grant instanceof SiteCode) {
            // Each device gets its own grant; the single-device rule of normal codes does not apply.
            $grant = $siteCodes->grantForDevice($grant, $site, $mac, trim($data['first_name']).' '.trim($data['last_name']));
            if ($grant === null) {
                return $this->error('site_code_full', 403);
            }
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
            'terms_version' => $terms?->number, 'site_code' => $grant->site_code_id !== null]);
        $request->session()->forget('portal_code');

        return $this->handoff($request, $grant, $site, $context, $token, $password);
    }

    /** Ask an approver for access (CONCEPT.md §7.2 C); the guest then waits on the approval page. */
    public function requestApproval(Request $request, ApprovalService $approvals, LoginThrottle $throttle): Response|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:26'],
        ]);

        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete() || ! Channel::query()->activeNotification()->exists()) {
            return redirect()->route('guest.portal');
        }
        if (app(Topology::class)->limitedMode()) {
            throw ValidationException::withMessages(['approval' => __('portal.guest.errors.limited_mode')]);
        }
        $terms = TermsVersion::current(app()->getLocale());
        if (($data['terms_version'] ?? null) !== $terms?->id) {
            throw ValidationException::withMessages(['terms' => __('portal.guest.errors.terms_changed')]);
        }

        $mac = $context->mac;
        $recent = ApprovalRequest::query()->where('site_id', $site->id)->where('mac', $mac)
            ->where('created_at', '>=', now()->subMinutes(ApprovalService::TTL_MINUTES))->latest()->get();
        $pending = $recent->first(fn (ApprovalRequest $r) => $r->isPending());
        if ($pending === null) {
            // Limit notifications per device: at most 3 requests per 15 minutes.
            if ($recent->count() >= 3 || $throttle->isLocked($mac, $request->ip())) {
                return $this->error('locked', 429);
            }
            // Global cap per site against notification flooding (e.g. random MACs).
            $open = ApprovalRequest::query()->where('site_id', $site->id)->where('status', ApprovalStatus::Pending)
                ->where('expires_at', '>', now())->count();
            if ($open >= config('portal.approval.max_pending_per_site')) {
                return $this->error('approval_busy', 429);
            }
            $pending = DB::transaction(function () use ($data, $approvals, $site, $mac, $terms) {
                $guest = Guest::create([
                    'first_name' => trim($data['first_name']),
                    'last_name' => trim($data['last_name']),
                    'locale' => app()->getLocale(),
                ]);
                TermsAcceptance::create([
                    'guest_id' => $guest->id,
                    'terms_version_id' => $terms?->id,
                    'site_id' => $site->id,
                    'mac' => $mac,
                    'accepted_at' => now(),
                ]);

                return $approvals->create($guest, $site, $mac);
            });
        }

        $request->session()->put('approval_request', $pending->id);

        return redirect()->route('guest.approval');
    }

    /** Waiting page (reloads itself); logs in automatically once approved. */
    public function approval(Request $request, GrantManager $grants): View|Response|RedirectResponse
    {
        $approval = ApprovalRequest::query()->with('grant')->find($request->session()->get('approval_request'));
        [$site, $context] = $this->current($request);
        if ($approval === null || $site === null || $site->id !== $approval->site_id || ! $context->isComplete()
            || $context->mac !== $approval->mac) {
            return redirect()->route('guest.portal');
        }

        return match ($approval->currentStatus()) {
            ApprovalStatus::Pending => view('guest.approval'),
            ApprovalStatus::Denied => $this->error('approval_denied', 403),
            ApprovalStatus::Expired => $this->error('approval_expired', 403),
            ApprovalStatus::Approved => $this->approvedHandoff($request, $grants, $approval, $site, $context),
        };
    }

    /** Send an SMS code (CONCEPT.md §7.2 B); the guest then enters it on the SMS page. */
    public function requestSms(Request $request, OtpService $otp, LoginThrottle $throttle): Response|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'terms' => ['accepted'],
            'terms_version' => ['nullable', 'string', 'max:26'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete() || ! OtpService::enabled()) {
            return redirect()->route('guest.portal');
        }
        $terms = TermsVersion::current(app()->getLocale());
        if (($data['terms_version'] ?? null) !== $terms?->id) {
            throw ValidationException::withMessages(['terms' => __('portal.guest.errors.terms_changed')]);
        }
        [$phone, $error] = PhoneNumber::normalise($data['phone'], SmsSettings::countries());
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('portal.guest.errors.phone_'.$error,
                ['countries' => implode(', ', SmsSettings::countries())])]);
        }

        if ($throttle->isLocked($context->mac, $request->ip())) {
            return $this->error('locked', 429);
        }
        // Over a cost cap nothing is stored; the guest is asked to try later.
        $challenge = $otp->start($data, $terms, $site, $context->mac, $phone);
        if ($challenge === null) {
            return $this->error('sms_busy', 429);
        }
        $request->session()->put('otp_challenge', $challenge->id);

        return redirect()->route('guest.sms');
    }

    /** Code entry page; after a successful verification it hands off (again) to the router. */
    public function sms(Request $request, GrantManager $grants): View|Response|RedirectResponse
    {
        [$challenge, $site, $context] = $this->currentChallenge($request);
        if ($challenge === null) {
            return redirect()->route('guest.portal');
        }
        if ($challenge->grant_id !== null) {
            return $this->grantHandoff($request, $grants, $challenge->grant, $challenge->guest, $site, $context);
        }
        if (! $challenge->isOpen()) {
            return $this->error('sms_failed', 403);
        }

        return view('guest.sms', [
            'phone' => Redact::phone($challenge->phone_e164),
            'canResend' => $challenge->sends < config('portal.sms.max_sends'),
        ]);
    }

    public function verifySms(Request $request, OtpService $otp, GrantManager $grants, LoginThrottle $throttle): View|Response|RedirectResponse
    {
        $code = preg_replace('/\D/', '', (string) $request->validate(['otp' => ['required', 'string', 'max:20']])['otp']);
        [$challenge, $site, $context] = $this->currentChallenge($request);
        if ($challenge === null || $challenge->grant_id !== null) {
            return redirect()->route('guest.sms');
        }

        try {
            $grant = $throttle->attempt($context->mac, $request->ip(), $site->id, 'sms', fn () => $otp->verify($challenge, $code));
        } catch (LockTimeoutException) {
            // Busy (many parallel logins); nothing was recorded, the guest can retry.
            return $this->error('sms_busy', 429);
        }
        if ($grant === false) {
            return $this->error('locked', 429);
        }
        if ($grant === null) {
            if (! $challenge->isOpen()) {
                return $this->error('sms_failed', 403);
            }
            throw ValidationException::withMessages(['otp' => __('portal.guest.errors.invalid_otp',
                ['tries' => config('portal.sms.max_attempts') - $challenge->attempts])]);
        }

        return $this->grantHandoff($request, $grants, $grant, $challenge->guest, $site, $context);
    }

    public function resendSms(Request $request, OtpService $otp): Response|RedirectResponse
    {
        [$challenge] = $this->currentChallenge($request);
        if ($challenge === null || ! $challenge->isOpen()) {
            return redirect()->route('guest.sms');
        }

        return match ($otp->resend($challenge)) {
            'sent' => redirect()->route('guest.sms')->with('status', __('portal.guest.sms_resent')),
            'closed' => redirect()->route('guest.sms'),
            'wait' => redirect()->route('guest.sms')->withErrors(['otp' => __('portal.guest.errors.sms_wait',
                ['seconds' => config('portal.sms.resend_after_s')])]),
            'limit' => redirect()->route('guest.sms')->withErrors(['otp' => __('portal.guest.errors.sms_limit')]),
            default => $this->error('sms_busy', 429),
        };
    }

    /** @return array{0: ?OtpChallenge, 1: ?Site, 2: HotspotContext} challenge of this session, site and device */
    private function currentChallenge(Request $request): array
    {
        $challenge = OtpChallenge::query()->with(['grant', 'guest'])->find($request->session()->get('otp_challenge'));
        [$site, $context] = $this->current($request);
        if ($challenge === null || $site === null || $site->id !== $challenge->site_id || ! $context->isComplete()
            || $context->mac !== $challenge->mac) {
            return [null, $site, $context];
        }

        return [$challenge, $site, $context];
    }

    private function approvedHandoff(Request $request, GrantManager $grants, ApprovalRequest $approval, Site $site, HotspotContext $context): View|Response
    {
        return $this->grantHandoff($request, $grants, $approval->grant, $approval->guest, $site, $context);
    }

    /** Handoff for a site-bound grant (approval, SMS); the context MAC was checked by the caller. */
    private function grantHandoff(Request $request, GrantManager $grants, ?Grant $grant, Guest $guest, Site $site, HotspotContext $context): View|Response
    {
        if ($grant === null || ! $grant->isUsable()) {
            return $this->error($grant?->status() === GrantStatus::Revoked ? 'revoked' : 'expired', 403);
        }
        // Each visit issues fresh one-time credentials, so a failed router login can be retried.
        [$token, $password] = $grants->issueToken($grant, $guest, $site, $context->mac);

        return $this->handoff($request, $grant, $site, $context, $token, $password);
    }

    private function handoff(Request $request, Grant $grant, Site $site, HotspotContext $context, RadiusToken $token, string $password): View
    {
        $request->session()->put('handoff_token', $token->id);
        // Valid until the grant ends (plus a margin for the "expired" page).
        $statusUrl = url(URL::temporarySignedRoute('guest.status', $grant->refresh()->expires_at->copy()->addHour(),
            ['grant' => $grant->id, 'token' => $token->id], absolute: false));

        return view('guest.handoff', $site->driver()->loginHandoff($context, $token->username, $password, $statusUrl));
    }

    /** Target of the router after the login (dst): remaining time of the grant. Signed URL, no cookie needed. */
    public function status(Request $request, string $grant, int $token): View|Response
    {
        $grant = Grant::find($grant);
        $token = RadiusToken::query()->where('grant_id', $grant?->id)->find($token);
        if ($token !== null) {
            // Signed link without cookie: the site of the login decides the branding.
            $request->attributes->set(BrandingSettings::SITE_ATTRIBUTE, Site::find($token->site_id));
        }
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

    /**
     * Status of an ended code grant for this site (expired/revoked), null if unknown or usable. A site
     * code of this site that expired or was deactivated (replaced) counts as expired.
     */
    private function endedStatus(string $code, Site $site): ?GrantStatus
    {
        $statuses = Grant::query()->with('siteLinks')->where('type', GrantType::Code)->where('code', $code)->get()
            ->filter(fn (Grant $g) => $g->allowsSite($site->id))
            ->map(fn (Grant $g) => $g->status());

        return $statuses->first(fn (GrantStatus $s) => $s === GrantStatus::Revoked)
            ?? $statuses->first(fn (GrantStatus $s) => $s === GrantStatus::Expired)
            ?? (SiteCode::query()->where('site_id', $site->id)->where('code', $code)->get()
                ->contains(fn (SiteCode $c) => ! $c->isValid()) ? GrantStatus::Expired : null);
    }

    /** 6-digit code from ?code= or from a portal URL with ?code= in the router's link-orig (prefill only). */
    private function prefillCode(Request $request): ?string
    {
        $code = $request->query('code');
        if (! is_string($code)) {
            $orig = $request->query('link-orig');
            $path = is_string($orig) ? (string) parse_url($orig, PHP_URL_PATH) : '';
            parse_str(is_string($orig) && str_ends_with($path, '/portal') ? (string) parse_url($orig, PHP_URL_QUERY) : '', $query);
            $code = $query['code'] ?? null;
        }
        $code = is_string($code) ? preg_replace('/\D/', '', $code) : '';

        return strlen($code) === 6 ? $code : null;
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
