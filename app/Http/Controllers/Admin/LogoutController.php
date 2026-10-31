<?php

namespace App\Http\Controllers\Admin;

use App\Federation\Resync;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Audit::log('auth.logout', $request->user());
        if (Resync::required()) {
            // logout() writes a new remember token into the replicated user row, which the restore
            // resync blocks (CONCEPT.md §10.3); there is no "remember me", so this session is all to end.
            Auth::guard('web')->logoutCurrentDevice();
        } else {
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
