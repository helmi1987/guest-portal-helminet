<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesRouter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generated hotspot login.html for /tool fetch on the router (setup assistant, CONCEPT.md §9).
 * Same authentication as the router certificate download (token + NAS IP).
 */
class RouterLoginPageController extends Controller
{
    use AuthorizesRouter;

    public function __invoke(Request $request, string $nas): Response
    {
        $nas = $this->authorizeRouter($request, $nas, 'Router login page download');
        $nas->markSeen('login_page_fetched_at');

        return response($nas->site->driver()->loginPage($nas->site), 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
