<?php

namespace App\Nas;

use App\Models\Grant;
use App\Models\NasClient;
use App\Models\Site;
use App\Support\Mac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * MikroTik RouterOS 7 hotspot (CONCEPT.md §2.1, §5.1).
 */
class MikrotikRos7Driver implements NasDriver
{
    /**
     * Name of the hotspot, hotspot profile, RADIUS/walled-garden comments, script, scheduler and
     * certificate created by the generated script. Defined once here; the certificate script uses it too.
     */
    public const NAME = 'guest-portal';

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
        $certScript = $this->certScript($portalUrl.'/router-cert/'.$nas->id, self::safe($nas->certToken()), $nasIp);
        $n = self::NAME;

        return <<<RSC
        # Guest portal - site "{$name}", router "{$routerName}" ({$nasIp})
        # Generated script. Replace remaining <PLACEHOLDERS> with your values before running it
        # (values saved in the setup assistant of the site are filled in).
        # Contains the RADIUS secret and the certificate download token: handle it like a password.
        # Idempotent: re-running replaces the entries named/commented "{$n}" (hotspot profile "{$n}").
        # Re-running recreates the hotspot, which logs out connected guests.

        # RADIUS client (authentication + accounting) pointing to the portal instance. RouterOS >= 7.15
        # requires Message-Authenticator in replies (require-message-auth); the instance always adds it.
        /radius
        remove [find comment="{$n}"]
        add address={$radius} secret="{$secret}" service=hotspot src-address={$nasIp} timeout=3s comment="{$n}"
        # Accept Disconnect-Request (RFC 5176) from the instance; the RouterOS default port is 1700
        /radius incoming
        set accept=yes port={$nas->coa_port}

        {$hotspot}
        # Walled garden: guests may reach the portal (HTTPS) before login
        /ip hotspot walled-garden
        remove [find comment="{$n}"]
        add dst-host={$portalHost} action=allow comment="{$n}"
        # IP walled garden: action=accept (manual ambiguous between accept/allow, to verify)
        /ip hotspot walled-garden ip
        remove [find comment="{$n}"]
        add dst-address={$portalIp} protocol=tcp dst-port=443 action=accept comment="{$n}"

