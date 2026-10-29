<?php

namespace App\Approval;

use App\Models\Setting;
use App\Support\LocalInstance;

/**
 * Replicated settings used by the approval flow (CONCEPT.md §7.2 C, §10.5).
 */
class ApprovalSettings
{
    /**
     * Base URL of the admin port as reached by approvers (VPN / reverse proxy), for signed links and
     * the OIDC redirect URI. Without the setting: APP_URL with the admin port (ADMIN_PORT), because
     * APP_URL is the guest port where /admin answers 404.
     */
    public static function adminUrl(): string
    {
        $configured = Setting::get('admin_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return self::fallbackAdminUrl();
    }

    /** True when the admin URL setting is empty (the settings page warns). */
    public static function adminUrlMissing(): bool
    {
        $configured = Setting::get('admin_url');

        return ! is_string($configured) || $configured === '';
    }

    /** APP_URL (scheme, host, path) with the admin port. */
    public static function fallbackAdminUrl(): string
    {
        $url = rtrim((string) config('app.url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $host = str_contains($parts['host'], ':') && ! str_starts_with($parts['host'], '[') ? '['.$parts['host'].']' : $parts['host'];

        return $parts['scheme'].'://'.$host.':'.(int) config('portal.admin_port').rtrim($parts['path'] ?? '', '/');
    }

    /** Language of Telegram / Gotify / webhook messages and of the signed link pages. */
    public static function locale(): string
    {
        $locale = Setting::get('notification_locale');

        return in_array($locale, config('portal.locales'), true) ? $locale : config('app.locale');
    }

    /**
     * Only the Telegram owner polls getUpdates. Unset means this instance (single instance);
     * "none" disables polling everywhere.
     */
    public static function isTelegramOwner(): bool
    {
        $owner = Setting::get('telegram_owner');

        return $owner === null || $owner === app(LocalInstance::class)->id();
    }

    /** @return array<string,int> approver duration choices, e.g. ['8h' => 28800, ...] */
    public static function durations(): array
    {
        return config('portal.duration_presets');
    }
}
