<?php

namespace App\Providers;

use App\Auth\Directory\DirectoryClient;
use App\Auth\Directory\DirectoryLogin;
use App\Auth\Directory\LdapDirectoryClient;
use App\Database\SQLiteConnection;
use App\Federation\AppliedChange;
use App\Federation\ApplyHooks;
use App\Federation\BrandingFiles;
use App\Federation\DataKey;
use App\Federation\Outbox;
use App\Federation\SessionHooks;
use App\Http\Middleware\EnsureZone;
use App\Models\Site;
use App\Support\Hlc;
use App\Support\LocalInstance;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor('sqlite', fn ($pdo, $database, $prefix, $config) => new SQLiteConnection($pdo, $database, $prefix, $config));
        $this->app->singleton(LocalInstance::class);
        $this->app->singleton(Hlc::class);
        $this->app->singleton(DataKey::class);
        $this->app->singleton(Outbox::class);
        $this->app->singleton(ApplyHooks::class);
        // Directory account login (CONCEPT.md §7.2 E): one LDAP client per login; tests bind a fake.
        $this->app->bind(DirectoryClient::class, LdapDirectoryClient::class);
        $this->app->bind(DirectoryLogin::class, fn ($app) => new DirectoryLogin(fn () => $app->make(DirectoryClient::class)));
        // Disconnects of local sessions after replicated changes (CONCEPT.md §10.4).
        $this->app->afterResolving(ApplyHooks::class, function (ApplyHooks $hooks) {
            (new SessionHooks)->register($hooks);
            // Branding files referenced by replicated settings (CONCEPT.md §10.3).
            app(BrandingFiles::class)->register($hooks);
            // A site switched to a shared Wi-Fi password drops this instance's local copy (CONCEPT.md §9).
            $hooks->register('sites', fn (AppliedChange $c) => Site::dropSharedLocalWifiPasswords($c->id));
        });
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

        // Guest views link to the guest routes unless the admin preview shares its GuestPreview (CONCEPT.md §9).
        View::share('guestPreview', null);

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
