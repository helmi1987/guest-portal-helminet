<?php

namespace App\Providers;

use App\Federation\ApplyHooks;
use App\Federation\DataKey;
use App\Federation\Outbox;
use App\Http\Middleware\EnsureZone;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocalInstance::class);
        $this->app->singleton(Hlc::class);
        $this->app->singleton(DataKey::class);
        $this->app->singleton(Outbox::class);
        $this->app->singleton(ApplyHooks::class);
    }

    public function boot(): void
    {
        // Livewire is used by the admin UI only: its endpoints exist on the admin listener only.
        Livewire::setUpdateRoute(fn ($handle) => Route::post('/livewire/update', $handle)
            ->middleware(['web', 'zone:admin']));
        Livewire::setScriptRoute(fn ($handle) => Route::get('/livewire/livewire.js', $handle)
            ->middleware('zone:admin'));
        // Livewire update requests re-apply the zone of the page they came from.
        Livewire::addPersistentMiddleware([EnsureZone::class]);

        // Columns shared by all replicated tables (CONCEPT.md §10.3, §11).
        Blueprint::macro('replicated', function () {
            /** @var Blueprint $this */
            $this->ulid('origin_instance_id')->nullable()->index();
            $this->string('version', 64)->nullable()->index();
            $this->timestamps();
            $this->softDeletes();
        });
    }
}
