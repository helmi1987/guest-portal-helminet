<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use App\Nas\NasDriver;
use App\Nas\NasDrivers;
use App\Support\LocalInstance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hotspot location (replicated). Routers are local-only NasClient rows of the site's home instance.
 */
#[Fillable(['slug', 'name', 'driver', 'settings', 'home_instance_id'])]
class Site extends Model
{
    use Replicated;

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

    public function driver(): NasDriver
    {
        return NasDrivers::make($this->driver);
    }
}
