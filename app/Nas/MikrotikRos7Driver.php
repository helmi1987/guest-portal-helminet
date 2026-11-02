<?php

namespace App\Nas;

use App\Models\Grant;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\Site;
use App\Nas\RouterOs\ProvisioningEditor;
use App\Nas\RouterOs\RouterStateDriver;
use App\Support\Mac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MikroTik RouterOS 7 hotspot (CONCEPT.md §2.1, §5.1).
 */
class MikrotikRos7Driver implements NasDriver, ProvisioningEditor, RouterStateDriver
{
    use MikrotikRos7Provisioning;
    use MikrotikRos7RouterState;

    /**
     * Name of the hotspot, hotspot profile, RADIUS/walled-garden comments, script, scheduler and
     * certificate created by the generated script. Defined once here; the certificate script uses it too.
     */
    public const NAME = 'guest-portal';

    /** User group of the read-only router API access (state query of the setup assistant). */
    public const API_GROUP = 'guest-portal-ro';

    /** Policies of that group (RouterOS 7): print via the API (api-ssl) or REST (www-ssl), nothing else. */
    public const API_POLICY = 'read,api,rest-api';

    /** html-directory of the hotspot profile: RouterOS default, or on devices with a flash folder. */
    public const HTML_DIRECTORIES = ['hotspot', 'flash/hotspot'];

    /** Printable ASCII, 8-63 characters: WPA2/WPA3 personal passphrase of the guest SSID (optional). */
    public const WIFI_PASSWORD_RULE = 'regex:/^[\x20-\x7E]{8,63}$/';

    /**
     * Address-list entries of the firewall block: key object => [list, address]; the address is filled
     * per site (portal and RADIUS IP).
     */
    private const FW_ADDRESSES = ['addr-portal' => 'PORTAL', 'addr-radius' => 'PORTAL_RADIUS',
        'addr-rfc1918-10' => 'RFC1918', 'addr-rfc1918-172' => 'RFC1918', 'addr-rfc1918-192' => 'RFC1918'];

    /** Filter rules of the firewall block in generated order: key object => chain. */
    private const FW_RULES = ['in-dhcp' => 'input', 'in-dns' => 'input', 'in-dns-tcp' => 'input', 'in-ping' => 'input',
        'in-coa' => 'input', 'in-drop-mgmt' => 'input', 'fwd-from-guests' => 'forward', 'fwd-to-guests' => 'forward',
        'fwd-portal' => 'forward', 'fwd-drop-lan' => 'forward', 'fwd-drop-new' => 'forward', 'fwd-internet' => 'forward'];

    /**
     * Comment key of one generated object: "guest-portal:<block>:<object>" (decision 2026-10-31). Every
     * object with a comment property gets its own key; scripts match the exact key (never a prefix).
     */
    public static function key(string $block, string $object): string
    {
        return self::NAME.':'.$block.':'.$object;
    }

    /**
     * Objects created by the generated scripts of a site, by block: [block => [key => ['path' => menu
     * path, 'name' => name (or list/identifying value) or null]]]. Basis for a router state query and
     * for the removal/re-run guards. Objects without a comment property (hotspot server and profile,
     * certificates) are identified by name and are not listed. The Wi-Fi paths follow the Wi-Fi mode.
     *
     * @return array<string, array<string, array{path: string, name: ?string}>>
     */
    public function managedObjects(Site $site): array
    {
        $g = $this->guestValues($site);
        $o = fn (string $path, ?string $name = null) => ['path' => $path, 'name' => $name];
        $blocks = [
            'radius' => ['server' => $o('/radius')],
            'walled' => ['portal-host' => $o('/ip hotspot walled-garden'), 'portal-ip' => $o('/ip hotspot walled-garden ip')],
            'cert' => ['script' => $o('/system script', self::NAME.'-cert'), 'scheduler' => $o('/system scheduler', self::NAME.'-cert')],
            'dns' => ['hotspot-name' => $o('/ip dns static'), 'portal-a' => $o('/ip dns static', self::NAME.'-v4.internal'),
                'portal-cname' => $o('/ip dns static')],
            'container' => ['veth' => $o('/interface veth', 'veth-portal'), 'bridge' => $o('/interface bridge'),
                'bridge-port' => $o('/interface bridge port', 'veth-portal'), 'gateway' => $o('/ip address'),
                'mounts' => $o('/container mounts', 'portal-data'), 'env-app-url' => $o('/container envs', 'portal')],
            'net' => ['vlan' => $o('/interface vlan', $g['interface']), 'bridge-vlan' => $o('/interface bridge vlan', $g['bridge']),
                'gateway' => $o('/ip address', $g['interface']), 'pool' => $o('/ip pool', $g['pool_name']),
                'dhcp-server' => $o('/ip dhcp-server', 'guest-dhcp'), 'dhcp-network' => $o('/ip dhcp-server network', $g['subnet']),
                'option-114' => $o('/ip dhcp-server option', 'captive-portal')],
            // Filled below: shared objects per package path, virtual APs (local), own provisioning rules per CAP
            'wifi' => [],
            'fw' => ['list-guest' => $o('/interface list', 'GUEST'), 'list-member' => $o('/interface list member', 'GUEST')]
                + array_map(fn ($list) => $o('/ip firewall address-list', $list), self::FW_ADDRESSES)
                + array_map(fn () => $o('/ip firewall filter'), self::FW_RULES),
            // Read-only API access of the state query (per router: the user name is the router's api_user)
            'api' => ['group' => $o('/user group', self::API_GROUP), 'user' => $o('/user')],
        ];
        $out = [];
        foreach ($blocks as $block => $objects) {
            $out[$block] = [];
            foreach ($objects as $object => $entry) {
                $out[$block][self::key($block, $object)] = $entry;
            }
        }
        $out['wifi'] = $this->wifiManagedObjects($g);

        return $out;
    }

    /**
     * Wi-Fi objects of the selected modes (decision 2026-11-02): dp-guest, sec-guest and cfg-guest once
     * per package path (/caps-man for capsman1, /interface wifi for capsman2 and local), one virtual AP per
     * master (local), one own provisioning rule per selected CAP radio (capsman1/capsman2). Indexed by
     * wifiId(): the comment key, or "<key>@caps-man" for the /caps-man objects while the wifi package is
     * selected too (same key in both packages); then the entry carries the comment key as "key".
     *
     * @return array<string, array{path: string, name: ?string, key?: string}>
     */
    private function wifiManagedObjects(array $g): array
    {
        $out = [];
        $add = function (string $path, ?string $name, string $key) use (&$out, $g) {
            $id = self::wifiId($key, $path, $g['wifi_modes']);
            $out[$id] = ['path' => $path, 'name' => $name] + ($id !== $key ? ['key' => $key] : []);
        };
        foreach (self::wifiPaths($g['wifi_modes']) as $path) {
            foreach (['datapath' => 'dp-guest', 'security' => 'sec-guest', 'configuration' => 'cfg-guest'] as $menu => $name) {
                $add("{$path} {$menu}", $name, self::key('wifi', $name));
            }
        }
        foreach ($g['wifi_caps'] as $mode => $macs) {
            foreach ($macs as $mac) {
                $add(self::wifiPath($mode).' provisioning', null, self::capKey($mac));
            }
        }
        if (in_array('local', $g['wifi_modes'], true)) {
            foreach ($g['wifi_masters']['local'] as $master) {
                $add('/interface wifi', self::guestInterface($master), self::key('wifi', self::guestInterface($master)));
            }
        }

        return $out;
    }

    /** Menu path of a Wi-Fi mode: legacy CAPsMAN (wireless package) or the wifi package. */
    public static function wifiPath(string $mode): string
    {
        return $mode === 'capsman1' ? '/caps-man' : '/interface wifi';
    }

    /**
     * Package paths of the selected modes, each once (capsman2 and local share /interface wifi).
     *
     * @param  list<string>  $modes
     * @return list<string>
     */
    public static function wifiPaths(array $modes): array
    {
        return array_values(array_unique(array_map(self::wifiPath(...), $modes)));
    }

    /**
     * State id of a Wi-Fi object: the comment key, or "<key>@caps-man" for an object in /caps-man while the
     * wifi package is selected too (both packages carry the same comment keys, decision 2026-11-02).
     */
    public static function wifiId(string $key, string $path, array $modes): string
    {
        return str_starts_with($path, '/caps-man') && count(self::wifiPaths($modes)) > 1 ? $key.'@caps-man' : $key;
    }

    /** Comment key of the own provisioning rule of one CAP radio: "guest-portal:wifi:cap-<12 hex digits>". */
    public static function capKey(string $mac): string
    {
        return self::key('wifi', 'cap-'.strtolower(str_replace(':', '', $mac)));
    }

    /**
     * RouterOS find condition for own objects: the comment is one of the keys, or a legacy comment of
     * scripts before the keys ("guest-portal" exact or "guest-portal: ..."; never a bare prefix, so
     * "guest-portal-old" is not matched). Re-runs replace legacy comments with the key.
     */
    private static function owned(string ...$keys): string
    {
        $n = self::NAME;
        $alts = array_map(fn ($k) => 'comment="'.$k.'"', $keys);

        return '('.implode(' || ', [...$alts, "comment=\"{$n}\"", "comment~\"^{$n}: \""]).')';
    }

    /** Keys of one block, optionally only the given objects. */
    private static function keys(string $block, array $objects): array
    {
        return array_map(fn ($o) => self::key($block, $o), $objects);
    }

    public function label(): string
    {
        return 'MikroTik RouterOS 7';
    }

    public function parseRedirect(Request $request, Site $site): HotspotContext
    {
        // Credentials go only via HTTPS (login-by=https) to a configured hotspot host.
        $login = self::httpUrl($request->query('link-login-only'));
        $hosts = array_map('strtolower', (array) $site->setting('hotspot_hosts', []));
        if ($login !== null && (parse_url($login, PHP_URL_SCHEME) !== 'https'
            || ! in_array(strtolower((string) parse_url($login, PHP_URL_HOST)), $hosts, true))) {
            Log::warning('Hotspot login URL rejected (not a configured hotspot host)', ['site' => $site->slug, 'url' => $login]);
            $login = null;
        }

        return new HotspotContext(
            mac: Mac::normalize($request->query('mac')),
            ip: filter_var($request->query('ip'), FILTER_VALIDATE_IP) ?: null,
            loginUrl: $login,
            // Only passed to the router as post-login target (dst); the portal never redirects to it.
            origUrl: self::httpUrl($request->query('link-orig')),
        );
    }

    public function loginHandoff(HotspotContext $context, string $username, string $password, string $successUrl): array
    {
        // Hotspot login form fields; with login-by=https the router receives PAP credentials.
        // After the login the router sends the browser to dst (the portal status page).
        return [
            'url' => $context->loginUrl,
            'fields' => [
                'username' => $username,
                'password' => $password,
                'dst' => $successUrl,
                'popup' => 'true',
            ],
        ];
    }

