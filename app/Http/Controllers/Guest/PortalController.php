<?php

namespace App\Http\Controllers\Guest;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Site;
use App\Nas\HotspotContext;
use App\Services\GrantManager;
use App\Services\LoginThrottle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Guest login with a code (CONCEPT.md §7.1, §7.2 A).
 */
class PortalController extends Controller
{
    /** Entry from the router's login.html redirect; parameters are kept in the session. */
    public function show(Request $request): View
    {
        if ($request->filled('site')) {
            $site = Site::query()->where('slug', $request->query('site'))->first();
            $request->session()->put('portal', $site ? [
                'site' => $site->id,
                'context' => $site->driver()->parseRedirect($request, $site)->toArray(),
            ] : null);
        }

        [$site, $context] = $this->current($request);

        return view('guest.portal', [
            'site' => $site,
            'ready' => $site !== null && $context->isComplete(),
        ]);
    }

    public function redeem(Request $request, GrantManager $grants, LoginThrottle $throttle): View|RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'terms' => ['accepted'],
            'code' => ['required', 'string'],
        ]);

        [$site, $context] = $this->current($request);
        if ($site === null || ! $context->isComplete()) {
            return redirect()->route('guest.portal');
        }

        $mac = $context->mac;
        $ip = $request->ip();
        $code = preg_replace('/\D/', '', $data['code']);

        // Check, lookup and record atomically so parallel guesses cannot exceed the limits.
        $grant = $throttle->attempt($mac, $ip, $site->id, 'code',
            fn () => strlen($code) === 6 ? $grants->findByCode($code, $site) : null);

        if ($grant === false) {
            throw ValidationException::withMessages(['code' => __('portal.guest.errors.locked')]);
        }
        if ($grant === null) {
            throw ValidationException::withMessages(['code' => __('portal.guest.errors.invalid_code')]);
        }

        $guest = Guest::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'locale' => app()->getLocale(),
        ]);
        [$token, $password] = $grants->issueToken($grant, $guest, $site, $mac);
        Log::info('Guest code redeemed', ['grant' => $grant->id, 'site' => $site->slug, 'mac' => $mac]);

        return view('guest.handoff', $site->driver()->loginHandoff($context, $token->username, $password));
    }

    /** @return array{0: ?Site, 1: HotspotContext} */
    private function current(Request $request): array
    {
        $portal = $request->session()->get('portal');
        $site = $portal ? Site::find($portal['site']) : null;

        return [$site, HotspotContext::fromArray($site ? $portal['context'] : [])];
    }
}
