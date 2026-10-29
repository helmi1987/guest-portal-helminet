<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Instance;
use App\Models\NasClient;
use App\Models\Site;
use App\Nas\NasDrivers;
use App\Support\Audit;
use App\Support\LocalInstance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
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
        Audit::log('site.updated', $site, ['changed' => array_values(array_diff(array_keys($site->getChanges()), ['updated_at', 'version']))]);

        return back()->with('status', __('portal.saved'));
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
            'nas_ip' => ['required', 'ipv4', Rule::unique('nas_clients', 'nas_ip')],
            'nas_identifier' => ['nullable', 'string', 'max:100'],
            'coa_port' => ['required', 'integer', 'between:1,65535'],
        ]));
        Audit::log('router.created', $router, ['site' => $site->slug, 'name' => $router->name, 'nas_ip' => $router->nas_ip]);

        return back()->with('status', __('portal.saved'));
    }

    public function regenerateSecret(Site $site, NasClient $router): RedirectResponse
    {
        $router->radius_secret = NasClient::generateSecret();
        $router->save();
        Audit::log('router.secret_regenerated', $router, ['site' => $site->slug, 'name' => $router->name]);

        return back()->with('status', __('portal.sites.secret_regenerated'));
    }

    public function destroyRouter(Site $site, NasClient $router): RedirectResponse
    {
        $router->delete();
        Audit::log('router.deleted', $router, ['site' => $site->slug, 'name' => $router->name]);

        return back()->with('status', __('portal.deleted'));
    }

    public function routerConfig(Site $site, NasClient $router): View
    {
        return view('admin.sites.config', [
            'site' => $site,
            'router' => $router,
            'script' => $site->driver()->configScript($site, $router),
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
            'portal_address' => ['nullable', 'ip'],
            'portal_url' => ['nullable', 'url:https,http'],
            'hotspot_hosts' => ['nullable', 'string', 'max:500', 'regex:/^([A-Za-z0-9.\-]+(\s*,\s*[A-Za-z0-9.\-]+)*)?$/D'],
            'home_instance_id' => ['nullable', Rule::exists('instances', 'id')->whereNull('deleted_at')],
        ]);

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
                'hotspot_hosts' => array_values(array_filter(array_map('trim',
                    explode(',', strtolower($data['hotspot_hosts'] ?? ''))))),
            ]),
        ];
    }
}