    public function replyAttributes(Grant $grant): array
    {
        return $grant->rate_limit ? ['reply:Mikrotik-Rate-Limit' => $grant->rate_limit] : [];
    }

    /**
     * RouterOS 7 hotspot answers a Disconnect-Request carrying User-Name and/or Acct-Session-Id (with
     * or without Message-Authenticator) with Disconnect-NAK, Error-Cause 406 (Unsupported-Extension);
     * only Framed-IP-Address alone removes the /ip hotspot active entry (verified on RouterOS 7).
     * The IP comes from accounting (session summary, Framed-IP-Address of the Accounting-Start).
     *
     * A hotspot IP can be reused for another guest after a DHCP lease change, so the request would
     * end that guest's session instead. Mitigation: send only while the accounting session is still
     * open (no Stop) and its last update is recent (within portal.radius.stale_after). No fallback to
     * User-Name: it is rejected anyway.
     */
    public function disconnectAttributes(RadiusAccounting $acct): ?array
    {
        $summary = $acct->summary;
        $ip = $summary?->ip;
        $context = ['acct_session_id' => $acct->acct_session_id, 'session_summary_id' => $acct->session_summary_id];

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            Log::warning('RADIUS Disconnect-Request not sent: Framed-IP-Address of the session unknown', $context);

            return null;
        }
        $staleBefore = now()->subSeconds((int) config('portal.radius.stale_after'));
        if ($summary->stopped_at !== null || $acct->terminate_cause !== null
            || $acct->last_update_at === null || $acct->last_update_at->lt($staleBefore)) {
            Log::warning('RADIUS Disconnect-Request not sent: accounting session stopped or stale, IP may be reused',
                $context + ['ip' => $ip, 'last_update_at' => $acct->last_update_at?->toIso8601String()]);

            return null;
        }

