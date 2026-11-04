<?php

namespace App\Nas;

use App\Auth\Social\WalledGarden;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\Site;
use App\Nas\Unifi\UnifiClient;
use App\Nas\Unifi\UnifiException;
use App\Nas\Unifi\UnifiSettings;
use App\Support\Mac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Ubiquiti UniFi with an external portal server (CONCEPT.md §5.1, decision 2026-11-04, v0.27.0):
 * EXPERIMENTAL, built from Ubiquiti's documentation and the OpenAPI files without UniFi hardware
 * (docs/unifi-setup.md lists what is verified and what not).
 *
 * The AP redirects guests to `/guest/s/<UniFi site>/?ap=..&id=..&t=..&url=..&ssid=..` (route guest.unifi),
 * which forwards to /portal; after the login the portal authorizes the client through the controller API
 * (AuthorizesViaController). No RADIUS: the RADIUS methods of NasDriver are no-ops, MAC whitelist and
 * roaming do not work on UniFi sites. Resolved as a scoped singleton: one controller login per request
 * or command run (UnifiClient per site).
 */
class UnifiDriver implements AuthorizesViaController, NasDriver
{
    public const KEY = 'unifi';

    /** @var array<string, UnifiClient> per site id, for this request or command run */
    private array $clients = [];

    public function label(): string
    {
        return 'Ubiquiti UniFi (experimental)';
    }

    // --- NasDriver ----------------------------------------------------------------------------------

    /**
     * UniFi redirect parameters as forwarded by the guest.unifi route: id (client MAC), ap (AP MAC), ssid,
     * url (original URL), t (timestamp) and unifi_site (from the redirect's path). All untrusted. The
     * context is complete only when the UniFi site is the one configured for this portal site.
     */
    public function parseRedirect(Request $request, Site $site): HotspotContext
    {
        $string = fn (string $key, int $max) => is_string($v = $request->query($key)) && $v !== '' && strlen($v) <= $max ? $v : null;
        $unifiSite = $string('unifi_site', 64);
        if ($unifiSite !== null && $unifiSite !== UnifiSettings::forSite($site)['unifi_site']) {
            Log::warning('UniFi redirect for another UniFi site ignored', ['site' => $site->slug]);
            $unifiSite = null;
        }
        $ssid = $string('ssid', 64);
        $ssid = $ssid !== null && preg_match('/^[^\x00-\x1F\x7F]{1,32}$/u', $ssid) === 1 ? $ssid : null;
        $t = $string('t', 12);

        return new HotspotContext(
            Mac::strict($string('id', 17)),
            null,
            null,
            self::httpUrl($request->query('url')),
            Mac::strict($string('ap', 17)),
            $ssid,
            $unifiSite,
            $t !== null && ctype_digit($t) ? (int) $t : null,
        );
    }

    /** Not used: the core authorizes through authorizeClient() instead of a browser handoff. */
    public function loginHandoff(HotspotContext $context, string $username, string $password, string $successUrl): array
    {
        return ['url' => $successUrl, 'fields' => []];
    }

    /** No RADIUS on UniFi sites. */
    public function replyAttributes(Grant $grant): array
    {
        return [];
    }

    /** No RADIUS sessions on UniFi sites: deauthorizeClient() ends access. */
    public function disconnectAttributes(RadiusAccounting $acct): ?array
    {
        return null;
    }

    /** Nothing to install: the AP redirects to the portal by itself. */
    public function loginPage(Site $site): string
    {
        return "<!doctype html>\n<!-- Ubiquiti UniFi (experimental): no login page to install. The UniFi external portal\n"
            ."     redirects guests to <portal>/guest/s/<UniFi site>/ by itself; see the setup page of the site. -->\n"
            .'<meta charset="utf-8"><title>Not applicable for UniFi</title><p>Not applicable for UniFi.</p>'."\n";
    }

    public function loginPageFetch(Site $site, NasClient $nas): string
    {
        return '# Not applicable for UniFi: no login page to install (see the controller checklist).';
    }

    /** Controller checklist instead of a router script (sites with this driver have no routers). */
    public function configScript(Site $site, NasClient $nas): string
    {
        return $this->checklist($site);
    }

    /** No setup assistant inputs: the controller is configured by hand (checklist). */
    public function setupFields(): array
    {
        return ['network' => [], 'container' => []];
    }

