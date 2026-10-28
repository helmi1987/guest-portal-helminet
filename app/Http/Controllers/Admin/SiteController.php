<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NasClient;
use App\Models\Site;
use App\Nas\NasDrivers;
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
        return view('admin.sites.form', ['site' => new Site(['driver' => 'mikrotik_ros7'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $site = Site::create($this->validated($request));

        return redirect()->route('admin.sites.edit', $site)->with('status', __('portal.saved'));
    }

    public function edit(Site $site): View
    {
        return view('admin.sites.form', ['site' => $site->load('nasClients')]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $site->update($this->validated($request, $site));

        return back()->with('status', __('portal.saved'));
    }

    public function destroy(Site $site): RedirectResponse
    {
        $site->nasClients->each->delete();
        $site->delete();

        return redirect()->route('admin.sites.index')->with('status', __('portal.deleted'));
    }

    public function storeRouter(Request $request, Site $site): RedirectResponse
    {
        $site->nasClients()->create($request->validate([
            'name' => ['required', 'string', 'max:100'],
            'nas_ip' => ['required', 'ipv4', Rule::unique('nas_clients', 'nas_ip')],
            'nas_identifier' => ['nullable', 'string', 'max:100'],
            'coa_port' => ['required', 'integer', 'between:1,65535'],
        ]));

        return back()->with('status', __('portal.saved'));
    }

    public function regenerateSecret(Site $site, NasClient $router): RedirectResponse
    {
        $router->radius_secret = NasClient::generateSecret();
        $router->save();

        return back()->with('status', __('portal.sites.secret_regenerated'));
    }

    public function destroyRouter(Site $site, NasClient $router): RedirectResponse
    {
        $router->delete();

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
            'hotspot_hosts' => ['nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9.\-, ]*$/'],
        ]);

        return [
            'name' => $data['name'],
            'slug' => $data['slug'],
            'driver' => $data['driver'],
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
