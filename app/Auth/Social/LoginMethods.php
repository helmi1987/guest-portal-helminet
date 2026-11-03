<?php

namespace App\Auth\Social;

use App\Federation\Topology;
use App\Models\Channel;
use App\Models\Setting;
use App\Sms\OtpService;

/**
 * Guest login methods and their switches (CONCEPT.md §7, decision 2026-11-03): one replicated,
 * hub-only setting (Federation::SECURITY_SETTINGS) with a flag per method. A method is offered to
 * guests only when its switch is on AND its prerequisites are met (SMS provider, notification channel,
 * configured social provider, federation limited mode).
 */
final class LoginMethods
{
    public const KEY = 'login_methods';

    public const CODE = 'code';

    public const SMS = 'sms';

    public const APPROVAL = 'approval';

    public const SOCIAL = 'social';

    public const ALL = [self::CODE, self::SMS, self::APPROVAL, self::SOCIAL];

    /** Switches before any admin change: everything that existed so far stays on, social login is off. */
    public const DEFAULTS = [
        self::CODE => true,
        self::SMS => true,
        self::APPROVAL => true,
        self::SOCIAL => false,
    ];

    /** @return array<string, bool> switch per method */
    public static function all(): array
    {
        $stored = Setting::get(self::KEY, []);
        $flags = self::DEFAULTS;
        foreach (self::ALL as $method) {
            if (is_array($stored) && array_key_exists($method, $stored)) {
                $flags[$method] = (bool) $stored[$method];
            }
        }

        return $flags;
    }

    /** The admin switch only (prerequisites not checked). */
    public static function enabled(string $method): bool
    {
        return self::all()[$method] ?? false;
    }

    /** @param  array<string, bool>  $flags */
    public static function save(array $flags): void
    {
        $stored = [];
        foreach (self::ALL as $method) {
            $stored[$method] = (bool) ($flags[$method] ?? self::DEFAULTS[$method]);
        }
        Setting::put(self::KEY, $stored);
    }

    /**
     * Methods a guest can use right now: switch on and prerequisites met. Push approval and normal codes
     * are not offered in federation limited mode (CONCEPT.md §10.1); the site code still works through
     * the code form, so "code" stays available then.
     *
     * @return array<string, bool>
     */
    public static function available(): array
    {
        $flags = self::all();
        $limited = app(Topology::class)->limitedMode();

        return [
            self::CODE => $flags[self::CODE],
            self::SMS => $flags[self::SMS] && OtpService::enabled(),
            self::APPROVAL => $flags[self::APPROVAL] && ! $limited && Channel::query()->activeNotification()->exists(),
            self::SOCIAL => $flags[self::SOCIAL] && ! $limited && SocialSettings::enabledProviders() !== [],
        ];
    }

    /** @return list<string> available methods in display order */
    public static function availableList(): array
    {
        return array_keys(array_filter(self::available()));
    }
}
