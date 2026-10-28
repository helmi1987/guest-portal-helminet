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
        $portalUrl = rtrim(self::safe($this->portalUrl($site)), '/');
        $portalHost = self::safe(parse_url($this->portalUrl($site), PHP_URL_HOST)) ?: '<PORTAL_HOST>';
        $portalIp = self::safe($site->setting('portal_address')) ?: $radius;
        $dnsName = self::safe(((array) $site->setting('hotspot_hosts', []))[0] ?? null) ?: '<HOTSPOT_DNS_NAME>';
        $name = self::safe($site->name);
        $routerName = self::safe($nas->name);
        $nasIp = self::safe($nas->nas_ip);
        $secret = self::safe($nas->radius_secret);
        $certScript = $this->certScript($portalUrl.'/router-cert/'.$nas->id, self::safe($nas->certToken()));

        return <<<RSC
        # Guest portal - site "{$name}", router "{$routerName}" ({$nasIp})
        # Generated script. Replace the <PLACEHOLDERS> with your values before running it.
        # Contains the RADIUS secret and the certificate download token: handle it like a password.

        # RADIUS client (authentication + accounting) pointing to the portal instance. RouterOS >= 7.15
        # requires Message-Authenticator in replies (require-message-auth); the instance always adds it.
        /radius
        add address={$radius} secret="{$secret}" service=hotspot src-address={$nasIp} timeout=3s comment="guest-portal"
        # Accept Disconnect-Request (RFC 5176) from the instance; the RouterOS default port is 1700
        /radius incoming
        set accept=yes port={$nas->coa_port}

        # Hotspot: authentication via RADIUS only, login over HTTPS. dns-name must be covered by the
        # router certificate (e.g. a wildcard); the certificate is set by the script below.
        /ip hotspot profile
        add name=guest-portal dns-name={$dnsName} hotspot-address=<HOTSPOT_GATEWAY_IP> \\
            login-by=https use-radius=yes \\
            radius-accounting=yes radius-interim-update=received nas-port-type=wireless-802.11
        /ip hotspot
        add name=guest-portal interface=<GUEST_INTERFACE> address-pool=<GUEST_POOL> profile=guest-portal disabled=no

        # Walled garden: guests may reach the portal (HTTPS) before login
        /ip hotspot walled-garden
        add dst-host={$portalHost} action=allow comment="guest-portal"
        # IP walled garden: action=accept (manual ambiguous between accept/allow, to verify)
        /ip hotspot walled-garden ip
        add dst-address={$portalIp} protocol=tcp dst-port=443 action=accept comment="guest-portal"

        {$certScript}
        # Finally upload login.html (download from the admin UI) into the hotspot HTML directory
        # (default: hotspot/ or flash/hotspot/), replacing the existing login.html.

        RSC;
    }

    /**
     * Script + scheduler that fetch the router certificate from the instance and set it on the hotspot
     * profile when its fingerprint changed (CONCEPT.md §6). RouterOS syntax details: to verify per version.
     */
    private function certScript(string $base, string $token): string
    {
        $interval = preg_replace('/[^0-9dhms]/', '', (string) config('portal.router_cert.fetch_interval')) ?: '1d';
        $script = <<<'RSC'
        # Router certificate for login-by=https, fetched from the instance at startup and every __INTERVAL__.
        # Imported only when its fingerprint changed. check-certificate=yes needs a trusted portal certificate.
        /system script
        add name=guest-portal-cert dont-require-permissions=no policy=read,write,policy,test source={
        :local base "__BASE__"
        :local hdr "X-Portal-Token: __TOKEN__"
        :local name "guest-portal"
        :local newname "guest-portal-new"
        :do {
            :local fp ([/tool fetch url=($base . "/fingerprint") http-header-field=$hdr check-certificate=yes output=user as-value]->"data")
            :local cur ""
            :foreach c in=[/certificate find where name=$name] do={ :set cur [/certificate get $c fingerprint] }
            :if ([:len $fp] = 64 && $fp != $cur) do={
                /tool fetch url=($base . "/router.crt") http-header-field=$hdr check-certificate=yes dst-path="guest-portal.crt"
                /tool fetch url=($base . "/router.key") http-header-field=$hdr check-certificate=yes dst-path="guest-portal.key"
                :delay 2s
                /certificate remove [find where name=$newname]
                /certificate import file-name="guest-portal.crt" passphrase="" name=$newname
                /certificate import file-name="guest-portal.key" passphrase="" name=$newname
                /file remove [find where name="guest-portal.crt"]
                /file remove [find where name="guest-portal.key"]
                /ip hotspot profile set [find where name=$name] ssl-certificate=$newname
                /certificate remove [find where name=$name]
                /certificate set [find where name=$newname] name=$name
                :log info "guest-portal: router certificate updated"
            }
        } on-error={ :log warning "guest-portal: router certificate update failed" }
        }
        /system scheduler
        add name=guest-portal-cert interval=__INTERVAL__ start-time=startup on-event=guest-portal-cert policy=read,write,policy,test
        /system script run guest-portal-cert

        RSC;

        return str_replace(['__BASE__', '__TOKEN__', '__INTERVAL__'], [$base, $token, $interval], $script);
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
