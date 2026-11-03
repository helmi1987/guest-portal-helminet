<?php

namespace App\Guest;

use App\Auth\Social\LoginMethods;
use App\Auth\Social\SocialLogin;
use App\Auth\Social\SocialSettings;
use App\Models\Site;
use App\Models\TermsVersion;
use App\Support\Redact;
use Carbon\CarbonInterval;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Admin preview of the guest pages (CONCEPT.md §9 "Guest texts"): renders the real guest views with demo
 * data, never touching sessions, grants, throttles, drivers or RADIUS. While a preview renders, the
 * instance is shared with the views as `$guestPreview`; links and forms of the guest views then lead to
 * the next preview screen instead of the guest routes (code → status, account → status, sms → otp →
 * status, approval → waiting, social → name → status). Without a preview `$guestPreview` is null.
 */
final class GuestPreview
{
    /** Screens besides the error pages ("error-<kind>", GuestTexts::ERROR_KINDS). */
    public const SCREENS = ['chooser', 'notices', 'code', 'account', 'sms', 'sms_otp', 'sms_resent', 'approval', 'approval_waiting',
        'social', 'social_name', 'waiting', 'status', 'landing'];

    /** Demo values: masked phone number of the SMS page and remaining time of the status page. */
    public const DEMO_PHONE = '+41790000000';

    public const DEMO_REMAINING_S = 2 * 3600 + 15 * 60;

    public function __construct(
        public readonly string $locale,
        public readonly string $screen,
        public readonly ?Site $site,
    ) {}

    /** @return list<string> */
    public static function screens(): array
    {
        return [...self::SCREENS, ...array_map(fn (string $kind) => 'error-'.$kind, GuestTexts::ERROR_KINDS)];
    }

    /**
     * Screens grouped for the toolbox page (sign-in, status, errors); together exactly screens().
     *
     * @return array<string, list<string>>
     */
    public static function screenGroups(): array
    {
        $status = ['waiting', 'status', 'landing'];

        return [
            'signin' => array_values(array_diff(self::SCREENS, $status)),
            'status' => $status,
            'errors' => array_map(fn (string $kind) => 'error-'.$kind, GuestTexts::ERROR_KINDS),
        ];
    }

    /** Label of a screen for the admin UI. */
    public static function screenLabel(string $screen): string
    {
        return str_starts_with($screen, 'error-')
            ? __('portal.texts.screens.error', ['kind' => __('portal.texts.error_kinds.'.substr($screen, 6))])
            : __('portal.texts.screens.'.$screen);
    }

    /**
     * Screens that show a key; the admin page switches the preview to the first one when a field gets the
     * focus while another screen is shown. Empty: visible on every screen (or none), no switch.
     *
     * @return list<string>
     */
    public static function screensFor(string $key): array
    {
        $key = Str::after($key, 'guest.');
        $forms = ['code', 'account', 'sms', 'approval', 'social'];

        return match (true) {
            str_starts_with($key, 'error_pages.') => ['error-'.explode('.', $key)[1]],
            str_starts_with($key, 'access_ended.'), in_array($key, ['limited_mode', 'router_failed_notice'], true) => ['notices'],
            str_starts_with($key, 'choose.'), $key === 'intros.chooser' => ['chooser', 'notices'],
            str_starts_with($key, 'intros.') => [substr($key, 7)],
            $key === 'heading' => ['chooser', ...$forms, 'notices', 'landing'],
            $key === 'intro' => ['landing'],
            $key === 'other_methods', str_starts_with($key, 'terms') => $forms,
            $key === 'code', $key === 'email' => ['code'],
            $key === 'connect' => ['code', 'account', 'sms_otp', 'sms_resent'],
            str_starts_with($key, 'account_') => ['account'],
            in_array($key, ['first_name', 'last_name'], true) => ['code', 'sms', 'approval', 'social_name'],
            in_array($key, ['phone', 'sms_request'], true) => ['sms'],
            $key === 'sms_resent' => ['sms_resent'],
            str_starts_with($key, 'sms_'), $key === 'otp' => ['sms_otp', 'sms_resent'],
            $key === 'approval_request' => ['approval'],
            str_starts_with($key, 'approval_waiting') => ['approval_waiting'],
            $key === 'social_with' => ['social', 'chooser'],
            $key === 'social_hint' => ['social'],
            str_starts_with($key, 'social_name'), $key === 'continue' => ['social_name'],
            in_array($key, ['connecting', 'waiting'], true) => ['waiting'],
            in_array($key, ['online', 'remaining', 'online_hint'], true) => ['status'],
            $key === 'try_again' => ['error-expired'],
            default => [],
        };
    }

    /** URL of a preview screen (GET: stored texts). */
    public function url(string $screen, ?string $locale = null): string
    {
        return route('admin.texts.preview', array_filter([
            'locale' => $locale ?? $this->locale, 'screen' => $screen, 'site' => $this->site?->id,
        ]), false);
    }

    /** Action of the guest forms in the preview: the preview POST route (see fields()). */
    public function action(): string
    {
        return route('admin.texts.preview.draft', absolute: false);
    }

    /** Hidden inputs of a guest form in the preview: the screen it leads to. */
    public function fields(string $next): HtmlString
    {
        $html = '';
        foreach (array_filter(['locale' => $this->locale, 'screen' => $next, 'site' => $this->site?->id]) as $name => $value) {
            $html .= '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
        }

        return new HtmlString($html);
    }

    /** The guest view of the screen with demo data (minimal view data, mirroring PortalController). */
    public function view(): View
    {
        $portal = fn (?string $method, array $extra = []) => view('guest.portal', [
            'site' => $this->site,
            'methods' => LoginMethods::ALL,
            'method' => $method,
            'terms' => TermsVersion::current($this->locale),
            'routerFailed' => false,
            'limitedMode' => false,
            'prefillCode' => null,
            'socialProviders' => $this->providers(),
            'endedNotice' => null,
            ...$extra,
        ]);

        return match (true) {
            $this->screen === 'landing' => view('guest.landing'),
            $this->screen === 'notices' => $portal(null, ['routerFailed' => true, 'limitedMode' => true, 'endedNotice' => 'expired']),
            in_array($this->screen, LoginMethods::ALL, true) => $portal($this->screen),
            in_array($this->screen, ['sms_otp', 'sms_resent'], true) => view('guest.sms', [
                'phone' => Redact::phone(self::DEMO_PHONE),
                'canResend' => true,
                'statusMessage' => $this->screen === 'sms_resent' ? __('portal.guest.sms_resent') : null,
            ]),
            $this->screen === 'approval_waiting' => view('guest.approval'),
            $this->screen === 'social_name' => view('guest.social-name', [
                'provider' => SocialLogin::name($this->providers()[0]), 'first' => null, 'last' => null,
            ]),
            $this->screen === 'waiting' => view('guest.waiting'),
            $this->screen === 'status' => view('guest.status', [
                'remaining' => CarbonInterval::seconds(self::DEMO_REMAINING_S)->cascade()->locale($this->locale)
                    ->forHumans(['parts' => 2, 'join' => true]),
            ]),
            str_starts_with($this->screen, 'error-') => view('guest.error', ['kind' => substr($this->screen, 6)]),
            default => $portal(null),
        };
    }

    /** @return non-empty-list<string> social providers offered to guests, else the first one as demo */
    private function providers(): array
    {
        return SocialSettings::enabledProviders() ?: [SocialSettings::PROVIDERS[0]];
    }
}
