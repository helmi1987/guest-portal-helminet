<?php

namespace App\Models;

use App\Models\Concerns\Replicated;
use App\Nas\NasDriver;
use App\Nas\NasDrivers;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A hotspot location (replicated). Routers are local-only NasClient rows.
 */
#[Fillable(['slug', 'name', 'driver', 'settings'])]
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

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key) ?? $default;
    }

    public function driver(): NasDriver
    {
        return NasDrivers::make($this->driver);
    }
}