        return ['Framed-IP-Address' => $ip];
    }

    public function loginPage(Site $site): string
    {
        // RouterOS replaces $(...) variables; the -esc variants are URL-encoded (to verify per version).
        $url = rtrim($this->portalUrl($site), '/').'/portal?site='.rawurlencode($site->slug)
            .'&mac=$(mac-esc)&ip=$(ip)&link-login-only=$(link-login-only-esc)&link-orig=$(link-orig-esc)';
        $html = e($url, false);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="refresh" content="0; url={$html}">
        <title>Guest Wi-Fi</title>
        </head>
        <body>
        <p><a href="{$html}">Continue to the guest login</a></p>
        </body>
        </html>

        HTML;
    }

    public function configScript(Site $site, NasClient $nas): string
    {
        $radius = self::safe($site->setting('radius_address')) ?: '<INSTANCE_IP>';
        $portalUrl = rtrim(self::url($this->portalUrl($site)), '/');
        $portalHost = self::token(parse_url($this->portalUrl($site), PHP_URL_HOST)) ?: '<PORTAL_HOST>';
        $portalIp = self::safe($site->setting('portal_address')) ?: $radius;
        $dnsName = self::token(((array) $site->setting('hotspot_hosts', []))[0] ?? null) ?: '<HOTSPOT_DNS_NAME>';
        $name = self::safe($site->name);
        $routerName = self::safe($nas->name);
        $nasIp = self::safe($nas->nas_ip);
        $secret = self::safe($nas->radius_secret);
        $hotspot = $this->hotspotSection($site, $dnsName);
        $hotspotDir = $this->guestValues($site)['html_directory'];
        $certScript = $this->certScript($portalUrl.'/router-cert/'.$nas->id, self::safe($nas->certToken()), $nasIp);
        $n = self::NAME;
        $kRadius = self::key('radius', 'server');
        $kHost = self::key('walled', 'portal-host');
        $kIp = self::key('walled', 'portal-ip');
        $oRadius = self::owned($kRadius);
        $oHost = self::owned($kHost);
        $oIp = self::owned($kIp);

        return <<<RSC
        # Guest portal - site "{$name}", router "{$routerName}" ({$nasIp})
        # Generated script. Replace remaining <PLACEHOLDERS> with your values before running it
        # (values saved in the setup assistant of the site are filled in).
        # Contains the RADIUS secret and the certificate download token: handle it like a password.
        # RouterOS writes the full source of a script added or changed via /system script (incl. secret
        # and token) to the system log: paste this into the terminal instead of saving it as a /system
        # script, or clear the log afterwards. The certificate script below is itself a /system script
        # holding the token, so check the log after running this too (/log print where message~"{$n}").
        # Idempotent: re-running replaces only its own entries: hotspot and profile named "{$n}", other
        # entries by their comment key "{$n}:<block>:<object>" (or the comment "{$n}" / "{$n}: ..." of
        # earlier versions). Re-running recreates the hotspot, which logs out connected guests.

        # RADIUS client (authentication + accounting) pointing to the portal instance. RouterOS >= 7.15
        # requires Message-Authenticator in replies (require-message-auth); the instance always adds it.
        /radius
        remove [find where {$oRadius}]
        add address={$radius} secret="{$secret}" service=hotspot src-address={$nasIp} timeout=3s comment="{$kRadius}"
        # Accept Disconnect-Request (RFC 5176) from the instance; the RouterOS default port is 1700
        /radius incoming
        set accept=yes port={$nas->coa_port}

        {$hotspot}
        # Walled garden: guests may reach the portal (HTTPS) before login
        /ip hotspot walled-garden
        remove [find where {$oHost}]
        add dst-host={$portalHost} action=allow comment="{$kHost}"
        # IP walled garden: action=accept. Only the own entry is replaced; entries of others are never
        # changed. An existing drop entry covering {$portalIp} (e.g. dst-address=<LAN>/24 action=drop)
        # blocks the portal for guests: check with the print of the diagnostics block, remove it manually.
        /ip hotspot walled-garden ip
        remove [find where {$oIp}]
        add dst-address={$portalIp} protocol=tcp dst-port=443 action=accept comment="{$kIp}"

        {$certScript}
        # Finally upload login.html into the html-directory of the profile ({$hotspotDir}),
        # replacing the existing login.html: download it from the admin UI or use the fetch command
        # of the setup assistant.

        RSC;
    }

    /** Hotspot profile and server "guest-portal"; used by the router script and the setup assistant. */
    private function hotspotSection(Site $site, string $dnsName): string
    {
        $g = $this->guestValues($site);
        $n = self::NAME;

        return <<<RSC
        # Hotspot: authentication via RADIUS only, login over HTTPS. dns-name must be covered by the
        # router certificate (e.g. a wildcard). login-by=https needs ssl-certificate (without it the
        # profile may fall back to ssl-certificate=none), so both are set together: here if the
        # certificate "{$n}" already exists, otherwise by the certificate script after the import.
        # IMPORTANT: until the router certificate is imported the profile has only login-by=mac, so code
        # and SMS logins of the portal CANNOT work yet. Order: (1) portal certificate, (2) router
        # certificate pulled in the portal, (3) certificate script on this router, (4) login.html upload,
        # (5) check: /ip hotspot profile print where name={$n}  (login-by=https,mac + ssl-certificate)
        # login-by=mac: MAC authentication for the MAC whitelist of the portal and for roaming devices
        # (federation: a device logged in at another site with a code valid here), active from the
        # start. The router first sends an Access-Request with the MAC as User-Name (default
        # mac-auth-mode=mac-as-username, to verify); other devices get the portal.
        /ip hotspot
        remove [find name="{$n}"]
        /ip hotspot profile
        remove [find name="{$n}"]
        # html-directory as on this device: "hotspot", or "flash/hotspot" on devices with a flash folder
        # (see html-directory of: /ip hotspot profile print where default=yes)
        add name={$n} dns-name={$dnsName} hotspot-address={$g['gateway']} html-directory={$g['html_directory']} \\
            use-radius=yes radius-accounting=yes radius-interim-update=received nas-port-type=wireless-802.11 login-by=mac
        :if ([:len [/certificate find where name="{$n}"]] > 0) do={
            /ip hotspot profile set [find where name="{$n}"] ssl-certificate={$n} login-by=https,mac
        }
        /ip hotspot
        add name={$n} interface={$g['interface']} address-pool={$g['pool_name']} profile={$n} disabled=no

        RSC;
    }

    public function setupFields(): array
    {
        return [
            'network' => [
                'vlan_id' => ['required', 'integer', 'between:1,4094'],
                'bridge' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
                'interface' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
                'subnet' => ['required', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/(1[6-9]|2[0-9]|30)$/'],
                'gateway' => ['required', 'ipv4'],
                'pool' => ['required', 'string', 'max:64', 'regex:/^(\d{1,3}\.){3}\d{1,3}-(\d{1,3}\.){3}\d{1,3}$/'],
                'ssid' => ['required', 'string', 'max:32', 'regex:/^[\p{L}\p{N} ._-]+$/u'],
                // Optional; empty keeps the stored password, wifi_password_clear removes it (open SSID)
                'wifi_password' => ['nullable', 'string', self::WIFI_PASSWORD_RULE],
                'wifi_password_clear' => ['nullable', 'boolean'],
                // Share the password with other instances (replicated, data key); off: this instance only (APP_KEY)
                'wifi_password_shared' => ['nullable', 'boolean'],
                // Any combination of the Wi-Fi modes (decision 2026-11-02); the single "wifi_mode" of earlier
                // versions is still read (Site::normalizeWifiModes()), never written again
                'wifi_modes' => ['required', 'array', 'min:1'],
                'wifi_modes.*' => ['required', 'string', 'distinct', 'in:'.implode(',', Site::WIFI_MODES)],
                'html_directory' => ['nullable', 'in:'.implode(',', self::HTML_DIRECTORIES)],
            ],
            'container' => [
                'veth_address' => ['required', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/(1[6-9]|2[0-9]|30)$/'],
                'veth_gateway' => ['required', 'ipv4'],
                'bridge' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
                'disk' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\/-]+$/'],
                'image' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9._\/:@-]+$/'],
            ],
        ];
    }

    /** Placeholder of the guest Wi-Fi passphrase in the displayed (masked) setup commands. */
    public const WIFI_PASSWORD_PLACEHOLDER = '<WIFI_PASSWORD>';

    public function setupCommands(Site $site, array $container, bool $withSecrets = true): array
    {
        $g = $this->guestValues($site);
        $c = [
            'veth_address' => self::token($container['veth_address'] ?? null) ?: '<VETH_ADDRESS/PREFIX>',
            'veth_gateway' => self::token($container['veth_gateway'] ?? null) ?: '<VETH_GATEWAY>',
            'bridge' => self::token($container['bridge'] ?? null) ?: '<CONTAINER_BRIDGE>',
            'disk' => rtrim(self::token($container['disk'] ?? null), '/') ?: '<DISK>',
            'image' => self::token($container['image'] ?? null) ?: 'ghcr.io/OWNER/REPO:latest',
        ];
        $veth = self::token($container['veth_address'] ?? null);
        $c['prefix'] = str_contains($veth, '/') ? substr($veth, strrpos($veth, '/')) : '/<PREFIX>';
        $appUrl = rtrim(self::url(config('app.url')), '/') ?: 'https://<PORTAL_HOST>';
        $n = self::NAME;
        $portalHost = self::token(parse_url($this->portalUrl($site), PHP_URL_HOST)) ?: '<PORTAL_HOST>';
        $radiusIp = self::token($site->setting('radius_address')) ?: '<INSTANCE_IP>';
        $portalIp = self::token($site->setting('portal_address')) ?: $radiusIp;
        $dnsName = self::token(((array) $site->setting('hotspot_hosts', []))[0] ?? null) ?: '<HOTSPOT_DNS_NAME>';
        $captiveUrl = rtrim(self::url($this->portalUrl($site)), '/').'/captive/'.self::token($site->slug);
        // Disconnect port(s) of the site's routers (per router, default 3799).
        $coaPorts = $site->nasClients->pluck('coa_port')->map(fn ($p) => (int) $p)
            ->filter(fn ($p) => $p >= 1 && $p <= 65535)->unique()->sort()->implode(',') ?: '3799';

        $capsman2 = in_array('capsman2', $g['wifi_modes'], true);
        $trunk = $capsman2
            ? "# Trunk port to the CAPs: must already be a port of this bridge (see the print above); only if not:\n"
                ."# /interface bridge port add bridge={$g['bridge']} interface=<TRUNK_PORT_TO_CAP>"
            : '# No trunk port needed (guest traffic is forwarded by this router into the bridge)';
        $vlanTagged = $capsman2 ? "{$g['bridge']},<TRUNK_PORT_TO_CAP>" : $g['bridge'];

        // Keys of this site's objects (managedObjects()), short names for the heredocs below.
        $k = fn (string $block, string $object) => self::key($block, $object);
        $o = fn (string $block, string $object) => self::owned(self::key($block, $object));
        $up = self::upsert(...);

        // Wi-Fi (decision 2026-11-02: every selected mode, shared objects once per package path)
        [$wifi, $wifiRemove] = $this->wifiCommands($g, $withSecrets);

        // Firewall: exact keys of the address-list entries and filter rules
        $addrKeys = self::keys('fw', array_keys(self::FW_ADDRESSES));
        $ruleKeys = self::keys('fw', array_keys(self::FW_RULES));
        $addrOwned = self::owned(...$addrKeys);
        $rulesOwned = self::owned(...$ruleKeys);
        // Exactly the current rule keys (legacy rules are removed before the adds)
        $rulesExact = '('.implode(' || ', array_map(fn ($r) => 'comment="'.$r.'"', $ruleKeys)).')';
        $kDhcpNet = $k('net', 'dhcp-network');
        $kOpt = $k('net', 'option-114');
        $oOpt = $o('net', 'option-114');
        $dnsOwned = self::owned(...self::keys('dns', ['hotspot-name', 'portal-a', 'portal-cname']));
        $apiGroup = self::API_GROUP;

        // Container network (step 1)
        $containerNetwork = implode("\n", [
            $up('/interface/veth', 'name=veth-portal', $k('container', 'veth'), 'name=veth-portal',
                "address={$c['veth_address']} gateway={$c['veth_gateway']}"),
            $up('/interface/bridge', "name={$c['bridge']}", $k('container', 'bridge'), "name={$c['bridge']}", ''),
            $up('/interface/bridge/port', 'interface=veth-portal', $k('container', 'bridge-port'), 'interface=veth-portal', "bridge={$c['bridge']}"),
            $up('/ip/address', "interface={$c['bridge']}", $k('container', 'gateway'), "interface={$c['bridge']}",
                "address={$c['veth_gateway']}{$c['prefix']}"),
        ]);
        $containerLists = implode("\n", [
            $up('/container/mounts', 'list=portal-data', $k('container', 'mounts'), 'list=portal-data',
                "src={$c['disk']}/portal-data dst=/data"),
            $up('/container/envs', 'list=portal && key=APP_URL', $k('container', 'env-app-url'), 'list=portal key=APP_URL', "value=\"{$appUrl}\""),
        ]);

        return [
            'container' => [
                'device_mode' => <<<'RSC'
                # Confirm physically within 5 minutes (button or cold boot; a normal reboot aborts it)
                /system/device-mode/update container=yes
                RSC,
                'container_network' => <<<RSC
                # Re-runnable: adds the objects or updates the own ones (comment key "{$n}:container:<object>")
                {$containerNetwork}
                RSC,
                'container_add' => <<<RSC
                /container/config set registry-url=https://ghcr.io tmpdir={$c['disk']}/tmp
                # Private image only: /container/config set username=<GITHUB_USER> password=<READ_ONLY_TOKEN>
                {$containerLists}
                /container add remote-image={$c['image']} interface=veth-portal \\
                    root-dir={$c['disk']}/portal-root mountlists=portal-data envlist=portal start-on-boot=yes logging=yes
                /container/start [find]
                # First admin: /container/shell [find], then: artisan portal:admin
                RSC,
            ],
            'network' => [
                'vlan' => <<<RSC
                # Use Safe Mode (Ctrl+X). "{$g['bridge']}" must be the bridge that CONTAINS THE TRUNK PORT to the
                # access points / CAPs (usually your LAN bridge). Do NOT create a separate empty bridge for the
                # guests: a VLAN interface on a bridge without ports receives nothing (guests get no DHCP).
                # Check: the trunk port to the CAPs must be listed here
                /interface bridge port print where bridge={$g['bridge']}
                # Shows vlan-filtering of the bridge (see the optional block below)
                /interface bridge print where name={$g['bridge']}
                {$trunk}
                # Re-runnable: adds the VLAN interface or updates the own one (comment "{$k('net', 'vlan')}")
                {$up('/interface vlan', "name={$g['interface']}", $k('net', 'vlan'), "name={$g['interface']}", "interface={$g['bridge']} vlan-id={$g['vlan_id']}")}
                RSC,
                'vlan_filtering' => <<<RSC
                # OPTIONAL, only if bridge "{$g['bridge']}" has vlan-filtering=yes.
                # vlan-filtering=no: tagged frames from the trunk reach the VLAN interface without any entry
                #   here; skip this block and do not switch filtering on just for the guest VLAN.
                # vlan-filtering=yes: the bridge drops VLAN {$g['vlan_id']} unless the bridge itself (for the
                #   VLAN interface) and the trunk port are tagged members:
                {$up('/interface bridge vlan', "bridge={$g['bridge']}", $k('net', 'bridge-vlan'), "bridge={$g['bridge']}", "tagged={$vlanTagged} vlan-ids={$g['vlan_id']}")}
                # Check (VLAN {$g['vlan_id']} must list the bridge and the trunk port as tagged):
                /interface bridge vlan print where bridge={$g['bridge']}
                RSC,
                'wifi' => $wifi,
                'dhcp' => <<<RSC
                # Re-runnable: adds gateway address, pool, DHCP server and network or updates the own ones
                {$up('/ip address', "interface={$g['interface']}", $k('net', 'gateway'), "interface={$g['interface']}", "address={$g['gateway']}{$g['prefix']}")}
                {$up('/ip pool', "name={$g['pool_name']}", $k('net', 'pool'), "name={$g['pool_name']}", "ranges={$g['pool']}")}
                {$up('/ip dhcp-server', 'name=guest-dhcp', $k('net', 'dhcp-server'), 'name=guest-dhcp', "interface={$g['interface']} address-pool={$g['pool_name']} lease-time=30m disabled=no")}
                {$up('/ip dhcp-server network', "address=\"{$g['subnet']}\"", $kDhcpNet, "address={$g['subnet']}", "gateway={$g['gateway']} dns-server={$g['gateway']}")}
                # The router serves DNS to guests; upstream servers if not yet set:
                # /ip dns set servers=<UPSTREAM_DNS_1>,<UPSTREAM_DNS_2>
                /ip dns set allow-remote-requests=yes
                RSC,
                'captive_option' => <<<RSC
                # Optional: Captive Portal API URI (RFC 8910/8908) via DHCP option 114.
                # Device support for option 114 varies (to verify per client OS).
                # Only if guests reach the portal with their own IP (no source NAT of guests).
                # Re-runnable: updates the value of the own option; an option "captive-portal" of others is not
                # changed (the add then fails: rename yours or remove it first).
                {$up('/ip dhcp-server option', 'name="captive-portal"', $kOpt, 'name=captive-portal', "code=114 value=\"'{$captiveUrl}'\"")}
                # Sets the option list of the guest network (created in the DHCP block, comment "{$kDhcpNet}")
                /ip dhcp-server network set [find where address="{$g['subnet']}" && {$o('net', 'dhcp-network')}] dhcp-option=captive-portal
                RSC,
                'firewall' => <<<RSC
                # Re-runnable: list, member and address-list entries are added only if missing; an existing
                # entry with the same list and address (e.g. your own RFC1918 list) is used as it is.
                :if ([:len [/interface list find where name=GUEST]] = 0) do={ /interface list add name=GUEST comment="{$k('fw', 'list-guest')}" }
                /interface list set [find where name=GUEST && {$o('fw', 'list-guest')}] comment="{$k('fw', 'list-guest')}"
                :if ([:len [/interface list member find where list=GUEST && interface={$g['interface']}]] = 0) do={ /interface list member add list=GUEST interface={$g['interface']} comment="{$k('fw', 'list-member')}" }
                /interface list member set [find where list=GUEST && interface={$g['interface']} && {$o('fw', 'list-member')}] comment="{$k('fw', 'list-member')}"
                /ip firewall address-list
                # Own entries of an earlier run (portal IP may have changed); entries of others are kept
                remove [find where (list=PORTAL || list=PORTAL_RADIUS || list=RFC1918) && {$addrOwned}]
                :if ([:len [find where list=PORTAL && address={$portalIp}]] = 0) do={ add list=PORTAL address={$portalIp} comment="{$k('fw', 'addr-portal')}" }
                :if ([:len [find where list=PORTAL_RADIUS && address={$radiusIp}]] = 0) do={ add list=PORTAL_RADIUS address={$radiusIp} comment="{$k('fw', 'addr-radius')}" }
                :if ([:len [find where list=RFC1918 && address=10.0.0.0/8]] = 0) do={ add list=RFC1918 address=10.0.0.0/8 comment="{$k('fw', 'addr-rfc1918-10')}" }
                :if ([:len [find where list=RFC1918 && address=172.16.0.0/12]] = 0) do={ add list=RFC1918 address=172.16.0.0/12 comment="{$k('fw', 'addr-rfc1918-172')}" }
                :if ([:len [find where list=RFC1918 && address=192.168.0.0/16]] = 0) do={ add list=RFC1918 address=192.168.0.0/16 comment="{$k('fw', 'addr-rfc1918-192')}" }
                # Filter rules: one comment key per rule ("{$n}:fw:<rule>"). Re-running this block replaces them
                # (and the rules of earlier versions, comment "{$n}: ...").
                /ip firewall filter remove [find where {$rulesOwned}]
                /ip firewall filter
                # guest DHCP, DNS (udp, tcp), ping of the gateway, Disconnect/CoA from the instance, no router management
                add chain=input action=accept in-interface-list=GUEST protocol=udp dst-port=67 comment="{$k('fw', 'in-dhcp')}"
                add chain=input action=accept in-interface-list=GUEST protocol=udp dst-port=53 comment="{$k('fw', 'in-dns')}"
                add chain=input action=accept in-interface-list=GUEST protocol=tcp dst-port=53 comment="{$k('fw', 'in-dns-tcp')}"
                add chain=input action=accept in-interface-list=GUEST protocol=icmp limit=10,20:packet comment="{$k('fw', 'in-ping')}"
                add chain=input action=accept protocol=udp src-address-list=PORTAL_RADIUS dst-port={$coaPorts} comment="{$k('fw', 'in-coa')}"
                add chain=input action=drop in-interface-list=GUEST comment="{$k('fw', 'in-drop-mgmt')}"
                # Forward (order matters; assumes a WAN interface list): stateful from/to guests, guest to portal,
                # no LAN / other VLANs / VPN, nothing initiates towards guests, guest internet. The stateful
                # accepts cover guest traffic only, so FastTrack for other traffic stays as it is.
                add chain=forward action=accept connection-state=established,related in-interface-list=GUEST comment="{$k('fw', 'fwd-from-guests')}"
                add chain=forward action=accept connection-state=established,related out-interface-list=GUEST comment="{$k('fw', 'fwd-to-guests')}"
                add chain=forward action=accept in-interface-list=GUEST dst-address-list=PORTAL protocol=tcp dst-port=443 comment="{$k('fw', 'fwd-portal')}"
                add chain=forward action=drop in-interface-list=GUEST dst-address-list=RFC1918 comment="{$k('fw', 'fwd-drop-lan')}"
                add chain=forward action=drop out-interface-list=GUEST in-interface-list=!GUEST connection-state=new comment="{$k('fw', 'fwd-drop-new')}"
                add chain=forward action=accept in-interface-list=GUEST out-interface-list=WAN comment="{$k('fw', 'fwd-internet')}"
                # "add" appends at the end of a chain, i.e. after an existing drop rule (e.g. defconf "drop all
                # not coming from LAN"). Move the rules above to the top of input and forward, keeping their
                # order: each one goes before the first static rule of the chain that is not ours. Dynamic
                # rules (hotspot) stay first. Local names differ from property names on purpose.
                :foreach fwChain in={"input";"forward"} do={
                    :local fwFirst [:pick [/ip firewall filter find where chain=\$fwChain && dynamic=no && !{$rulesExact}] 0]
                    :if ([:len \$fwFirst] > 0) do={
                        :foreach fwRule in=[/ip firewall filter find where chain=\$fwChain && dynamic=no && {$rulesExact}] do={
                            /ip firewall filter move \$fwRule destination=\$fwFirst
                        }
                    }
                }
                # Check: the "{$n}:fw:" rules must come before any drop rule of input and forward.
                # /ip firewall filter print where chain=input || chain=forward
                # /ip firewall filter print where comment~"{$n}"
                # FastTrack (defconf "fasttrack-connection") may bypass the hotspot for fasttracked
                # connections (assumption, to verify). The guest stateful accepts above come before it, so
                # guest connections are not fasttracked. If guest traffic is still fasttracked, exclude the
                # guest list in the FastTrack rule, e.g.
                # /ip firewall filter set [find where action=fasttrack-connection] in-interface-list=!GUEST out-interface-list=!GUEST
                RSC,
            ],
            'hotspot' => [
                'dns' => <<<RSC
                # Two different names: the hotspot name is the router's own HTTPS login name and resolves to the
                # guest gateway; the portal name is the portal instance. They must never be the same.
                #   {$dnsName} -> {$g['gateway']} (router, guest gateway)
                #   {$portalHost} -> {$portalIp} (portal instance)
                # Portal name as CNAME to an internal name with a static A record: a plain static A entry
                # covers only A queries, AAAA queries still go upstream. With a public (wildcard) AAAA record
                # for the portal name the router's own /tool fetch (src-address=IPv4) then picks IPv6 and fails
                # ("Address family not supported by protocol"). With the CNAME the router answers AAAA with
                # "no appropriate record" and IPv4 is used. (A static type=NXDOMAIN entry would block A too.)
                # The hotspot name keeps a plain A entry: it is the router itself, the router never fetches it,
                # and guests use IPv4 on the guest network (if the guest network gets IPv6 and the hotspot name
                # has a public AAAA, use the same CNAME pattern for it).
                /ip dns static
                remove [find where {$dnsOwned}]
                add name={$dnsName} address={$g['gateway']} comment="{$k('dns', 'hotspot-name')}"
                add name={$n}-v4.internal address={$portalIp} comment="{$k('dns', 'portal-a')}"
                add name={$portalHost} type=CNAME cname={$n}-v4.internal comment="{$k('dns', 'portal-cname')}"
                # Check: IPv4 address, and "no appropriate record" for IPv6
                # :put [:resolve {$portalHost}]
                # :put [:resolve domain-name={$portalHost} type=ipv6]
                RSC,
                'hotspot' => rtrim($this->hotspotSection($site, $dnsName)),
            ],
            'diagnostics' => [
                'verify' => <<<RSC
                # Certificate and login checklist (in this order):
                # 1. portal certificate, 2. router certificate pulled in the portal, 3. certificate script
                # on this router, 4. login.html uploaded, 5. these checks:
                # imported router certificate (leaf "{$n}" with flag K = private key, CAs "{$n}-ca-<n>")
                /certificate print where name="{$n}" || name~"^{$n}-ca-[0-9]+\\$"
                # expected: login-by=mac,https and ssl-certificate={$n}
                /ip hotspot profile print where name={$n}
                # expected: login.html in the html-directory of the profile ({$g['html_directory']}/login.html)
                /file print where name~"login.html"
                # html-directory of the default profile: where this device keeps hotspot files ("hotspot", or
                # "flash/hotspot" on devices with a flash folder); the assistant setting must match it
                /ip hotspot profile print where default=yes
                # IP walled garden: an entry of others with action=drop covering the portal IP {$portalIp}
                # (e.g. dst-address=<LAN>/24 action=drop) blocks the portal for guests before login. The
                # scripts never change entries they did not create: remove or narrow such an entry manually.
                /ip hotspot walled-garden ip print
                # last runs of the certificate script
                /log print where message~"{$n}"
                # firewall: the "{$n}:fw:" rules must come before any drop rule of chain input and forward
                /ip firewall filter print where comment~"{$n}"
                /ip firewall filter print where chain=input || chain=forward
                RSC,
                'diagnostics' => <<<RSC
                /radius print
                /radius monitor [find where comment="{$k('radius', 'server')}"] once
                /ip hotspot active print
                /ip hotspot host print
                /log print where topics~"hotspot|radius"
                RSC,
            ],
            'uninstall' => [
                'uninstall' => <<<RSC
                # Removes everything the router script and the setup assistant created: hotspot, profile and
                # certificates by name "{$n}", all other objects by name plus comment key "{$n}:<block>:<object>"
                # (or the comment "{$n}" / "{$n}: ..." of earlier versions). Safe to run when entries do not
                # exist. Logs out connected guests.
                # Order matters: script/scheduler, hotspot (uses the certificate), then the certificates.
                /system scheduler remove [find where name="{$n}-cert" && ({$o('cert', 'scheduler')} || !comment)]
                /system script remove [find where name="{$n}-cert" && ({$o('cert', 'script')} || !comment)]
                /ip hotspot remove [find where name="{$n}"]
                /ip hotspot profile remove [find where name="{$n}"]
                /ip hotspot walled-garden remove [find where {$o('walled', 'portal-host')}]
                /ip hotspot walled-garden ip remove [find where {$o('walled', 'portal-ip')}]
                /radius remove [find where {$o('radius', 'server')}]
                # /radius incoming has no comment: only if nothing else uses Disconnect/CoA on this router:
                # /radius incoming set accept=no
                # /ip service is not an own object (the portal never sets it), so its certificate is not reset
                # here. Services that still use the certificate "{$n}" (e.g. www-ssl or api-ssl for the state
                # query) are reported: set them back before the certificates are removed, e.g.
                # /ip service set www-ssl certificate=none
                :foreach gpSvc in=[/ip service find where certificate="{$n}"] do={ :put ("{$n}: /ip service " . [/ip service get \$gpSvc name] . " uses the certificate {$n}; set it back manually") }
                # Certificates of the certificate script and of older versions, by name only (RouterOS
                # certificates have no comment): leaf, CAs "-ca-<n>", temporary import and legacy names
                /certificate remove [find where name="{$n}" || name~"^{$n}-ca-[0-9]+\\$" || name~"^{$n}-import" || name="{$n}-new" || name~"^{$n}-new_"]
                /file remove [find where name="{$n}.crt" || name="{$n}.key"]
                # Static DNS entries of the assistant (hotspot name, portal CNAME and its internal A record)
                /ip dns static remove [find where {$dnsOwned}]
                # DHCP option 114 of the own guest network: the reference is cleared only if it is the network's
                # only option; otherwise a hint is printed (remove captive-portal from dhcp-option manually).
                :foreach gpNet in=[/ip dhcp-server network find where address="{$g['subnet']}" && {$o('net', 'dhcp-network')}] do={
                    :local gpOpts [/ip dhcp-server network get \$gpNet dhcp-option]
                    :if ([:len \$gpOpts] = 1 && [:tostr \$gpOpts] = "captive-portal") do={
                        /ip dhcp-server network set \$gpNet dhcp-option=""
                    } else={
                        :if ([:len \$gpOpts] > 1) do={ :put "{$n}: network {$g['subnet']} has more DHCP options, remove captive-portal from dhcp-option manually" }
                    }
                }
                /ip dhcp-server option remove [find where name="captive-portal" && {$oOpt}]
                # Firewall rules and address-list entries of the assistant (its keys and lists only; "{$n}-old"
                # or entries without comment are not ours and stay)
                /ip firewall filter remove [find where {$rulesOwned}]
                /ip firewall nat remove [find where comment~"^{$n}: "]
                /ip firewall address-list remove [find where (list=PORTAL || list=PORTAL_RADIUS || list=RFC1918) && {$addrOwned}]
                # Read-only API access of the portal: its users (group {$apiGroup}), then the group
                /user remove [find where group={$apiGroup} && {$o('api', 'user')}]
                /user group remove [find where name={$apiGroup} && {$o('api', 'group')}]
                # Older assistant versions used other comments; check and remove manually if present:
                # /ip firewall filter print where comment~"^(guest|portal): |^stateful\\$|^nothing initiates"
                # /ip firewall address-list print where list=PORTAL || list=PORTAL_RADIUS || list=RFC1918
                # login.html: the html-directory ({$g['html_directory']}) may be shared with other hotspots, so
                # it is not removed here. Delete it only if no other hotspot uses it (RouterOS creates the
                # default files again for a newly created hotspot):
                # /file remove [find where name="{$g['html_directory']}/login.html"]
                # RouterOS adds disabled placeholder rules "place hotspot rules here" (filter, nat, walled
                # garden) with the first hotspot; they are not removed here (other hotspots may use them).
                RSC,
                'uninstall_container' => <<<RSC
                # OPTIONAL, only if the portal runs as RouterOS container on this router (step 1).
                # Data in {$c['disk']}/portal-data and {$c['disk']}/portal-root stays (back up, then /file remove).
                # Device mode container=yes stays enabled.
                /container stop [find where interface=veth-portal]
                :delay 10s
                /container remove [find where interface=veth-portal]
                /container mounts remove [find where list=portal-data && {$o('container', 'mounts')}]
                /container envs remove [find where list=portal && {$o('container', 'env-app-url')}]
                /ip address remove [find where interface={$c['bridge']} && {$o('container', 'gateway')}]
                /interface bridge port remove [find where interface=veth-portal && {$o('container', 'bridge-port')}]
                /interface bridge remove [find where name={$c['bridge']} && {$o('container', 'bridge')}]
                /interface veth remove [find where name=veth-portal && {$o('container', 'veth')}]
                # Created by assistant versions before 0.12.7 (no comment): check and remove manually
                # /interface veth print where name=veth-portal
                RSC,
                'uninstall_network' => <<<RSC
                # OPTIONAL network infrastructure of step 2. Removes only objects with an own comment key (or the
                # comment "{$n}" of versions 0.12.7 to 0.13.x; older ones have none: check with the prints at
                # the end and remove them manually). Wi-Fi first, VLAN interface last.
                {$wifiRemove}
                # only if created for the guest network: DHCP server, network, pool, gateway address
                /ip dhcp-server remove [find where name=guest-dhcp && {$o('net', 'dhcp-server')}]
                /ip dhcp-server network remove [find where address="{$g['subnet']}" && {$o('net', 'dhcp-network')}]
                /ip pool remove [find where name={$g['pool_name']} && {$o('net', 'pool')}]
                /ip address remove [find where interface={$g['interface']} && {$o('net', 'gateway')}]
                # interface list GUEST (only the own list and member)
                /interface list member remove [find where list=GUEST && {$o('fw', 'list-member')}]
                # (the list only when no member of others is left in it)
                :if ([:len [/interface list member find where list=GUEST]] = 0) do={ /interface list remove [find where name=GUEST && {$o('fw', 'list-guest')}] }
                # bridge VLAN entry (optional block of step 2)
                /interface bridge vlan remove [find where bridge={$g['bridge']} && {$o('net', 'bridge-vlan')}]
                # guest VLAN interface
                /interface vlan remove [find where name={$g['interface']} && {$o('net', 'vlan')}]
                # Left over without comment (older assistant versions)? Check:
                # /interface vlan print where name={$g['interface']}
                # /ip pool print where name={$g['pool_name']}
                RSC,
            ],
        ];
    }

    public function loginPageFetch(Site $site, NasClient $nas): string
    {
        $url = rtrim(self::url($this->portalUrl($site)), '/').'/router-login/'.$nas->id.'/login.html';
        $token = self::safe($nas->certToken());
        $nasIp = self::safe($nas->nas_ip);
        $dir = $this->guestValues($site)['html_directory'];
        $n = self::NAME;

        return <<<RSC
        # html-directory of the hotspot profile "{$n}" (setup assistant: {$dir}); must match
        # /ip hotspot profile print where name={$n}
        /tool fetch url="{$url}" http-header-field="X-Portal-Token: {$token}" \\
            src-address={$nasIp} check-certificate=yes dst-path={$dir}/login.html
        RSC;
    }

    /**
     * Guest network values saved in the setup assistant (site setting "setup"), sanitized;
     * placeholders for missing values.
     *
     * @return array<string, mixed>
     */
    private function guestValues(Site $site): array
    {
        $s = (array) $site->setting('setup', []);
        $vlan = (int) ($s['vlan_id'] ?? 0);
        $subnet = self::token($s['subnet'] ?? null);
        $pool = self::token($s['pool'] ?? null);

        return [
            'vlan_id' => $vlan >= 1 && $vlan <= 4094 ? (string) $vlan : '<GUEST_VLAN_ID>',
            'bridge' => self::token($s['bridge'] ?? null) ?: '<BRIDGE>',
            'interface' => self::token($s['interface'] ?? null) ?: '<GUEST_INTERFACE>',
            'subnet' => $subnet ?: '<GUEST_SUBNET/PREFIX>',
            'prefix' => str_contains($subnet, '/') ? substr($subnet, strrpos($subnet, '/')) : '/<PREFIX>',
            'gateway' => self::token($s['gateway'] ?? null) ?: '<HOTSPOT_GATEWAY_IP>',
            'pool' => $pool ?: '<POOL_START>-<POOL_END>',
            'pool_name' => $pool ? 'guest-pool' : '<GUEST_POOL>',
            'ssid' => trim(self::safe($s['ssid'] ?? null)) ?: 'Guest-WiFi',
            // Selected modes (decision 2026-11-02), their masters (instance-local, decision 2026-11-01) and the
            // CAP radios of the guest SSID per CAP (CAPsMAN modes, instance-local, decision 2026-11-02)
            'wifi_modes' => $modes = $site->wifiModes(),
            'wifi_masters' => array_combine($modes, array_map(fn ($m) => $site->wifiMasters($m), $modes)),
            'wifi_caps' => array_filter(array_combine($modes, array_map(fn ($m) => in_array($m, Site::CAPSMAN_MODES, true) ? $site->wifiCaps($m) : [], $modes))),
            'html_directory' => in_array($s['html_directory'] ?? null, self::HTML_DIRECTORIES, true) ? $s['html_directory'] : 'hotspot',
            // Decrypted guest Wi-Fi password, '' for an open SSID; only printable ASCII is kept
            'wifi_unavailable' => $site->wifiPasswordUnavailable(),
            'wifi_password' => (string) preg_replace('/[^\x20-\x7E]/', '', (string) $site->wifiPassword()),
        ];
    }

    /**
     * Script + scheduler that fetch the router certificate from the instance and set it on the hotspot
     * profile when its fingerprint changed (CONCEPT.md §6). RouterOS syntax details: to verify per version.
     *
     * Robust against chain imports: importing the PEM chain creates one certificate per PEM block (leaf +
     * CA certificates) with generated names, so nothing relies on names. The current certificate is the
     * one referenced by the profile's ssl-certificate (renaming by the user does not matter), the new
     * leaf is the imported certificate whose fingerprint matches /fingerprint (fallback: the one with a
     * private key). RouterOS certificates have no comment property, so certificates are identified by
     * name only: leaf "guest-portal", CAs "guest-portal-ca-<n>", temporary import prefix
     * "guest-portal-import" and the legacy names "guest-portal-new" / "guest-portal-new_*". No other
     * certificate is ever removed.
     *
     * Script locals use the prefix "gp": in RouterOS "find where", a local named like a property (e.g.
     * "name") is not substituted, so "name=$name" matches every item (v0.12.1-v0.12.4 removed all
     * certificates this way). Certificates are removed only by id after explicit string checks on each
     * name, never certificates referenced by a hotspot profile, and never more than 10 per run.
     */
    private function certScript(string $base, string $token, string $sourceIp): string
    {
        $interval = preg_replace('/[^0-9dhms]/', '', (string) config('portal.router_cert.fetch_interval')) ?: '1d';
        $script = <<<'RSC'
        # Router certificate for login-by=https, fetched from the instance at startup and every __INTERVAL__.
        # Imported only when its fingerprint differs from the certificate set on hotspot profile "__NAME__";
        # then sets ssl-certificate and login-by=https,mac. check-certificate=yes needs a trusted portal certificate.
        # The download is accepted only from this router's IP (src-address) with its token.
        # Imported certificates (leaf + chain CAs) get the temporary name "__NAME__-import*"; the leaf is renamed
        # to "__NAME__", the CA certificates to "__NAME__-ca-<n>". RouterOS certificates have no comment, so
        # only certificates with these names (plus the legacy names "__NAME__-new", "__NAME__-new_*") are
        # ever removed; certificates referenced by a hotspot profile are kept; more than 10 aborts the run.
        # Fingerprints are compared lowercase without ":" (instance: sha256 lowercase hex; RouterOS
        # "fingerprint" property: format to verify, normalised here).
        # Errors are logged with the failing step: /log print where message~"__NAME__"
        # Uses :onerror (RouterOS >= 7.13, to verify on the installed version).
        # Script and scheduler: by name plus own comment key (versions before the keys set no comment)
        /system scheduler
        remove [find where name="__NAME__-cert" && (__OWNED_SCHEDULER__ || !comment)]
        /system script
        remove [find where name="__NAME__-cert" && (__OWNED_SCRIPT__ || !comment)]
        add name=__NAME__-cert comment="__KEY_SCRIPT__" dont-require-permissions=no policy=read,write,policy,test source={
        # Local names start with "gp": inside "find where" a local with the same name as a property
        # (e.g. "name") is not substituted, so "name=$name" would match every item. Never use such names.
        :local gpBase "__BASE__"
        :local gpHdr "X-Portal-Token: __TOKEN__"
        :local gpSrc "__SRC__"
        :local gpName "__NAME__"
        :local gpTmp "__NAME__-import"
        :local gpStep "start"
        # Lowercase hex without ":", spaces or line breaks
        :local gpNorm do={
            :local gs [:tostr $1]
            :local gr ""
            :local gup "ABCDEF"
            :local glo "abcdef"
            :if ([:len $gs] > 0) do={
                :for gi from=0 to=([:len $gs] - 1) do={
                    :local gch [:pick $gs $gi]
                    :local gpos [:find $gup $gch]
                    :if ([:typeof $gpos] = "num") do={ :set gch [:pick $glo $gpos] }
                    :if ([:typeof [:find "0123456789abcdef" $gch]] = "num") do={ :set gr ($gr . $gch) }
                }
            }
            :return $gr
        }
        # Removes certificates of this script only, by explicit string checks on each name (no find
        # filter, no computed expression passed to /certificate remove). $1 = base name, $2 = temporary
        # import prefix, $3 = "pre" (temporary names, CAs, legacy names) or "post" (previous leaf, CAs,
        # legacy names; never temporary names). Owned names: $1, $1-ca-<digits>, $1-new, $1-new_*, $2*.
        # A certificate referenced by any hotspot profile is never removed. Returns the number removed,
        # or -1 (nothing removed) when more than 10 certificates would be removed.
        :local gpRemove do={
            :local gb [:tostr $1]
            :local gt [:tostr $2]
            :local gmode [:tostr $3]
            :local gca ($gb . "-ca-")
            :local gnew ($gb . "-new_")
            :local gused [:toarray ""]
            :foreach gp in=[/ip hotspot profile find] do={
                :set gused ($gused , [:tostr [/ip hotspot profile get $gp ssl-certificate]])
            }
            :local gdel [:toarray ""]
            :foreach gc in=[/certificate find] do={
                :local gn [:tostr [/certificate get $gc name]]
                :local gkind ""
                :if ([:len $gn] > 0) do={
                    :if ([:len $gn] >= [:len $gt] && [:pick $gn 0 [:len $gt]] = $gt) do={
                        :set gkind "tmp"
                    } else={
                        :if ($gn = $gb) do={ :set gkind "leaf" }
                        :if ($gn = ($gb . "-new")) do={ :set gkind "legacy" }
                        :if ([:len $gn] >= [:len $gnew] && [:pick $gn 0 [:len $gnew]] = $gnew) do={ :set gkind "legacy" }
                        :if ([:len $gn] > [:len $gca] && [:pick $gn 0 [:len $gca]] = $gca) do={
                            :local gdig [:pick $gn [:len $gca] [:len $gn]]
                            :local gok true
                            :for gk from=0 to=([:len $gdig] - 1) do={
                                :if ([:typeof [:find "0123456789" [:pick $gdig $gk]]] != "num") do={ :set gok false }
                            }
                            :if ($gok) do={ :set gkind "ca" }
                        }
                    }
                }
                :local gwant false
                :if ($gmode = "pre" && ($gkind = "tmp" || $gkind = "ca" || $gkind = "legacy")) do={ :set gwant true }
                :if ($gmode = "post" && ($gkind = "leaf" || $gkind = "ca" || $gkind = "legacy")) do={ :set gwant true }
                :if ($gwant) do={
                    :foreach gu in=$gused do={ :if ($gu = $gn) do={ :set gwant false } }
                }
                :if ($gwant) do={ :set gdel ($gdel , $gc) }
            }
            :if ([:len $gdel] > 10) do={
                :log error ($gb . ": refusing to remove " . [:len $gdel] . " certificates (safety limit 10)")
                :return -1
            }
            :foreach gc in=$gdel do={ /certificate remove $gc }
            :return [:len $gdel]
        }
        :onerror gpErr in={
            :set gpStep "hotspot profile"
            :local gpProfs [/ip hotspot profile find where name=$gpName]
            :if ([:len $gpProfs] != 1) do={ :error ("hotspot profile " . $gpName . " not found or not unique") }
            :local gpProf [:pick $gpProfs 0]
            :set gpStep "fetch fingerprint"
            :local gpFp [$gpNorm ([/tool fetch url=($gpBase . "/fingerprint") http-header-field=$gpHdr src-address=$gpSrc check-certificate=yes output=user as-value]->"data")]
            :if ([:len $gpFp] != 64) do={ :error "unexpected fingerprint from the instance" }
            :set gpStep "current certificate"
            # Certificate currently set on the profile (by reference, whatever its name is)
            :local gpSc [:tostr [/ip hotspot profile get $gpProf ssl-certificate]]
            :local gpCur ""
            :if ($gpSc != "none" && [:len $gpSc] > 0) do={
                :foreach c in=[/certificate find where name=$gpSc] do={ :set gpCur [$gpNorm [/certificate get $c fingerprint]] }
            }
            :if ($gpFp != $gpCur) do={
                :set gpStep "download"
                /tool fetch url=($gpBase . "/router.crt") http-header-field=$gpHdr src-address=$gpSrc check-certificate=yes dst-path=($gpName . ".crt")
                /tool fetch url=($gpBase . "/router.key") http-header-field=$gpHdr src-address=$gpSrc check-certificate=yes dst-path=($gpName . ".key")
                :delay 2s
                :set gpStep "cleanup before import"
                # Leftovers of an aborted run (temporary import names), previous CA certificates of this
                # script ("-ca-<n>") and legacy names; never the certificate in use. Other names are never touched.
                :if ([$gpRemove $gpName $gpTmp "pre"] < 0) do={ :error "too many certificates to remove, aborted" }
                :set gpStep "import certificate chain"
                /certificate import file-name=($gpName . ".crt") passphrase="" name=$gpTmp
                :set gpStep "import private key"
                # name= as in earlier versions; whether the key import renames the matched certificate: to verify
                /certificate import file-name=($gpName . ".key") passphrase="" name=$gpTmp
                /file remove [find where name=($gpName . ".crt")]
                /file remove [find where name=($gpName . ".key")]
                :set gpStep "find imported leaf"
                :local gpLeaf ""
                :foreach c in=[/certificate find where name~("^" . $gpTmp)] do={
                    :if ([$gpNorm [/certificate get $c fingerprint]] = $gpFp) do={ :set gpLeaf $c }
                }
                :if ([:typeof $gpLeaf] != "id") do={
                    # Fallback: the imported certificate that received the private key (to verify)
                    :foreach c in=[/certificate find where name~("^" . $gpTmp) && private-key] do={ :set gpLeaf $c }
                }
                :if ([:typeof $gpLeaf] != "id") do={ :error "imported leaf certificate not found" }
                :if (![/certificate get $gpLeaf private-key]) do={ :error "private key not matched to the leaf certificate" }
                :set gpStep "set hotspot profile"
                /ip hotspot profile set $gpProf ssl-certificate=[/certificate get $gpLeaf name] login-by=https,mac
                :set gpStep "remove previous certificate"
                # Previous leaf/CAs by name (the new ones still have the temporary name and are in use);
                # a previous certificate renamed by the user is not removed (remove it manually)
                :if ([$gpRemove $gpName $gpTmp "post"] < 0) do={ :error "too many certificates to remove, aborted" }
                :set gpStep "rename"
                :onerror gpE in={ /certificate set $gpLeaf name=$gpName } do={ :log warning ($gpName . ": leaf not renamed: " . $gpE) }
                :local gpIdx 1
                :foreach c in=[/certificate find where name~("^" . $gpTmp)] do={
                    :onerror gpE in={ /certificate set $c name=($gpName . "-ca-" . $gpIdx) } do={ :log warning ($gpName . ": CA not renamed: " . $gpE) }
                    :set gpIdx ($gpIdx + 1)
                }
                # Set again by name in case the profile keeps the name instead of a reference (to verify)
                /ip hotspot profile set $gpProf ssl-certificate=[/certificate get $gpLeaf name] login-by=https,mac
                :log info ($gpName . ": router certificate updated")
            }
        } do={ :log warning ($gpName . ": router certificate update failed at step '" . $gpStep . "': " . $gpErr) }
        }
        /system scheduler
        add name=__NAME__-cert comment="__KEY_SCHEDULER__" interval=__INTERVAL__ start-time=startup on-event=__NAME__-cert policy=read,write,policy,test
        /system script run __NAME__-cert

        RSC;

        return str_replace(['__BASE__', '__TOKEN__', '__SRC__', '__OWNED_SCHEDULER__', '__OWNED_SCRIPT__', '__KEY_SCRIPT__',
            '__KEY_SCHEDULER__', '__NAME__', '__INTERVAL__'],
            [$base, $token, $sourceIp, self::owned(self::key('cert', 'scheduler')), self::owned(self::key('cert', 'script')),
                self::key('cert', 'script'), self::key('cert', 'scheduler'), self::NAME, $interval], $script);
    }

    private function portalUrl(Site $site): string
    {
        return (string) ($site->setting('portal_url') ?: config('app.url'));
    }

    /** Name of the guest virtual AP on a master interface (mode local), e.g. "wifi-guest-wifi1". */
    public static function guestInterface(string $master): string
    {
        return 'wifi-guest-'.$master;
    }

    /**
     * Wi-Fi block and its uninstall part for every selected mode (decision 2026-11-02): one sub-block per
     * mode in the order capsman1, capsman2, local; dp-guest, sec-guest and cfg-guest once per package path
     * (capsman2 and local share the /interface wifi objects), generated with the first mode of the path.
     * One password guard and one "{ ... }" group around the whole block. A single mode gives the block of
     * earlier versions.
     *
     * @return array{0: string, 1: string} Wi-Fi block, uninstall part
     */
    private function wifiCommands(array $g, bool $withSecrets): array
    {
        $guard = ! $withSecrets && ! $g['wifi_unavailable'] && $g['wifi_password'] !== ''
            ? ':if ("'.self::WIFI_PASSWORD_PLACEHOLDER.'" = ("<" . "'.substr(self::WIFI_PASSWORD_PLACEHOLDER, 1).'")) do={ :error "replace '
                .self::WIFI_PASSWORD_PLACEHOLDER.' first" }'."\n"
            : '';
        $k = self::key('wifi', 'cfg-guest');
        $wifiHeader = "# Re-runnable: adds dp-guest, sec-guest and cfg-guest or updates the own ones (comment key\n"
            ."# \"{$k}\" etc.); objects of others with these names are never changed (the add fails).";
        $done = [];
        $parts = [];
        foreach ($g['wifi_modes'] as $mode) {
            $path = self::wifiPath($mode);
            if (! isset($done[$path])) {
                $objects = $this->wifiObjects($path, $g, $withSecrets);
                if ($done === [] && $guard !== '') {
                    // One "{ ... }" unit around the whole Wi-Fi block (closed at the end): a terminal paste runs
                    // line by line, so lines outside the group would still run after the guard's :error.
                    $objects = "{\n".$guard.$objects;
                }
                $objects = $wifiHeader."\n".$objects;
                $done[$path] = true;
            } else {
                $objects = '# dp-guest, sec-guest and cfg-guest of the wifi package: created above (shared with CAPsMAN of the wifi package)';
            }
            $masters = $g['wifi_masters'][$mode] ?? [];
            // Masters (decision 2026-11-01): selected on this instance, else one placeholder master (a master
            // that does not exist on the router is skipped with a message, also a placeholder left unfilled).
            $masters = $masters !== [] ? $masters : [$mode === 'local' ? '<WIFI_INTERFACE>' : '<MASTER_CFG>'];
            $tail = $mode === 'local' ? '' : $this->wifiProvisioning($path, $masters, $g['wifi_caps'][$mode] ?? []);
            $parts[] = match ($mode) {
                'capsman1' => <<<RSC
                # Legacy CAPsMAN (/caps-man, wireless package): manager forwarding, VLAN tagged by the manager
                {$objects}
                {$tail}
                /caps-man manager set enabled=yes
                RSC,
                'capsman2' => <<<RSC
                # CAPsMAN of the wifi package (/interface wifi). wifi-qcom-ac CAPs: do NOT set vlan-id
                # (no VLAN offload); set PVID {$g['vlan_id']} on the CAP's bridge port instead.
                {$objects}
                {$tail}
                /interface wifi capsman set enabled=yes
                # On each CAP: /interface wifi cap set enabled=yes discovery-interfaces=<IF> caps-man-addresses=<MANAGER_IP>
                RSC,
                default => <<<RSC
                # Local radio of this router (wifi package): virtual AP on an existing radio
                {$objects}
                # configuration=<name> on a virtual AP (to verify); alternative: inline configuration.ssid="{$g['ssid']}"
                {$this->wifiVirtualAps($masters)}
                RSC,
            };
        }
        // Own cap rules of a CAPsMAN package whose mode is no longer selected (while the other CAPsMAN package is):
        // not removed here. The menu may not exist on this router (package not installed) and a command of a
        // missing menu is not safe to emit, so only a hint is printed (the uninstall of a selected mode removes them)
        $capsmanSelected = array_intersect($g['wifi_modes'], Site::CAPSMAN_MODES);
        if ($capsmanSelected !== []) {
            foreach (array_diff(Site::CAPSMAN_MODES, $capsmanSelected) as $mode) {
                $parts[] = '# Not selected: '.self::wifiPath($mode).' CAPsMAN. Own provisioning rules there (comment key "'.self::NAME.":wifi:cap-<mac>\")\n"
                    ."# of an earlier selection stay on the router: remove them by hand (menu exists only with its package) or\n"
                    .'# select the mode again and run the uninstall.'."\n"
                    .':put "'.self::NAME.': '.self::wifiPath($mode).' is not selected; own cap rules there (if any) were not removed"';
            }
        }
        $wifi = implode("\n", $parts).($guard !== '' ? "\n}" : '');

        $remove = [];
        foreach (self::wifiPaths($g['wifi_modes']) as $path) {
            $ownedCfg = self::owned(self::key('wifi', 'cfg-guest'));
            $lines = [];
            $capsman = $path === '/caps-man' || in_array('capsman2', $g['wifi_modes'], true);
            if ($capsman) {
                // Own provisioning rules per CAP first (they list cfg-guest), then cfg-guest out of the others
                $lines[] = $this->wifiCapRulesRemove($path);
                $lines[] = $this->wifiSlaveRemove($path, $ownedCfg);
            }
            if ($path === '/interface wifi' && in_array('local', $g['wifi_modes'], true)) {
                // Virtual APs per master (and the single one of scripts before 0.16), then the configuration
                $lines[] = "# only if created for the guest network: virtual APs and their configuration\n"
                    .implode('', array_map(fn ($m) => '/interface wifi remove [find where name="'.self::guestInterface($m).'" && '
                        .self::owned(self::key('wifi', self::guestInterface($m)))."]\n", $g['wifi_masters']['local'] ?? []))
                    .'/interface wifi remove [find where name=wifi-guest && '.self::owned(self::key('wifi', 'wifi-guest')).']';
            } else {
                $lines[] = '# only if created for the guest network: configuration, security, datapath';
            }
            $lines[] = "{$path} configuration remove [find where name=cfg-guest && {$ownedCfg}]\n"
                ."{$path} security remove [find where name=sec-guest && ".self::owned(self::key('wifi', 'sec-guest'))."]\n"
                ."{$path} datapath remove [find where name=dp-guest && ".self::owned(self::key('wifi', 'dp-guest')).']';
            $remove[] = implode("\n", $lines);
        }

        return [$wifi, implode("\n", $remove)];
    }

    /** dp-guest, sec-guest (password or open) and cfg-guest of one package path, add-or-update. */
    private function wifiObjects(string $path, array $g, bool $withSecrets): string
    {
        $v1 = $path === '/caps-man';
        $kSec = self::key('wifi', 'sec-guest');
        $datapath = $v1
            ? "bridge={$g['bridge']} vlan-id={$g['vlan_id']} vlan-mode=use-tag local-forwarding=no client-to-client-forwarding=no"
            : "bridge={$g['bridge']} vlan-id={$g['vlan_id']} client-isolation=yes";
        $security = self::upsert("{$path} security", 'name=sec-guest', $kSec, 'name=sec-guest', '');
        if ($g['wifi_unavailable']) {
            // WPA site whose password is kept on another instance: never fall back to an open network
            $security = "# Guest Wi-Fi password is set for this site but not available on this instance (kept local on another one).\n"
                .'# sec-guest is NOT created or changed here: enter the password in step 2 on this instance, or run this on the instance that has it.';
        } elseif ($g['wifi_password'] !== '') {
            // Masked for display (setup assistant page): the placeholder is replaced in the browser only
            // when the admin asks for it; a copy with the placeholder left in aborts before any change.
            $pass = $withSecrets ? self::quoted($g['wifi_password']) : self::WIFI_PASSWORD_PLACEHOLDER;
            $auth = $v1 ? 'authentication-types=wpa2-psk encryption=aes-ccm' : 'authentication-types=wpa2-psk,wpa3-psk';
            $security .= "\n# Guest Wi-Fi password of the setup assistant (WPA2/WPA3 personal); re-running updates it\n"
                ."{$path} security set [find where name=sec-guest && comment=\"{$kSec}\"] {$auth} passphrase=\"{$pass}\"";
        } else {
            $unset = $v1 ? ['passphrase', 'authentication-types', 'encryption'] : ['passphrase', 'authentication-types'];
            $security .= "\n# No guest Wi-Fi password in the setup assistant: open SSID (a password set earlier is removed)\n"
                .implode("\n", array_map(fn ($p) => "{$path} security unset [find where name=sec-guest && comment=\"{$kSec}\"] {$p}", $unset));
        }

        return self::upsert("{$path} datapath", 'name=dp-guest', self::key('wifi', 'dp-guest'), 'name=dp-guest', $datapath)."\n"
            .$security."\n"
            .self::upsert("{$path} configuration", 'name=cfg-guest', self::key('wifi', 'cfg-guest'), 'name=cfg-guest',
                "ssid=\"{$g['ssid']}\" country=<COUNTRY> security=sec-guest datapath=dp-guest");
    }

    /**
     * Provisioning part of a CAPsMAN mode: without a CAP selection cfg-guest is appended to the rules of the
     * selected masters (decision 2026-11-01); with one, every selected radio gets an own rule (guest SSID per
     * CAP, decision 2026-11-02) and cfg-guest is taken out of the other rules. Own rules of radios no longer
     * selected are removed in both cases. Never provisions: CAPsMAN applies rule changes to the CAPs itself
     * (reported by the maintainer, not verified on the CHR); the script only prints the rules and a hint.
     *
     * @param  list<string>  $masters
     * @param  list<string>  $caps  radio MACs (AA:BB:CC:DD:EE:FF)
     */
    private function wifiProvisioning(string $path, array $masters, array $caps): string
    {
        $out = $caps === []
            ? $this->wifiSlaveAppend($path, $masters)
            : $this->wifiCapRules($path, $caps)."\n"
                ."# Guest SSID per CAP: cfg-guest out of the slave configurations of the other provisioning rules\n"
                .$this->wifiSlaveRemove($path, self::owned(self::key('wifi', 'cfg-guest')), true);
        $out .= "\n".$this->wifiCapRulesCleanup($path, $caps);

        return $out."\n"
            ."{$path} provisioning print\n"
            ."# Rule changes are applied to the CAPs by CAPsMAN. If a CAP did not pick them up (check the print above\n"
            ."# and the CAP's interfaces), provision that CAP manually; this script never provisions.";
    }

    /**
     * Guest SSID per CAP, "variant A" (decision 2026-11-02, verified on wifi package hardware with RouterOS
     * 7.24.5; /caps-man untested on hardware): per selected radio an own provisioning rule
     * "radio-mac=<mac>" with comment key "guest-portal:wifi:cap-<mac>", placed before the rule of the admin
     * that provisions the radio now (first match wins), copying its action, band matcher, master and slave
     * configurations and name formats, plus cfg-guest. The admin's rule is found on the router: the master
     * configuration of the radio's master interface (radio-mac, no master interface), then the first rule
     * that is not an own CAP rule with that master configuration and no other radio-mac. Radio or rule not
     * found: reported and skipped (an existing own rule stays as it is). Add-or-update by the key; moved
     * before the admin's rule only when it comes after it (re-runs change nothing).
     *
     * @param  list<string>  $caps
     */
    private function wifiCapRules(string $path, array $caps): string
    {
        $p = "{$path} provisioning";
        $v1 = $path === '/caps-man';
        $ifMenu = $v1 ? '/caps-man interface' : '/interface wifi';
        $band = $v1 ? 'hw-supported-modes' : 'supported-bands';
        $own = self::NAME.':wifi:cap-';
        // Copied from the admin's rule (RouterOS 7.24.5, checked on the CHR): /caps-man sets every property
        // (also empty values, it has no unset); /interface wifi unsets an empty band matcher, while its name
        // formats cannot be unset: an own rule with a name format the admin's rule no longer has is removed
        // (by id after the key match) and added again
        $copy = $v1
            ? ["        {$p} set \$gpOwn {$band}=[{$p} get \$gpRule {$band}] name-format=[{$p} get \$gpRule name-format] name-prefix=[{$p} get \$gpRule name-prefix]"]
            : ["        :if ([:len [{$p} get \$gpRule {$band}]] > 0) do={ {$p} set \$gpOwn {$band}=[{$p} get \$gpRule {$band}] } else={ {$p} unset \$gpOwn {$band} }",
                "        :if ([:len [{$p} get \$gpRule name-format]] > 0) do={ {$p} set \$gpOwn name-format=[{$p} get \$gpRule name-format] }",
                "        :if ([:len [{$p} get \$gpRule slave-name-format]] > 0) do={ {$p} set \$gpOwn slave-name-format=[{$p} get \$gpRule slave-name-format] }"];
        $out = "# Guest SSID only on the selected CAP radios: own provisioning rule per radio (radio-mac, comment key\n"
            ."# \"{$own}<mac>\") before the rule that provisions the radio now, copying it plus cfg-guest. Radio not\n"
            .'# connected or no rule found: reported and skipped (an existing own rule stays as it is).';
        foreach ($caps as $mac) {
            $key = self::capKey($mac);
            $readd = $v1 ? '' : "        :if ([:len \$gpOwn] > 0) do={\n"
                ."            :if (([:len [{$p} get \$gpRule name-format]] = 0 && [:len [{$p} get \$gpOwn name-format]] > 0) || ([:len [{$p} get \$gpRule slave-name-format]] = 0 && [:len [{$p} get \$gpOwn slave-name-format]] > 0)) do={\n"
                ."                {$p} remove \$gpOwn\n"
                ."                :set gpOwn \"\"\n"
                ."            }\n"
                ."        }\n";
            $out .= "\n{\n"
                ."    :local gpCfg \"\"\n"
                ."    :foreach gpIf in=[{$ifMenu} find where radio-mac={$mac}] do={\n"
                ."        :local gpMaster [:tostr [{$ifMenu} get \$gpIf master-interface]]\n"
                ."        :if (\$gpMaster = \"\" || \$gpMaster = \"none\") do={ :set gpCfg [:tostr [{$ifMenu} get \$gpIf configuration]] }\n"
                ."    }\n"
                ."    :local gpRule \"\"\n"
                ."    :if ([:len \$gpCfg] > 0) do={\n"
                ."        :foreach gpR in=[{$p} find where master-configuration=\$gpCfg] do={\n"
                ."            :local gpRm [:tostr [{$p} get \$gpR radio-mac]]\n"
                ."            :if ([:len \$gpRule] = 0 && [:tostr [{$p} get \$gpR disabled]] != \"true\" && !([:tostr [{$p} get \$gpR comment]] ~ \"^{$own}\") && (\$gpRm = \"\" || \$gpRm = \"00:00:00:00:00:00\" || \$gpRm = \"{$mac}\")) do={ :set gpRule \$gpR }\n"
                ."        }\n"
                ."    }\n"
                ."    :if ([:len \$gpRule] = 0) do={\n"
                ."        :put (\"guest-portal: radio {$mac}: not connected or no provisioning rule of the admin for its configuration (\" . \$gpCfg . \"), skipped\")\n"
                ."    } else={\n"
                ."        :local gpSlaves \"\"\n"
                ."        :foreach gpSlave in=[{$p} get \$gpRule slave-configurations] do={ :if ([:len \$gpSlave] > 0 && \$gpSlave != \"cfg-guest\") do={ :set gpSlaves (\$gpSlaves . \$gpSlave . \",\") } }\n"
                ."        :set gpSlaves (\$gpSlaves . \"cfg-guest\")\n"
                ."        :local gpOwn [:pick [{$p} find where comment=\"{$key}\"] 0]\n"
                .$readd
                ."        :if ([:len \$gpOwn] = 0) do={\n"
                ."            {$p} add place-before=\$gpRule action=[{$p} get \$gpRule action] radio-mac={$mac} master-configuration=\$gpCfg slave-configurations=\$gpSlaves comment=\"{$key}\"\n"
                ."            :set gpOwn [:pick [{$p} find where comment=\"{$key}\"] 0]\n"
                ."        }\n"
                ."        {$p} set \$gpOwn action=[{$p} get \$gpRule action] radio-mac={$mac} master-configuration=\$gpCfg slave-configurations=\$gpSlaves\n"
                .implode("\n", $copy)."\n"
                ."        :if ([:find [{$p} find] \$gpOwn] > [:find [{$p} find] \$gpRule]) do={ {$p} move \$gpOwn destination=\$gpRule }\n"
                ."    }\n"
                .'}';
        }

        return $out;
    }

    /**
     * Own provisioning rules per CAP whose radio is no longer selected (all of them without a selection):
     * removed by id after comparing the comment with the exact keys (never a bare prefix match).
     *
     * @param  list<string>  $caps
     */
    private function wifiCapRulesCleanup(string $path, array $caps): string
    {
        $p = "{$path} provisioning";
        // An empty "{}" is a syntax error on RouterOS 7.24.5: [:toarray ""]
        $keep = $caps === [] ? '[:toarray ""]' : '{'.implode(';', array_map(fn ($m) => '"'.self::capKey($m).'"', $caps)).'}';
        $pattern = '^'.self::NAME.':wifi:cap-[0-9a-f]{12}\$';

        return "# Own provisioning rules of CAP radios no longer selected for the guest SSID are removed\n"
            ."{\n"
            ."    :local gpKeep {$keep}\n"
            ."    :foreach gpR in=[{$p} find where comment~\"{$pattern}\"] do={\n"
            ."        :if ([:typeof [:find \$gpKeep [{$p} get \$gpR comment]]] = \"nil\") do={ {$p} remove \$gpR }\n"
            ."    }\n"
            .'}';
    }

    /** Uninstall: own provisioning rules per CAP (exact key pattern, by id). */
    private function wifiCapRulesRemove(string $path): string
    {
        $p = "{$path} provisioning";
        $pattern = '^'.self::NAME.':wifi:cap-[0-9a-f]{12}\$';

        return '# Own provisioning rules of the guest SSID per CAP (comment key "'.self::NAME.":wifi:cap-<mac>\"), by id\n"
            .":foreach gpR in=[{$p} find where comment~\"{$pattern}\"] do={ {$p} remove \$gpR }";
    }

    /**
     * CAPsMAN v1/v2: append cfg-guest to the slave configurations of every provisioning rule whose
     * master-configuration is one of the selected masters, only when missing; the existing slaves are kept
     * (computed on the router). This append is the only change the scripts make to objects of others
     * (decision 2026-11-01); other rules are not touched. Script locals use the prefix "gp" (never named
     * like a property). A master without a rule is reported and skipped.
     *
     * @param  list<string>  $masters
     */
    private function wifiSlaveAppend(string $path, array $masters): string
    {
        $p = "{$path} provisioning";
        $out = "# Guest SSID as additional slave of the provisioning rules of the selected master configurations:\n"
            ."# cfg-guest is appended only when missing, existing slave configurations are kept, other rules are not changed.\n"
            ."{$p} print";
        foreach ($masters as $m) {
            $find = "{$p} find where master-configuration=\"{$m}\"";
            $out .= "\n:if ([:len [{$find}]] = 0) do={ :put \"guest-portal: no provisioning rule with master-configuration={$m}, skipped\" }\n"
                .":foreach gpRule in=[{$find}] do={\n"
                ."    :local gpList \"\"\n"
                ."    :local gpHas false\n"
                ."    :foreach gpSlave in=[{$p} get \$gpRule slave-configurations] do={\n"
                ."        :if (\$gpSlave = \"cfg-guest\") do={ :set gpHas true }\n"
                ."        :if ([:len \$gpSlave] > 0) do={ :set gpList (\$gpList . \$gpSlave . \",\") }\n"
                ."    }\n"
                ."    :if (!\$gpHas) do={ {$p} set \$gpRule slave-configurations=(\$gpList . \"cfg-guest\") }\n"
                .'}';
        }

        return $out;
    }

    /**
     * CAPsMAN v1/v2 uninstall: take cfg-guest out of the slave configurations of every provisioning rule
     * that lists it (all rules, not only the selected masters: the selection may have changed), only while
     * an own cfg-guest exists; the other slaves are kept in their order, an empty list is set as "" on
     * /caps-man ("unset" does not work there) and unset on /interface wifi (set "" would leave an explicit
     * empty value, unset restores the state of a rule without slaves). Like the append, this is an approved change to objects of
     * others (decision 2026-11-01). Runs before the configuration is removed; re-running changes nothing.
     */
    private function wifiSlaveRemove(string $path, string $ownedCfg, bool $exceptOwnRules = false): string
    {
        $p = "{$path} provisioning";
        // Guest SSID per CAP: the own rules per radio keep cfg-guest (exact key pattern)
        $find = $exceptOwnRules ? 'find where !(comment~"^'.self::NAME.':wifi:cap-[0-9a-f]{12}\\$")' : 'find';
        // Empty list: /caps-man has no "unset" for it (set ""); the wifi package's unset restores the default
        $empty = $path === '/caps-man' ? "{$p} set \$gpRule slave-configurations=\"\"" : "{$p} unset \$gpRule slave-configurations";

        return "# Approved exception to \"never change objects of others\" (like the append of the Wi-Fi block): cfg-guest\n"
            ."# out of the slave configurations of every provisioning rule that lists it (only while the own cfg-guest\n"
            ."# exists); the other slave configurations are kept, nothing else of the rules is changed.\n"
            .":if ([:len [{$path} configuration find where name=cfg-guest && {$ownedCfg}]] > 0) do={\n"
            ."    :foreach gpRule in=[{$p} {$find}] do={\n"
            ."        :local gpList \"\"\n"
            ."        :local gpHas false\n"
            ."        :foreach gpSlave in=[{$p} get \$gpRule slave-configurations] do={\n"
            ."            :if (\$gpSlave = \"cfg-guest\") do={ :set gpHas true } else={ :if ([:len \$gpSlave] > 0) do={ :set gpList (\$gpList . \",\" . \$gpSlave) } }\n"
            ."        }\n"
            ."        :if (\$gpHas) do={\n"
            ."            :if ([:len \$gpList] = 0) do={ {$empty} } else={ {$p} set \$gpRule slave-configurations=[:pick \$gpList 1 [:len \$gpList]] }\n"
            ."        }\n"
            ."    }\n"
            .'}';
    }

    /**
     * Mode local: one virtual AP "wifi-guest-<master>" per selected master interface, add-or-update by
     * name + own key "guest-portal:wifi:wifi-guest-<master>". The single "wifi-guest" of scripts before
     * 0.16 on that master (own key or legacy comment) is renamed to it. A master that does not exist is
     * reported and skipped.
     *
     * @param  list<string>  $masters
     */
    private function wifiVirtualAps(array $masters): string
    {
        $old = self::owned(self::key('wifi', 'wifi-guest'));
        $lines = [];
        foreach ($masters as $m) {
            $name = self::guestInterface($m);
            $key = self::key('wifi', $name);
            $find = "find where name=\"{$name}\" && ".self::owned($key);
            $lines[] = ":if ([:len [/interface wifi find where name=\"{$m}\"]] = 0) do={ :put \"guest-portal: master interface {$m} not found, {$name} skipped\" } else={\n"
                ."    :if ([:len [/interface wifi {$find}]] = 0) do={ :foreach gpIf in=[/interface wifi find where name=wifi-guest && master-interface=\"{$m}\" && {$old}] do={ /interface wifi set \$gpIf name=\"{$name}\" comment=\"{$key}\" } }\n"
                ."    :if ([:len [/interface wifi {$find}]] = 0) do={ /interface wifi add name=\"{$name}\" master-interface=\"{$m}\" configuration=cfg-guest disabled=no comment=\"{$key}\" } else={ /interface wifi set [{$find}] configuration=cfg-guest comment=\"{$key}\" }\n"
                .'}';
        }

        return implode("\n", $lines);
    }

    /**
     * Add-or-update of one own object: adds it when no object matching $match with an own comment
     * exists, otherwise sets the values by id (find) and replaces a legacy comment with the key. An
     * object of others with the same name is never changed (the add fails on it).
     */
    private static function upsert(string $path, string $match, string $key, string $ident, string $props): string
    {
        $find = "find where {$match} && ".self::owned($key);
        $props = $props === '' ? '' : $props.' ';

        return ":if ([:len [{$path} {$find}]] = 0) do={\n"
            ."    {$path} add {$ident} {$props}comment=\"{$key}\"\n"
            ."} else={\n"
            ."    {$path} set [{$find}] {$props}comment=\"{$key}\"\n"
            .'}';
    }

    /** Value inside a quoted RouterOS string: printable ASCII with \\ " $ ? escaped (passphrase). */
    private static function quoted(string $value): string
    {
        return addcslashes((string) preg_replace('/[^\x20-\x7E]/', '', $value), '\\"$?');
    }

    /** Strip characters with special meaning in RouterOS scripts (quotes, $, \\, newlines, ...). */
    private static function safe(mixed $value): string
    {
        return (string) preg_replace('/[^\p{L}\p{N} ._:\/-]/u', '', (string) $value);
    }

    /** URL inside a quoted RouterOS string: URL characters only (no quotes, $, \\, spaces, brackets). */
    private static function url(mixed $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9:\/._\-?&=%~]/', '', (string) $value);
    }

    /** Unquoted RouterOS value (names, addresses, ranges): no spaces, quotes or script characters. */
    private static function token(mixed $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._:\/,@-]/', '', (string) $value);
    }

    private static function httpUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);

        // http(s) only, no embedded credentials.
        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : null;
    }
}