    /** Every block id the assistant views read, with a short note: UniFi uses the checklist instead. */
    public function setupCommands(Site $site, array $container, bool $withSecrets = true): array
    {
        $note = '# Not applicable for UniFi, see the controller checklist on the setup page.';
        $blocks = [
            'container' => ['device_mode', 'container_network', 'container_add'],
            'network' => ['vlan', 'vlan_filtering', 'wifi', 'dhcp', 'captive_option', 'firewall'],
            'hotspot' => ['dns', 'hotspot'],
            'diagnostics' => ['verify', 'diagnostics'],
            'uninstall' => ['uninstall', 'uninstall_container', 'uninstall_network'],
        ];

        return array_map(fn (array $ids) => array_fill_keys($ids, $note), $blocks);
    }

    /**
     * Settings to make on the controller, as plain text: external portal, redirect, pre-authorization
     * allow list, UniFi site and the API access. Values from settings are reduced to safe characters.
     */
    public function checklist(Site $site): string
    {
        $c = UnifiSettings::forSite($site);
        $portal = (string) ($site->setting('portal_url') ?: config('app.url'));
        $host = self::safe((string) parse_url($portal, PHP_URL_HOST)) ?: '<PORTAL_HOST>';
        $ip = self::safe((string) $site->setting('portal_address')) ?: '<PORTAL_IPV4>';
        $unifiSite = self::safe($c['unifi_site']) ?: 'default';
        $social = WalledGarden::hosts();
        $lines = [
            '# Ubiquiti UniFi (experimental) - controller checklist for site '.self::safe($site->slug),
            '# Untested on hardware: please report what works and what not (test report issue).',
            '',
            '1. Guest network and SSID',
            '   - Network for guests (own VLAN, client isolation), SSID with "Hotspot Portal" switched on',
            '     (Network 9+: Hotspot zone or Settings > Hotspot).',
            '2. Hotspot > Authentication: "External Portal Server"',
            '   - External portal (IPv4 of the portal): '.$ip,
            '   - Domain / Redirect Hostname: '.$host,
            '   - Redirect: the AP sends guests to http(s)://'.$host.'/guest/s/'.$unifiSite.'/?ap=...&id=...&t=...&url=...&ssid=...',
            '   - "Use Secure Portal" (HTTPS): leave on when the portal has a trusted certificate for '.$host,
            '   - Landing page after the login: leave the original URL; the portal shows its status page.',
            '3. Pre-authorization allowances (allowed before login)',
            '   - '.$host,
            '   - '.$ip,
        ];
        foreach ($social as $entry) {
            $lines[] = '   - '.self::safe($entry).(str_starts_with($entry, '*.') ? '   (wildcard: support unverified, add the host names you need)' : '');
        }
        if ($social === []) {
            $lines[] = '   - (social login off: no further hosts)';
        }
        array_push($lines,
            '4. UniFi site name: '.$unifiSite.' (the part after /guest/s/ in the redirect; enter it in the site\'s controller section)',
            '5. API access for the portal',
            '   - UniFi OS console / UniFi OS Server: Network > Settings > Control Plane > Integrations > create an API key',
            '     with a dedicated local admin limited to this site; mode "API key".',
            '   - Standalone Network Application (10.x): a local account (no UI.com account, no 2FA) with a role that may',
            '     authorize guests (Hotspot Operator unverified, otherwise Site Admin); mode "local account".',
            '   - The portal must reach the controller on '.(self::safe((string) parse_url($c['url'], PHP_URL_HOST)) ?: '<CONTROLLER_HOST>')
                .' (HTTPS); pin its public key or keep certificate verification on.',
            '6. Check on the site page (button "Check controller"), then log in with a test device.',
            '',
            '# Not supported on UniFi sites: MAC whitelist, roaming between sites without login, RADIUS accounting.',
        );

        return implode("\n", $lines)."\n";
    }

    // --- AuthorizesViaController ---------------------------------------------------------------------

