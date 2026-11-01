<?php

namespace App\Models;

use App\Federation\DataKey;
use App\Models\Concerns\Replicated;
use App\Nas\NasDriver;
use App\Nas\NasDrivers;
use App\Support\LocalInstance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A hotspot location (replicated). Routers are local-only NasClient rows of the site's home instance.
 */
#[Fillable(['slug', 'name', 'driver', 'settings', 'home_instance_id'])]
class Site extends Model
{
    use Replicated;

    /** Setting key of the encrypted guest Wi-Fi password (setup assistant). */
    public const WIFI_PASSWORD = 'setup.wifi_password';

    /** Setting keys of the setup assistant: replicated non-secret flags (wpa|open, shared with other instances). */
    public const WIFI_SECURITY = 'setup.wifi_security';

    public const WIFI_SHARED = 'setup.wifi_password_shared';

    /** Wi-Fi modes with selectable masters (setup assistant, column wifi_masters_local). */
    public const WIFI_MODES = ['capsman1', 'capsman2', 'local'];

    /** Never serialized: instance-local Wi-Fi password (APP_KEY encrypted). */
    protected $hidden = ['wifi_password_local'];

    /** Unsaved copy for rendering only (withDerivedSetup()); saving it is refused. */
    private bool $renderOnly = false;

    protected function casts(): array
    {
        return ['settings' => 'array', 'wifi_password_local' => 'encrypted', 'wifi_masters_local' => 'array'];
    }

    protected static function booted(): void
    {
        static::saving(function (Site $site) {
            if ($site->renderOnly) {
                throw new \LogicException('A site copy with derived setup values must not be saved.');
            }
            // A shared password lives in the replicated setting only: no stale local copy stays. Without a
            // shared value the local one is the only copy and is kept (never lost to a bare flag).
            if ($site->wifiPasswordShared() && $site->hasSharedWifiPasswordValue()) {
                $site->wifi_password_local = null;
            }
        });
    }

    /**
     * Clear the instance-local Wi-Fi password of sites (all, or the given one) whose password is shared
     * and carry a shared value (CONCEPT.md §9, decision 2026-10-31): after a replicated change switched a
     * site to shared, the local value of this instance is stale. A shared flag without a shared value
     * never clears it (the local value may be the only copy). Raw write of a local column: no version,
     * no event.
     *
     * @return int sites cleared
     */
    public static function dropSharedLocalWifiPasswords(?string $id = null): int
    {
        $cleared = 0;
        $sites = static::query()->whereNotNull('wifi_password_local')->when($id !== null, fn ($q) => $q->whereKey($id))->get();
        foreach ($sites as $site) {
            if ($site->wifiPasswordShared() && $site->hasSharedWifiPasswordValue()) {
                $cleared += DB::table('sites')->where('id', $site->id)->update(['wifi_password_local' => null]);
            }
        }

        return $cleared;
    }

    /**
     * Copy of this site for rendering the setup assistant with inputs derived from the router state
     * (field => value) where the saved setup has none. Never saved; relations are kept.
     */
    public function withDerivedSetup(array $values): static
    {
        $copy = clone $this;
        $copy->renderOnly = true;
        $settings = $copy->settings ?? [];
        $setup = (array) ($settings['setup'] ?? []);
        foreach ($values as $field => $value) {
            if (($setup[$field] ?? '') === '') {
                $setup[$field] = $value;
            }
        }
        $settings['setup'] = $setup;
        $copy->settings = $settings;

        return $copy;
    }

    public function nasClients(): HasMany
    {
        return $this->hasMany(NasClient::class);
    }

    /** Alias used by scoped route bindings (/sites/{site}/routers/{router}). */
    public function routers(): HasMany
    {
        return $this->nasClients();
    }

