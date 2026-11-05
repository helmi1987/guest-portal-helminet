<?php

namespace App\Http\Controllers\Admin;

use App\Auth\Social\SecretExpiry;
use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Nas\RouterFreshness;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin start page. For admins also the routers of this instance that need an update or are unreachable
 * (decision 2026-11-05, v0.30.0): one line per router with a link to its router state; managers see no router data.
 * Admins also see the social login secrets that expire within 30 days or have expired (decision 2026-11-05, v0.31.0).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, RouterFreshness $freshness): View
    {
        $admin = (bool) $request->user()?->isAdmin();
        $attention = $admin
            ? $freshness->attention(Site::query()->with('nasClients')->orderBy('name')->get())
            : collect();

        return view('admin.dashboard', ['routerAttention' => $attention, 'socialSecretAttention' => $admin ? SecretExpiry::attention() : []]);
    }
}