    public function controllerFields(): array
    {
        return [
            'controller.url' => ['required', 'string', 'max:255', 'url:https', 'regex:#^https://[^/?\#@]+/?$#'],
            'controller.mode' => ['required', Rule::in(UnifiSettings::MODES)],
            'controller.api_key' => ['nullable', 'string', 'max:255', 'regex:/^[\x21-\x7E]+$/'],
            'controller.api_key_clear' => ['boolean'],
            'controller.username' => ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/'],
            'controller.password' => ['nullable', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/'],
            'controller.password_clear' => ['boolean'],
            'controller.unifi_site' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'controller.verify_tls' => ['boolean'],
            'controller.tls_pin' => ['nullable', 'string', 'regex:#^sha256//[A-Za-z0-9+/]{43}=$#'],
            'controller.duration_default_s' => ['required', 'integer', 'between:60,2592000'],
        ];
    }

    public function authorizeClient(Site $site, HotspotContext $context, Grant $grant, int $seconds): ControllerAuthorization
    {
        $mac = $context->mac;
        if ($mac === null) {
            return ControllerAuthorization::failed('no_mac');
        }
        try {
            $api = $this->client($site);
            $client = $api->findClient($mac);
            if ($client === null) {
                return ControllerAuthorization::failed('not_connected', 'device not found on the controller');
            }
            if (! $client['guest']) {
                return ControllerAuthorization::failed('not_guest', 'device is not a guest on the controller');
            }
            if ($context->ap !== null && ($apMac = $api->apMac($client)) !== null && $apMac !== $context->ap) {
                return ControllerAuthorization::failed('wrong_ap', 'device is connected to another access point');
            }
            $minutes = max(1, min(1000000, (int) ceil(max(1, $seconds) / 60)));
            $result = $api->authorize($client, $minutes, $context->ap);
        } catch (UnifiException $e) {
            return ControllerAuthorization::failed($e->reason, $e->getMessage());
        }

        return new ControllerAuthorization(true, $result['expires_at'] ?? now()->addMinutes($minutes), $client['id'],
            $result['raw'].' ('.$minutes.' min)', ip: $client['ip']);
    }

    public function deauthorizeClient(Site $site, string $mac): bool
    {
        try {
            $this->client($site)->unauthorize($mac);

            return true;
        } catch (UnifiException $e) {
            Log::warning('UniFi unauthorize failed', ['site' => $site->slug, 'mac' => $mac, 'reason' => $e->reason, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function clientStatus(Site $site, string $mac): ?array
    {
        try {
            $client = $this->client($site)->findClient($mac);
        } catch (UnifiException $e) {
            Log::info('UniFi client status not available', ['site' => $site->slug, 'reason' => $e->reason, 'error' => $e->getMessage()]);

            return null;
        }
        if ($client === null) {
            return ['connected' => false, 'authorized' => false, 'ip' => null, 'bytes_in' => null, 'bytes_out' => null, 'expires_at' => null];
        }

        return array_intersect_key($client, array_flip(['connected', 'authorized', 'ip', 'bytes_in', 'bytes_out', 'expires_at']));
    }

    public function checkController(Site $site): array
    {
        if (! UnifiSettings::isConfigured($site)) {
            return ['status' => 'not_configured', 'message' => __('portal.controller.check.not_configured')];
        }
        try {
            $api = $this->client($site);
            $resolved = $api->site();
            $version = $api->version() ?? '?';
            $clients = $api->clientCount();
        } catch (UnifiException $e) {
            $key = in_array($e->reason, ['connect', 'timeout', 'tls', 'auth', 'denied', 'site_not_found', 'protocol', 'rate_limited', 'not_configured'], true)
                ? $e->reason : 'failed';

            return ['status' => $e->reason, 'message' => __('portal.controller.check.'.$key).' '.$e->getMessage()];
        }

        return ['status' => 'ok', 'message' => __('portal.controller.check.ok', ['version' => $version,
            'site' => $resolved['ref'] !== '' ? $resolved['ref'] : $resolved['name'], 'clients' => $clients])];
    }

    /** The site's controller client, logged in at most once per request or command run. */
    public function client(Site $site): UnifiClient
    {
        $config = UnifiSettings::config($site);
        // Keyed by the settings too: a changed configuration never reuses a client (or session) of the old one.
        $key = $site->id.':'.hash('sha256', serialize([$config->url, $config->mode, $config->apiKey, $config->username,
            $config->password, $config->unifiSite, $config->verifyTls, $config->tlsPin]));

        return $this->clients[$key] ??= UnifiClient::make($config);
    }

    /** http(s) URL without embedded credentials (the original URL of the redirect), else null. */
    private static function httpUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : null;
    }

    /** Host names, IPs and slugs only (the checklist is shown to admins and copied into a controller). */
    private static function safe(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9.*:_-]/', '', $value);
    }
}