    /**
     * The instance serving this site's routers (RADIUS, one-time credentials, CONCEPT.md §10.4).
     * Sites without one (before M7) belong to the instance they are on.
     */
    public function isServedHere(): bool
    {
        return $this->home_instance_id === null || $this->home_instance_id === app(LocalInstance::class)->id();
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key) ?? $default;
    }

    /**
     * Whether the guest Wi-Fi password is shared with other instances (replicated site setting, encrypted
     * with the federation data key). Default off; sites saved before the flag existed keep a stored
     * replicated password as shared.
     */
    public function wifiPasswordShared(): bool
    {
        $flag = $this->setting(self::WIFI_SHARED);
        if ($flag !== null) {
            return (bool) $flag;
        }

        return is_string($this->setting(self::WIFI_PASSWORD)) && $this->setting(self::WIFI_PASSWORD) !== '';
    }

    /** Whether the replicated setting "setup.wifi_password" holds a (non-empty) encrypted value. */
    public function hasSharedWifiPasswordValue(): bool
    {
        $stored = $this->setting(self::WIFI_PASSWORD);

        return is_string($stored) && $stored !== '';
    }

    /**
     * Instance-local Wi-Fi password (APP_KEY), regardless of the shared flag. Null when not set or not
     * decryptable (never logged).
     */
    public function localWifiPassword(): ?string
    {
        try {
            $local = $this->wifi_password_local;
        } catch (Throwable) {
            Log::warning('Local guest Wi-Fi password of the site cannot be decrypted; enter it again in the setup assistant.', ['site' => $this->slug]);

            return null;
        }

        return is_string($local) && $local !== '' ? $local : null;
    }

    /**
     * Guest Wi-Fi masters selected on this instance for a Wi-Fi mode (CONCEPT.md §9, decision
     * 2026-11-01): local interfaces (mode local) or master configurations of provisioning rules
     * (capsman1/capsman2). Instance-local, not replicated. Only valid names are returned.
     *
     * @return list<string>
     */
    public function wifiMasters(string $mode): array
    {
        $all = is_array($this->wifi_masters_local) ? $this->wifi_masters_local : [];
        $list = is_array($all[$mode] ?? null) ? $all[$mode] : [];

        return array_values(array_unique(array_filter(array_map('strval', $list), fn ($m) => self::validWifiMaster($m))));
    }

    /** Name of a Wi-Fi master: RouterOS name characters only, not one of the guest objects. */
    public static function validWifiMaster(string $name): bool
    {
        return preg_match('/^[A-Za-z0-9._-]{1,48}\z/', $name) === 1 && $name !== 'cfg-guest' && ! str_starts_with($name, 'wifi-guest');
    }

    /** "wpa" (a password is set, on some instance) or "open"; replicated, carries no secret. */
    public function wifiSecurity(): string
    {
        $flag = $this->setting(self::WIFI_SECURITY);
        if (in_array($flag, ['wpa', 'open'], true)) {
            return $flag;
        }

        return $this->wifiPasswordShared() ? 'wpa' : 'open';
    }

    /** WPA network whose password is not available on this instance (kept local on another one). */
    public function wifiPasswordUnavailable(): bool
    {
        return $this->wifiSecurity() === 'wpa' && $this->wifiPassword() === null;
    }

    /**
     * Guest Wi-Fi password of the setup assistant from the storage in use: shared = site setting
     * "setup.wifi_password" encrypted with the federation data key (replicated, CONCEPT.md §10.2); not
     * shared = local column encrypted with APP_KEY. Null when not set, not available here or not
     * decryptable (never logged).
     */
    public function wifiPassword(): ?string
    {
        if (! $this->wifiPasswordShared()) {
            return $this->localWifiPassword();
        }
        $stored = $this->setting(self::WIFI_PASSWORD);
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return app(DataKey::class)->decrypt($stored);
        } catch (Throwable) {
            Log::warning('Guest Wi-Fi password of the site cannot be decrypted; enter it again in the setup assistant.', ['site' => $this->slug]);

            return null;
        }
    }

    /** Encrypted value for the setting "setup.wifi_password"; null removes it. */
    public static function encryptWifiPassword(?string $password): ?string
    {
        return $password === null || $password === '' ? null : app(DataKey::class)->encrypt($password);
    }

    /** Re-encrypt the Wi-Fi password with the current data key (after a rotation); true if rewritten. */
    public function reencryptWifiPassword(): bool
    {
        $stored = $this->setting(self::WIFI_PASSWORD);
        $plain = $this->wifiPasswordShared() ? $this->wifiPassword() : null;
        if ($plain === null || DataKey::keyIdOf($stored) === app(DataKey::class)->currentId()) {
            return false;
        }
        $settings = $this->settings ?? [];
        data_set($settings, self::WIFI_PASSWORD, self::encryptWifiPassword($plain));
        $this->settings = $settings;
        $this->save();

        return true;
    }

    public function driver(): NasDriver
    {
        return NasDrivers::make($this->driver);
    }
}
