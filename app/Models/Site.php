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

    protected function casts(): array
    {
        return ['settings' => 'array'];
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
     * Guest Wi-Fi password of the setup assistant (site setting "setup.wifi_password"), stored
     * encrypted with the federation data key because site settings are replicated (CONCEPT.md §10.2).
     * Null when not set or not decryptable (never logged).
     */
    public function wifiPassword(): ?string
    {
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
        $plain = $this->wifiPassword();
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
