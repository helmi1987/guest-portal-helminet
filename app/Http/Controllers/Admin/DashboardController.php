<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Nas\RouterFreshness;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin start page. For admins also the routers of this instance that need an update or are unreachable
 * (decision 2026-11-05, v0.30.0): one line per router with a link to its router state; managers see no router data.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, RouterFreshness $freshness): View
    {
        $attention = $request->user()?->isAdmin()
            ? $freshness->attention(Site::query()->with('nasClients')->orderBy('name')->get())
            : collect();

        return view('admin.dashboard', ['routerAttention' => $attention]);
    }
}
