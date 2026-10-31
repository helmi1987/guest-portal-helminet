<?php

namespace App\Approval;

use App\Federation\Topology;
use App\Models\Instance;
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
        // Federation: each instance has its own admin URL (instances.admin_url, federation page); the
        // replicated setting is shared by all instances.
        $own = self::instanceAdminUrl(app(LocalInstance::class)->id());
        if ($own !== null) {
            return $own;
        }
        $configured = Setting::get('admin_url');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return self::fallbackAdminUrl();
    }

    /** True when the admin URL setting is empty (the settings page warns). */
    public static function adminUrlMissing(): bool
    {
        if (self::instanceAdminUrl(app(LocalInstance::class)->id()) !== null) {
            return false;
        }
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

    /** Setting value of the Telegram owner that turns polling off everywhere. */
    public const TELEGRAM_OWNER_NONE = 'none';

    /**
     * Instance that polls Telegram updates (CONCEPT.md §10.5): the selected instance; unset, a former
     * boolean value or an instance no longer in the federation means the hub (this instance without a
     * hub); "none" (or false) turns polling off.
     */
    public static function telegramOwnerId(): ?string
    {
        $owner = Setting::get('telegram_owner');
        if ($owner === self::TELEGRAM_OWNER_NONE || $owner === false) {
            return null;
        }
        if (is_string($owner) && $owner !== '' && Instance::query()->whereKey($owner)->exists()) {
            return $owner;
        }

        return app(Topology::class)->hubId() ?? app(LocalInstance::class)->id();
    }

    /** Only the Telegram owner polls getUpdates. */
    public static function isTelegramOwner(): bool
    {
        return self::telegramOwnerId() === app(LocalInstance::class)->id();
    }

    /**
     * Base URL for the approval links of a request: the admin URL of the instance where the guest
     * waits (instances.admin_url), else the admin URL setting / fallback.
     */
    public static function adminUrlFor(?string $instanceId): string
    {
        return ($instanceId === null ? null : self::instanceAdminUrl($instanceId)) ?? self::adminUrl();
    }

    private static function instanceAdminUrl(string $instanceId): ?string
    {
        $url = Instance::query()->whereKey($instanceId)->value('admin_url');

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    /** @return array<string,int> approver duration choices, e.g. ['8h' => 28800, ...] */
    public static function durations(): array
    {
        return config('portal.duration_presets');
    }
}
