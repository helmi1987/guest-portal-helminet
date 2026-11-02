<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NasClient;
use App\Models\Site;
use App\Nas\MikrotikRos7Driver;
use App\Tls\CertificateInfo;
use App\Tls\CertificateSources;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Guided setup wizard per site (admin only, decision 2026-11-02, v0.19.0 phase 2): an additional view of
 * the setup assistant, one step per setup block. Reuses the setup page's data (SetupController::viewData),
 * its save actions and the per-router state query (they redirect back here via the hidden field
 * "wizard_step"); no script logic of its own. A step is "verified" when every router with API access has
 * a fresh state in which every object of the step's scope is present (or a guarded equal entry of others
 * is used); routers without API access are confirmed by hand in the browser. Progress (done, skipped,
 * manual) is kept in the browser only (localStorage), nothing is stored on the server.
 */
class WizardController extends Controller
{
    /** Statuses of the router state that count as done. */
    public const GREEN = ['present', 'foreign'];

    /**
     * Steps in order: key => scope of the router state compare (block => object names of the comment keys
     * "guest-portal:<block>:<object>", null = whole block; "hotspot" = server and profile by name),
     * "info" = shown but not required (optional block), "optional" = the whole step is optional.
     *
     * @var array<string, array{scope: array<string, ?list<string>>, info?: array<string, ?list<string>>, optional?: bool}>
     */
    public const STEPS = [
        'access' => ['scope' => ['api' => null]],
        'certificate' => ['scope' => ['cert' => null]],
        'radius' => ['scope' => ['radius' => null]],
        'router' => ['scope' => ['radius' => null, 'walled' => null, 'cert' => null]],
        'container' => ['scope' => ['container' => null], 'optional' => true],
        'network' => ['scope' => ['net' => ['vlan', 'gateway', 'pool', 'dhcp-server', 'dhcp-network']], 'info' => ['net' => ['bridge-vlan']]],
        'wifi' => ['scope' => ['wifi' => null]],
        'firewall' => ['scope' => ['fw' => null, 'net' => ['option-114']]],
        'hotspot' => ['scope' => ['dns' => null, 'hotspot' => null, 'walled' => null]],
        'login' => ['scope' => []],
        'status' => ['scope' => []],
    ];

    /** 1-based step number of a request value, null when it is no valid step. */
    public static function stepNumber(mixed $value): ?int
    {
        if (! is_scalar($value) || ! preg_match('/^[1-9][0-9]?$/', (string) $value)) {
            return null;
        }
        $n = (int) $value;

        return $n <= count(self::STEPS) ? $n : null;
    }

    public function show(Request $request, Site $site, SetupController $setup): View
    {
        $data = $setup->viewData($site);
        $keys = array_keys(self::STEPS);
        $number = self::stepNumber($request->query('step')) ?? 1;
        $routers = $data['site']->nasClients;
        $apiRouters = $data['stateSupported'] ? $routers->filter(fn (NasClient $nas) => $nas->hasApiAccess())->values() : collect();
        $manualRouters = $routers->reject(fn (NasClient $nas) => $apiRouters->contains('id', $nas->id))->values();
        $ready = $this->ready($data);

        $steps = [];
        foreach ($keys as $i => $key) {
            $def = self::STEPS[$key];
            $scoped = $this->scopedStates($data['states'], $routers, $def['scope'], $def['info'] ?? []);
            // Steps without a router scope are checked in the portal (login.html fetch) or are the summary
            $hasScope = $def['scope'] !== [];
            $apiOk = $routers->isNotEmpty() && $apiRouters->every(fn (NasClient $nas) => $data['states'][$nas->id]['fresh']
                && $scoped[$nas->id] !== null && self::green($scoped[$nas->id]['required']));
            $steps[$key] = [
                'number' => $i + 1,
                'key' => $key,
                'optional' => (bool) ($def['optional'] ?? false),
                'hasScope' => $hasScope,
                'ready' => $ready[$key] ?? true,
                'apiOk' => $hasScope ? $apiOk : true,
                // Routers whose result the admin confirms by hand (no API access, or no state support)
                'manual' => $hasScope ? $manualRouters->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
                'scoped' => $scoped,
            ];
            $steps[$key]['ok'] = $key !== 'status' && $steps[$key]['ready'] && $steps[$key]['apiOk'] && $steps[$key]['manual'] === [];
        }

        return view('admin.sites.wizard', $data + [
            'steps' => $steps,
            'step' => $steps[$keys[$number - 1]],
            'apiRouters' => $apiRouters,
            'manualRouters' => $manualRouters,
        ]);
    }

    /** All required items of a scoped compare are done (an empty scope counts as done). */
    public static function green(array $blocks): bool
    {
        foreach ($blocks as $items) {
            foreach ($items as $item) {
                if (! in_array($item['status'] ?? 'unknown', self::GREEN, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Router state compare per router, cut down to the step's scope: ['required' => block => items,
     * 'info' => block => items] or null without a stored compare.
     *
     * @param  Collection<int, NasClient>  $routers
     * @return array<string, ?array{required: array, info: array}>
     */
    private function scopedStates(Collection $states, Collection $routers, array $scope, array $info): array
    {
        $out = [];
        foreach ($routers as $nas) {
            $compare = $states[$nas->id]['compare'] ?? null;
            $out[$nas->id] = $compare === null ? null : ['required' => self::scope($compare, $scope), 'info' => self::scope($compare, $info)];
        }

        return $out;
    }

    /** Items of a compare in a scope (block => object names or null for the whole block). */
    public static function scope(array $compare, array $scope): array
    {
        $out = [];
        foreach ($scope as $block => $objects) {
            foreach ($compare[$block] ?? [] as $id => $item) {
                $key = (string) ($item['key'] ?? $id);
                $prefix = MikrotikRos7Driver::NAME.':'.$block.':';
                $object = str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
                if ($objects === null || in_array($object, $objects, true)) {
                    $out[$block][$id] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * Portal-side preconditions per step (inputs saved, certificates present, login.html fetched).
     *
     * @return array<string, bool>
     */
    private function ready(array $data): array
    {
        $site = $data['site'];
        $routers = $site->nasClients;
        $networkSaved = $data['setup'] !== [];
        $certs = CertificateInfo::fromFile(CertificateSources::paths('portal')['cert']) !== null
            && CertificateInfo::fromFile(CertificateSources::paths('router')['cert']) !== null;

        return [
            'certificate' => $certs,
            'network' => $networkSaved,
            'wifi' => $networkSaved,
            'firewall' => $networkSaved,
            'hotspot' => $networkSaved && (array) $site->setting('hotspot_hosts', []) !== [],
            'login' => $routers->isNotEmpty() && $routers->every(fn (NasClient $nas) => $nas->login_page_fetched_at !== null),
        ];
    }
}
