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
        # RouterOS writes the full source of a script added or changed via /system script (incl. secret
        # and token) to the system log: paste this into the terminal instead of saving it as a /system
        # script, or clear the log afterwards. The certificate script below is itself a /system script
        # holding the token, so check the log after running this too (/log print where message~"{$n}").
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
        add name={$n} dns-name={$dnsName} hotspot-address={$g['gateway']} use-radius=yes \\
            radius-accounting=yes radius-interim-update=received nas-port-type=wireless-802.11 login-by=mac
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
            ? "# Trunk port to the CAPs: must already be a port of this bridge (see the print above); only if not:\n"
                ."# /interface bridge port add bridge={$g['bridge']} interface=<TRUNK_PORT_TO_CAP>"
            : '# No trunk port needed (guest traffic is forwarded by this router into the bridge)';
        $vlanTagged = $g['wifi_mode'] === 'capsman2' ? "{$g['bridge']},<TRUNK_PORT_TO_CAP>" : $g['bridge'];

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

        $wifiRemove = match ($g['wifi_mode']) {
            'capsman1' => "# only if created for the guest network: first remove cfg-guest from slave-configurations (print)\n"
                ."/caps-man provisioning print\n"
                ."/caps-man configuration remove [find where name=cfg-guest]\n"
                ."/caps-man security remove [find where name=sec-guest]\n"
                .'/caps-man datapath remove [find where name=dp-guest]',
            'capsman2' => "# only if created for the guest network: first remove cfg-guest from slave-configurations (print)\n"
                ."/interface wifi provisioning print\n"
                ."/interface wifi configuration remove [find where name=cfg-guest]\n"
                ."/interface wifi security remove [find where name=sec-guest]\n"
                .'/interface wifi datapath remove [find where name=dp-guest]',
            default => "# only if created for the guest network: virtual AP and its configuration\n"
                ."/interface wifi remove [find where name=wifi-guest]\n"
                ."/interface wifi configuration remove [find where name=cfg-guest]\n"
                ."/interface wifi security remove [find where name=sec-guest]\n"
                .'/interface wifi datapath remove [find where name=dp-guest]',
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
                # Use Safe Mode (Ctrl+X). "{$g['bridge']}" must be the bridge that CONTAINS THE TRUNK PORT to the
                # access points / CAPs (usually your LAN bridge). Do NOT create a separate empty bridge for the
                # guests: a VLAN interface on a bridge without ports receives nothing (guests get no DHCP).
                # Check: the trunk port to the CAPs must be listed here
                /interface bridge port print where bridge={$g['bridge']}
                # Shows vlan-filtering of the bridge (see the optional block below)
                /interface bridge print where name={$g['bridge']}
                {$trunk}
                /interface vlan add name={$g['interface']} interface={$g['bridge']} vlan-id={$g['vlan_id']}
                RSC,
                'vlan_filtering' => <<<RSC
                # OPTIONAL, only if bridge "{$g['bridge']}" has vlan-filtering=yes.
                # vlan-filtering=no: tagged frames from the trunk reach the VLAN interface without any entry
                #   here; skip this block and do not switch filtering on just for the guest VLAN.
                # vlan-filtering=yes: the bridge drops VLAN {$g['vlan_id']} unless the bridge itself (for the
                #   VLAN interface) and the trunk port are tagged members:
                /interface bridge vlan add bridge={$g['bridge']} tagged={$vlanTagged} vlan-ids={$g['vlan_id']} comment="{$n}"
                # Check (VLAN {$g['vlan_id']} must list the bridge and the trunk port as tagged):
                /interface bridge vlan print where bridge={$g['bridge']}
                RSC,
                'wifi' => $wifi,
                'dhcp' => <<<RSC
                /ip address add address={$g['gateway']}{$g['prefix']} interface={$g['interface']} comment="{$n}: guest gateway"
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
                /ip dhcp-server option add code=114 name=captive-portal value="'{$captiveUrl}'" comment="{$n}"
                /ip dhcp-server network set [find where address="{$g['subnet']}"] dhcp-option=captive-portal
                RSC,
                'firewall' => <<<RSC
                /interface list add name=GUEST comment="{$n}"
                /interface list member add list=GUEST interface={$g['interface']} comment="{$n}"
                /ip firewall address-list
                add list=PORTAL address={$portalIp} comment="{$n}: portal instance (guest port)"
                add list=PORTAL_RADIUS address={$radiusIp} comment="{$n}: portal instance (RADIUS, Disconnect)"
                add list=RFC1918 address=10.0.0.0/8 comment="{$n}"
                add list=RFC1918 address=172.16.0.0/12 comment="{$n}"
                add list=RFC1918 address=192.168.0.0/16 comment="{$n}"
                # Input: place before your "drop all not from LAN" rule, after established/related accept
                /ip firewall filter
                add chain=input action=accept in-interface-list=GUEST protocol=udp dst-port=67 comment="{$n}: guest DHCP"
                add chain=input action=accept in-interface-list=GUEST protocol=udp dst-port=53 comment="{$n}: guest DNS"
                add chain=input action=accept in-interface-list=GUEST protocol=tcp dst-port=53 comment="{$n}: guest DNS tcp"
                add chain=input action=accept in-interface-list=GUEST protocol=icmp limit=10,20:packet comment="{$n}: guest ping gateway"
                add chain=input action=accept protocol=udp src-address-list=PORTAL_RADIUS dst-port={$coaPorts} comment="{$n}: Disconnect/CoA"
                add chain=input action=drop in-interface-list=GUEST comment="{$n}: guest no router management"
                # Forward: insert before your generic rules (order matters; assumes a WAN interface list)
                add chain=forward action=accept connection-state=established,related comment="{$n}: stateful"
                add chain=forward action=accept in-interface-list=GUEST dst-address-list=PORTAL protocol=tcp dst-port=443 comment="{$n}: guest to portal"
                add chain=forward action=drop in-interface-list=GUEST dst-address-list=RFC1918 comment="{$n}: guest no LAN / other VLANs / VPN"
                add chain=forward action=drop out-interface-list=GUEST in-interface-list=!GUEST connection-state=new comment="{$n}: nothing initiates towards guests"
                add chain=forward action=accept in-interface-list=GUEST out-interface-list=WAN comment="{$n}: guest internet"
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
                remove [find comment="{$n}"]
                add name={$dnsName} address={$g['gateway']} comment="{$n}"
                add name={$n}-v4.internal address={$portalIp} comment="{$n}"
                add name={$portalHost} type=CNAME cname={$n}-v4.internal comment="{$n}"
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
                /certificate print where name~"{$n}"
                # expected: login-by=https,mac and ssl-certificate set
                /ip hotspot profile print where name={$n}
                # expected: login.html in the html-directory of the profile (default hotspot/)
                /file print where name~"login.html"
                # last runs of the certificate script
                /log print where message~"{$n}"
                RSC,
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
            'uninstall' => [
                'uninstall' => <<<RSC
                # Removes everything the router script and the setup assistant created, identified by the name
                # or comment "{$n}". Safe to run when entries do not exist. Logs out connected guests.
                # Order matters: script/scheduler, hotspot (uses the certificate), then the certificates.
                /system scheduler remove [find where name="{$n}-cert"]
                /system script remove [find where name="{$n}-cert"]
                /ip hotspot remove [find where name="{$n}"]
                /ip hotspot profile remove [find where name="{$n}"]
                /ip hotspot walled-garden remove [find where comment="{$n}"]
                /ip hotspot walled-garden ip remove [find where comment="{$n}"]
                /radius remove [find where comment="{$n}"]
                # /radius incoming has no comment: only if nothing else uses Disconnect/CoA on this router:
                # /radius incoming set accept=no
                # Certificates of the certificate script and of older versions, by name only (RouterOS
                # certificates have no comment): leaf, CAs "-ca-<n>", temporary import and legacy names
                /certificate remove [find where name="{$n}" || name~"^{$n}-ca-[0-9]+\\$" || name~"^{$n}-import" || name="{$n}-new" || name~"^{$n}-new_"]
                /file remove [find where name="{$n}.crt" || name="{$n}.key"]
                # Static DNS entries of the assistant (hotspot name, portal CNAME and its internal A record)
                /ip dns static remove [find where comment="{$n}"]
                # DHCP option 114 of the guest network: the reference is cleared only if it is the network's
                # only option; otherwise a hint is printed (remove captive-portal from dhcp-option manually).
                :foreach i in=[/ip dhcp-server network find where address="{$g['subnet']}"] do={
                    :local opts [/ip dhcp-server network get \$i dhcp-option]
                    :if ([:len \$opts] = 1 && [:tostr \$opts] = "captive-portal") do={
                        /ip dhcp-server network set \$i dhcp-option=""
                    } else={
                        :if ([:len \$opts] > 1) do={ :put "{$n}: network {$g['subnet']} has more DHCP options, remove captive-portal from dhcp-option manually" }
                    }
                }
                /ip dhcp-server option remove [find where name="captive-portal" && comment="{$n}"]
                # Firewall rules and address lists of the assistant (comment starts with "{$n}")
                /ip firewall filter remove [find where comment~"^{$n}"]
                /ip firewall nat remove [find where comment~"^{$n}"]
                /ip firewall address-list remove [find where comment~"^{$n}"]
                # Older assistant versions used other comments; check and remove manually if present:
                # /ip firewall filter print where comment~"^(guest|portal): |^stateful\\$|^nothing initiates"
                # /ip firewall address-list print where list=PORTAL || list=PORTAL_RADIUS || list=RFC1918
                # login.html: the html-directory (default hotspot/) may be shared with other hotspots, so it
                # is not removed here. Delete it only if no other hotspot uses it (RouterOS creates the
                # default files again for a newly created hotspot, to verify):
                # /file remove [find where name~"hotspot/login.html"]
                RSC,
                'uninstall_container' => <<<RSC
                # OPTIONAL, only if the portal runs as RouterOS container on this router (step 1).
                # Data in {$c['disk']}/portal-data and {$c['disk']}/portal-root stays (back up, then /file remove).
                # Device mode container=yes stays enabled.
                /container stop [find where interface=veth-portal]
                :delay 10s
                /container remove [find where interface=veth-portal]
                /container mounts remove [find where list=portal-data]
                /container envs remove [find where list=portal]
                /ip address remove [find where interface={$c['bridge']}]
                /interface bridge port remove [find where interface=veth-portal]
                /interface bridge remove [find where name={$c['bridge']}]
                /interface veth remove [find where name=veth-portal]
                RSC,
                'uninstall_network' => <<<RSC
                # OPTIONAL network infrastructure of step 2. Run a line ONLY if it was created for the guest
                # network (not if it existed before or is used otherwise). Wi-Fi first, VLAN interface last.
                {$wifiRemove}
                # only if created for the guest network: DHCP server, network, pool, gateway address
                /ip dhcp-server remove [find where name=guest-dhcp]
                /ip dhcp-server network remove [find where address="{$g['subnet']}"]
                /ip pool remove [find where name={$g['pool_name']}]
                /ip address remove [find where interface={$g['interface']}]
                # only if created for the guest network: interface list GUEST
                /interface list member remove [find where list=GUEST]
                /interface list remove [find where name=GUEST]
                # only if created for the guest network: bridge VLAN entry (optional block of step 2)
                /interface bridge vlan remove [find where comment="{$n}"]
                # only if created for the guest network: guest VLAN interface
                /interface vlan remove [find where name={$g['interface']}]
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
        /system scheduler
        remove [find name="__NAME__-cert"]
        /system script
        remove [find name="__NAME__-cert"]
        add name=__NAME__-cert dont-require-permissions=no policy=read,write,policy,test source={
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
