<?php

namespace App\Providers;

use App\Http\Middleware\EnsureZone;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LocalInstance::class);
        $this->app->singleton(Hlc::class);
    }

    public function boot(): void
    {
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
