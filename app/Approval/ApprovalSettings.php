<?php

namespace App\Approval;

use App\Models\Setting;
use App\Support\LocalInstance;

/**
 * Replicated settings used by the approval flow (CONCEPT.md §7.2 C, §10.5).
 */
class ApprovalSettings
{
    /** Base URL of the admin port as reached by approvers (VPN / reverse proxy), for signed links. */
    public static function adminUrl(): string
    {
        return rtrim((string) (Setting::get('admin_url') ?: config('app.url')), '/');
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
