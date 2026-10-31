<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\NasClient;
use App\Models\Site;
use App\Nas\NasDrivers;
use App\Nas\RouterOs\RouterStateDriver;
use App\Radius\ClientsFile;
use App\Support\Audit;
use App\Support\LocalInstance;
use App\Support\PortalAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Sites and their routers (admin only, CONCEPT.md §9).
 */
class SiteController extends Controller
{
    public function index(): View
    {
        return view('admin.sites.index', ['sites' => Site::query()->withCount('nasClients')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        return view('admin.sites.form', ['site' => new Site(['driver' => 'mikrotik_ros7', 'home_instance_id' => app(LocalInstance::class)->id()]),
            'instances' => Instance::query()->orderBy('name')->pluck('name', 'id')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = Site::create($this->validated($request));
        Audit::log('site.created', $site, ['slug' => $site->slug, 'name' => $site->name]);

        return redirect()->route('admin.sites.edit', $site)->with('status', __('portal.saved'));
    }

    public function edit(Site $site): View
    {
        return view('admin.sites.form', ['site' => $site->load('nasClients'),
            'instances' => Instance::query()->orderBy('name')->pluck('name', 'id')]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $site->update($this->validated($request, $site));
        // The home instance decides which routers this instance serves.
        app(ClientsFile::class)->write();
        Audit::log('site.updated', $site, ['changed' => array_values(array_diff(array_keys($site->getChanges()), ['updated_at', 'version']))]);

        return back()->with('status', __('portal.saved'));
    }

    /**
     * Take over a site (replacement instance, CONCEPT.md §10.4): this instance becomes its home and
     * serves its routers from now on (clients file written at once).
     */
    public function takeOver(Site $site, LocalInstance $local, ClientsFile $clients): RedirectResponse
    {
        $previous = $site->home_instance_id;
        $site->update(['home_instance_id' => $local->id()]);
        $clients->write();
        Audit::log('site.taken_over', $site, ['slug' => $site->slug, 'previous_home' => $previous]);

        return back()->with('status', __('portal.sites.taken_over'));
    }

    public function destroy(Site $site): RedirectResponse
    {
        $site->nasClients->each->delete();
        $site->delete();
        Audit::log('site.deleted', $site, ['slug' => $site->slug]);

        return redirect()->route('admin.sites.index')->with('status', __('portal.deleted'));
    }

    public function storeRouter(Request $request, Site $site): RedirectResponse
    {
        $router = $site->nasClients()->create($request->validate([
            'name' => ['required', 'string', 'max:100'],
            // Unique among the routers served by the same instance (one FreeRADIUS clients file).
            'nas_ip' => ['required', 'ipv4', function (string $attribute, mixed $value, \Closure $fail) use ($site) {
                $home = $site->home_instance_id ?? app(LocalInstance::class)->id();
                $taken = NasClient::query()->with('site')->where('nas_ip', $value)->get()
                    ->contains(fn (NasClient $n) => ($n->site?->home_instance_id ?? app(LocalInstance::class)->id()) === $home);
                if ($taken) {
                    $fail(__('validation.unique', ['attribute' => $attribute]));
                }
            }],
            'nas_identifier' => ['nullable', 'string', 'max:100'],
            'coa_port' => ['required', 'integer', 'between:1,65535'],
        ]));
        Audit::log('router.created', $router, ['site' => $site->slug, 'name' => $router->name, 'nas_ip' => $router->nas_ip]);

        return back()->with('status', __('portal.saved'));
    }

    public function regenerateSecret(Site $site, NasClient $router): RedirectResponse
    {
        // Rotates the RADIUS secret and the download token; the router script must be applied again.
        $router->radius_secret = NasClient::generateSecret();
        $router->cert_token = NasClient::generateSecret();
        $router->save();
        // Audit::clean() strips keys containing "token"/"secret", so the flag is named cert_rotated.
        Audit::log('router.secret_regenerated', $router, ['site' => $site->slug, 'name' => $router->name, 'cert_rotated' => true]);

        return back()->with('status', __('portal.sites.secret_regenerated'));
    }

    /**
     * Read-only router API access for the setup assistant's state query (CONCEPT.md §9). User and
     * password are local to the instance (not replicated), stored encrypted with APP_KEY and never audited or logged; the password is
     * never rendered (the user name is, in the edit form); an empty password field keeps the stored one, "api_clear" removes the access.
     */
    public function updateRouterApi(Request $request, Site $site, NasClient $router): RedirectResponse
    {
        if ($request->boolean('api_clear')) {
            $router->fill(['api_host' => null, 'api_type' => null, 'api_port' => null, 'api_user' => null, 'api_password' => null, 'api_pin' => null]);
            self::clearRouterState($router);
            $router->save();
            Audit::log('router.api_access_removed', $router, ['site' => $site->slug, 'name' => $router->name]);

            return back()->with('status', __('portal.saved'));
        }
        $data = $request->validateWithBag('api_'.$router->id, [
            'api_host' => ['required', 'string', 'max:253', 'regex:/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$|^[0-9a-fA-F:]+$/'],
            'api_type' => ['required', Rule::in(NasClient::API_TYPES)],
            'api_port' => ['nullable', 'integer', 'between:1,65535'],
            'api_user' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._@-]+$/'],
            'api_password' => [$router->api_password === null ? 'required' : 'nullable', 'string', 'max:128', 'regex:/^[\x20-\x7E]+$/'],
            'api_pin' => ['nullable', 'string', 'regex:#^sha256//[A-Za-z0-9+/]{43}=$#'],
        ]);
        $router->fill([
            'api_host' => strtolower($data['api_host']),
            'api_type' => $data['api_type'],
            'api_port' => $data['api_port'] ?? null,
            'api_user' => $data['api_user'],
            'api_pin' => $data['api_pin'] ?? null,
        ]);
        if ($router->isDirty(['api_host', 'api_type', 'api_port'])) {
            self::clearRouterState($router);
        }
        // The checkbox removes only the stored pin (the router certificate of this portal is used afterwards)
        $pinRemoved = $request->boolean('api_clear_pin') && $router->getOriginal('api_pin') !== null;
        if ($request->boolean('api_clear_pin')) {
            $router->api_pin = null;
        }
        if ($router->isDirty('api_pin')) {
            // A previous TLS error belongs to the old pin; keep the last successful state
            $router->router_state_error = null;
        }
        if (($data['api_password'] ?? '') !== '') {
            $router->api_password = $data['api_password'];
        }
        $router->save();
        if ($pinRemoved) {
            Audit::log('router.api_pin_removed', $router, ['site' => $site->slug, 'name' => $router->name]);
        }
        // Never the user name or password; only which access is configured
        Audit::log('router.api_access_updated', $router, ['site' => $site->slug, 'name' => $router->name,
            'api_type' => $router->api_type, 'api_port' => $router->apiPort(), 'pinned' => $router->api_pin !== null]);

        return back()->with('status', __('portal.saved'));
    }

    /** Drop the stored router state (it belongs to the previous access settings). */
    private static function clearRouterState(NasClient $router): void
    {
        $router->router_state = null;
        $router->router_state_at = null;
        $router->router_state_error = null;
    }

    public function destroyRouter(Site $site, NasClient $router): RedirectResponse
    {
        $router->delete();
        Audit::log('router.deleted', $router, ['site' => $site->slug, 'name' => $router->name]);

        return back()->with('status', __('portal.deleted'));
    }

    public function routerConfig(Site $site, NasClient $router): View
    {
        $driver = $site->driver();
        $script = $driver->configScript($site, $router);
        $stateDriver = $driver instanceof RouterStateDriver ? $driver : null;
        // Line colours from this router's fresh state (display only), placeholder suggestions
        $fresh = $stateDriver && is_array($router->router_state) && SetupController::fresh($router, SetupController::inputsChangedAt($site));
        $statusByKey = $fresh ? SetupController::statusByKey([$stateDriver->compareState($site, $router->router_state)]) : [];

        return view('admin.sites.config', [
            'site' => $site,
            'router' => $router,
            'script' => $script,
            'lines' => $statusByKey !== [] ? $stateDriver->lineStatuses($script, $statusByKey) : null,
            'placeholders' => $stateDriver ? $stateDriver->placeholderHints($site, $router->hasApiAccess() ? [$router] : []) : ['options' => [], 'values' => []],
        ]);
    }

    public function loginPage(Site $site): Response
    {
        return response($site->driver()->loginPage($site), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="login.html"',
        ]);
    }

    private function validated(Request $request, ?Site $site = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'alpha_dash:ascii', 'max:64', Rule::unique('sites', 'slug')->ignore($site?->id)],
            'driver' => ['required', Rule::in(array_keys(NasDrivers::DRIVERS))],
            'radius_address' => ['nullable', 'ip'],
            'portal_address' => ['nullable', 'ipv4'],
            'portal_url' => ['nullable', 'url:https,http'],
            'hotspot_hosts' => ['nullable', 'string', 'max:500', 'regex:/^([A-Za-z0-9.\-]+(\s*,\s*[A-Za-z0-9.\-]+)*)?$/D'],
            'home_instance_id' => ['nullable', Rule::exists('instances', 'id')->whereNull('deleted_at')],
        ]);
        $host = fn (mixed $h) => is_string($h) ? rtrim(strtolower(trim($h)), '.') : '';
        $hosts = array_values(array_unique(array_filter(array_map($host, explode(',', (string) ($data['hotspot_hosts'] ?? ''))))));
        // The hotspot name is the router's own HTTPS login name (resolves to the guest gateway); it must
        // never be the portal host, otherwise the static DNS entry points the portal name at the router.
        $portalHosts = array_filter(array_map($host, [
            parse_url((string) ($data['portal_url'] ?? ''), PHP_URL_HOST),
            parse_url((string) config('app.url'), PHP_URL_HOST),
            config('portal.hostname'),
        ]));
        foreach ($hosts as $hotspot) {
            if (in_array($hotspot, $portalHosts, true)) {
                throw ValidationException::withMessages(['hotspot_hosts' => __('portal.sites.hotspot_is_portal', ['host' => $hotspot])]);
            }
        }

        // Portal IP as reachable from the router: never the guest gateway or inside the guest subnet.
        $problem = PortalAddress::problem($data['portal_address'] ?? null, (array) ($site?->setting('setup', []) ?? []));
        if ($problem !== null) {
            throw ValidationException::withMessages(['portal_address' => $problem]);
        }

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'driver' => $data['driver'],
            // Home instance: serves the site's routers; default this instance (CONCEPT.md §10.4).
            'home_instance_id' => $data['home_instance_id'] ?? $site?->home_instance_id ?? app(LocalInstance::class)->id(),
            'settings' => array_merge($site?->settings ?? [], [
                'radius_address' => $data['radius_address'] ?? null,
                'portal_address' => $data['portal_address'] ?? null,
                'portal_url' => $data['portal_url'] ?? null,
                'hotspot_hosts' => $hosts,
            ]),
        ];
    }
}