        {$certScript}
        # Finally upload login.html into the hotspot HTML directory (default: hotspot/ or flash/hotspot/),
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
        # Until then the RADIUS logins of the portal fail (no HTTPS login on the router).
        /ip hotspot
        remove [find name="{$n}"]
        /ip hotspot profile
        remove [find name="{$n}"]
        add name={$n} dns-name={$dnsName} hotspot-address={$g['gateway']} use-radius=yes \\
            radius-accounting=yes radius-interim-update=received nas-port-type=wireless-802.11
        :if ([:len [/certificate find where name="{$n}"]] > 0) do={
            /ip hotspot profile set [find where name="{$n}"] ssl-certificate={$n} login-by=https
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
                'wifi_mode' => ['required', 'in:capsman1,capsman2,local'],
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

    public function setupCommands(Site $site, array $container): array
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

        $trunk = $g['wifi_mode'] === 'capsman2'
            ? "/interface bridge port add bridge={$g['bridge']} interface=<TRUNK_PORT_TO_CAP> frame-types=admit-all ingress-filtering=yes\n"
                ."/interface bridge vlan add bridge={$g['bridge']} tagged={$g['bridge']},<TRUNK_PORT_TO_CAP> vlan-ids={$g['vlan_id']}"
            : "# No trunk port needed (traffic is forwarded by this router)\n"
                ."/interface bridge vlan add bridge={$g['bridge']} tagged={$g['bridge']} vlan-ids={$g['vlan_id']}";

        $wifi = match ($g['wifi_mode']) {
            'capsman1' => <<<RSC
            # Legacy CAPsMAN (/caps-man, wireless package): manager forwarding, VLAN tagged by the manager
            /caps-man datapath add name=dp-guest bridge={$g['bridge']} vlan-id={$g['vlan_id']} vlan-mode=use-tag \\
                local-forwarding=no client-to-client-forwarding=no
            /caps-man security add name=sec-guest
            /caps-man configuration add name=cfg-guest ssid="{$g['ssid']}" country=<COUNTRY> \\
                security=sec-guest datapath=dp-guest
            # Guest SSID as slave of your existing master configuration. "set" replaces the list: keep
            # existing slaves (see print) in <EXISTING_SLAVES>, or remove "<EXISTING_SLAVES>," if there are none.
            /caps-man provisioning print
            /caps-man provisioning set [find where master-configuration=<MASTER_CFG>] slave-configurations=<EXISTING_SLAVES>,cfg-guest
            /caps-man manager set enabled=yes
            RSC,
            'capsman2' => <<<RSC
            # CAPsMAN of the wifi package (/interface wifi). wifi-qcom-ac CAPs: do NOT set vlan-id
            # (no VLAN offload); set PVID {$g['vlan_id']} on the CAP's bridge port instead.
            /interface wifi datapath add name=dp-guest bridge={$g['bridge']} vlan-id={$g['vlan_id']} client-isolation=yes
            /interface wifi security add name=sec-guest
            /interface wifi configuration add name=cfg-guest ssid="{$g['ssid']}" country=<COUNTRY> \\
                security=sec-guest datapath=dp-guest
            # Guest SSID as slave of your existing master configuration. "set" replaces the list: keep
            # existing slaves (see print) in <EXISTING_SLAVES>, or remove "<EXISTING_SLAVES>," if there are none.
            /interface wifi provisioning print
            /interface wifi provisioning set [find where master-configuration=<MASTER_CFG>] slave-configurations=<EXISTING_SLAVES>,cfg-guest
            /interface wifi capsman set enabled=yes
            # On each CAP: /interface wifi cap set enabled=yes discovery-interfaces=<IF> caps-man-addresses=<MANAGER_IP>
            RSC,
            default => <<<RSC
            # Local radio of this router (wifi package): virtual AP on an existing radio
            /interface wifi datapath add name=dp-guest bridge={$g['bridge']} vlan-id={$g['vlan_id']} client-isolation=yes
            /interface wifi security add name=sec-guest
            /interface wifi configuration add name=cfg-guest ssid="{$g['ssid']}" country=<COUNTRY> \\
                security=sec-guest datapath=dp-guest
            # configuration=<name> on a virtual AP (to verify); alternative: inline configuration.ssid="{$g['ssid']}"
            /interface wifi add name=wifi-guest master-interface=<WIFI_INTERFACE> configuration=cfg-guest disabled=no
            RSC,
        };

        return [
            'container' => [
                'device_mode' => <<<'RSC'
                # Confirm physically within 5 minutes (button or cold boot; a normal reboot aborts it)
                /system/device-mode/update container=yes
                RSC,
                'container_network' => <<<RSC
                /interface/veth add name=veth-portal address={$c['veth_address']} gateway={$c['veth_gateway']}
                /interface/bridge add name={$c['bridge']}
                /interface/bridge/port add bridge={$c['bridge']} interface=veth-portal
                /ip/address add address={$c['veth_gateway']}{$c['prefix']} interface={$c['bridge']}
                RSC,
                'container_add' => <<<RSC
                /container/config set registry-url=https://ghcr.io tmpdir={$c['disk']}/tmp
                # Private image only: /container/config set username=<GITHUB_USER> password=<READ_ONLY_TOKEN>
                /container/mounts add list=portal-data src={$c['disk']}/portal-data dst=/data
                /container/envs add list=portal key=APP_URL value="{$appUrl}"
                /container add remote-image={$c['image']} interface=veth-portal \\
                    root-dir={$c['disk']}/portal-root mountlists=portal-data envlist=portal start-on-boot=yes logging=yes
                /container/start [find]
                # First admin: /container/shell [find], then: artisan portal:admin
                RSC,
            ],
            'network' => [
                'vlan' => <<<RSC
                # Use Safe Mode (Ctrl+X). Bridge "{$g['bridge']}" must exist; enable vlan-filtering last.
                {$trunk}
                /interface vlan add name={$g['interface']} interface={$g['bridge']} vlan-id={$g['vlan_id']}
                /interface bridge set {$g['bridge']} vlan-filtering=yes
                RSC,
                'wifi' => $wifi,
                'dhcp' => <<<RSC
                /ip address add address={$g['gateway']}{$g['prefix']} interface={$g['interface']} comment="guest gateway"
                /ip pool add name={$g['pool_name']} ranges={$g['pool']}
                /ip dhcp-server add name=guest-dhcp interface={$g['interface']} address-pool={$g['pool_name']} \\
                    lease-time=30m disabled=no
                /ip dhcp-server network add address={$g['subnet']} gateway={$g['gateway']} dns-server={$g['gateway']}
                # The router serves DNS to guests; upstream servers if not yet set:
                # /ip dns set servers=<UPSTREAM_DNS_1>,<UPSTREAM_DNS_2>
                /ip dns set allow-remote-requests=yes
                RSC,
                'captive_option' => <<<RSC
                # Optional: Captive Portal API URI (RFC 8910/8908) via DHCP option 114.
                # Device support for option 114 varies (to verify per client OS).
                # Only if guests reach the portal with their own IP (no source NAT of guests).
                /ip dhcp-server option add code=114 name=captive-portal value="'{$captiveUrl}'"
                /ip dhcp-server network set [find where address="{$g['subnet']}"] dhcp-option=captive-portal
                RSC,
                'firewall' => <<<RSC
                /interface list add name=GUEST
                /interface list member add list=GUEST interface={$g['interface']}
                /ip firewall address-list
                add list=PORTAL address={$portalIp} comment="portal instance (guest port)"
                add list=PORTAL_RADIUS address={$radiusIp} comment="portal instance (RADIUS, Disconnect)"
                add list=RFC1918 address=10.0.0.0/8
                add list=RFC1918 address=172.16.0.0/12
                add list=RFC1918 address=192.168.0.0/16
                # Input: place before your "drop all not from LAN" rule, after established/related accept
                /ip firewall filter
                add chain=input action=accept in-interface-list=GUEST protocol=udp dst-port=67 comment="guest: DHCP"
                add chain=input action=accept in-interface-list=GUEST protocol=udp dst-port=53 comment="guest: DNS"
                add chain=input action=accept in-interface-list=GUEST protocol=tcp dst-port=53 comment="guest: DNS tcp"
                add chain=input action=accept in-interface-list=GUEST protocol=icmp limit=10,20:packet comment="guest: ping gateway"
                add chain=input action=accept protocol=udp src-address-list=PORTAL_RADIUS dst-port={$coaPorts} comment="portal: Disconnect/CoA"
                add chain=input action=drop in-interface-list=GUEST comment="guest: no router management"
                # Forward: insert before your generic rules (order matters; assumes a WAN interface list)
                add chain=forward action=accept connection-state=established,related comment="stateful"
                add chain=forward action=accept in-interface-list=GUEST dst-address-list=PORTAL protocol=tcp dst-port=443 comment="guest: portal"
                add chain=forward action=drop in-interface-list=GUEST dst-address-list=RFC1918 comment="guest: no LAN / other VLANs / VPN"
                add chain=forward action=drop out-interface-list=GUEST in-interface-list=!GUEST connection-state=new comment="nothing initiates towards guests"
                add chain=forward action=accept in-interface-list=GUEST out-interface-list=WAN comment="guest: internet"
                RSC,
            ],
            'hotspot' => [
                'dns' => <<<RSC
                # Guests resolve the hotspot name to the router and the portal name to the instance
                /ip dns static
                remove [find comment="{$n}"]
                add name={$dnsName} address={$g['gateway']} comment="{$n}"
                add name={$portalHost} address={$portalIp} comment="{$n}"
                RSC,
                'hotspot' => rtrim($this->hotspotSection($site, $dnsName)),
            ],
            'diagnostics' => [
                'diagnostics' => <<<'RSC'
                /radius print
                # Use the number of the "guest-portal" entry from /radius print, or (to verify):
                # /radius monitor [find comment="guest-portal"] once
                /radius monitor 0 once
                /ip hotspot active print
                /ip hotspot host print
                /log print where topics~"hotspot|radius"
                RSC,
            ],
        ];
    }

    public function loginPageFetch(Site $site, NasClient $nas): string
    {
        $url = rtrim(self::url($this->portalUrl($site)), '/').'/router-login/'.$nas->id.'/login.html';
        $token = self::safe($nas->certToken());
        $nasIp = self::safe($nas->nas_ip);

        return <<<RSC
        # html-directory of the profile defaults to "hotspot"; check with /ip hotspot profile print and
        # /file print (on devices with a flash folder: flash/hotspot). dst-path below: to verify.
        /tool fetch url="{$url}" http-header-field="X-Portal-Token: {$token}" \\
            src-address={$nasIp} check-certificate=yes dst-path=hotspot/login.html
        RSC;
    }

    /**
     * Guest network values saved in the setup assistant (site setting "setup"), sanitized;
     * placeholders for missing values.
     *
     * @return array<string, string>
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
            'wifi_mode' => in_array($s['wifi_mode'] ?? null, ['capsman1', 'capsman2', 'local'], true) ? $s['wifi_mode'] : 'capsman2',
        ];
    }

    /**
     * Script + scheduler that fetch the router certificate from the instance and set it on the hotspot
     * profile when its fingerprint changed (CONCEPT.md §6). RouterOS syntax details: to verify per version.
     */
    private function certScript(string $base, string $token, string $sourceIp): string
    {
        $interval = preg_replace('/[^0-9dhms]/', '', (string) config('portal.router_cert.fetch_interval')) ?: '1d';
        $script = <<<'RSC'
        # Router certificate for login-by=https, fetched from the instance at startup and every __INTERVAL__.
        # Imported only when its fingerprint changed; sets ssl-certificate of hotspot profile "__NAME__". check-certificate=yes needs a trusted portal certificate.
        # The download is accepted only from this router's NAS IP (src-address) with its token.
        /system scheduler
        remove [find name="__NAME__-cert"]
        /system script
        remove [find name="__NAME__-cert"]
        add name=__NAME__-cert dont-require-permissions=no policy=read,write,policy,test source={
        :local base "__BASE__"
        :local hdr "X-Portal-Token: __TOKEN__"
        :local src "__SRC__"
        :local name "__NAME__"
        :local newname "__NAME__-new"
        :do {
            :local fp ([/tool fetch url=($base . "/fingerprint") http-header-field=$hdr src-address=$src check-certificate=yes output=user as-value]->"data")
            :local cur ""
            :foreach c in=[/certificate find where name=$name] do={ :set cur [/certificate get $c fingerprint] }
            :if ([:len $fp] = 64 && $fp != $cur) do={
                /tool fetch url=($base . "/router.crt") http-header-field=$hdr src-address=$src check-certificate=yes dst-path=($name . ".crt")
                /tool fetch url=($base . "/router.key") http-header-field=$hdr src-address=$src check-certificate=yes dst-path=($name . ".key")
                :delay 2s
                /certificate remove [find where name=$newname]
                /certificate import file-name=($name . ".crt") passphrase="" name=$newname
                /certificate import file-name=($name . ".key") passphrase="" name=$newname
                /file remove [find where name=($name . ".crt")]
                /file remove [find where name=($name . ".key")]
                /ip hotspot profile set [find where name=$name] ssl-certificate=$newname login-by=https
                /certificate remove [find where name=$name]
                /certificate set [find where name=$newname] name=$name
                :log info "__NAME__: router certificate updated"
            }
        } on-error={ :log warning "__NAME__: router certificate update failed" }
        }
        /system scheduler
        add name=__NAME__-cert interval=__INTERVAL__ start-time=startup on-event=__NAME__-cert policy=read,write,policy,test
        /system script run __NAME__-cert

        RSC;

        return str_replace(['__BASE__', '__TOKEN__', '__SRC__', '__NAME__', '__INTERVAL__'],
            [$base, $token, $sourceIp, self::NAME, $interval], $script);
    }

    private function portalUrl(Site $site): string
    {
        return (string) ($site->setting('portal_url') ?: config('app.url'));
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
