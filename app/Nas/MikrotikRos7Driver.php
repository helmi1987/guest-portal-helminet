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

    public function loginHandoff(HotspotContext $context, string $username, string $password): array
    {
        // Hotspot login form fields; with login-by=https the router receives PAP credentials.
        return [
            'url' => $context->loginUrl,
            'fields' => array_filter([
                'username' => $username,
                'password' => $password,
                'dst' => $context->origUrl,
                'popup' => 'true',
            ], fn ($v) => $v !== null),
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
        $portalHost = self::safe(parse_url($this->portalUrl($site), PHP_URL_HOST)) ?: '<PORTAL_HOST>';
        $portalIp = self::safe($site->setting('portal_address')) ?: $radius;
        $dnsName = self::safe(((array) $site->setting('hotspot_hosts', []))[0] ?? null) ?: '<HOTSPOT_DNS_NAME>';
        $name = self::safe($site->name);
        $routerName = self::safe($nas->name);
        $nasIp = self::safe($nas->nas_ip);
        $secret = self::safe($nas->radius_secret);

        return <<<RSC
        # Guest portal - site "{$name}", router "{$routerName}" ({$nasIp})
        # Generated script. Replace the <PLACEHOLDERS> with your values before running it.
        # Contains the RADIUS secret: handle it like a password.

        # RADIUS client (authentication + accounting) pointing to the portal instance
        /radius
        add address={$radius} secret="{$secret}" service=hotspot src-address={$nasIp} timeout=3s comment="guest-portal"
        # Accept Disconnect-Request (RFC 5176) from the instance
        /radius incoming
        set accept=yes port={$nas->coa_port}

        # Hotspot: authentication via RADIUS only, login over HTTPS with the router certificate
        /ip hotspot profile
        add name=guest-portal dns-name={$dnsName} hotspot-address=<HOTSPOT_GATEWAY_IP> \\
            login-by=https ssl-certificate=<ROUTER_CERTIFICATE> use-radius=yes \\
            radius-accounting=yes radius-interim-update=received nas-port-type=wireless-802.11
        /ip hotspot
        add name=guest-portal interface=<GUEST_INTERFACE> address-pool=<GUEST_POOL> profile=guest-portal disabled=no

        # Walled garden: guests may reach the portal (HTTPS) before login
        /ip hotspot walled-garden
        add dst-host={$portalHost} action=allow comment="guest-portal"
        /ip hotspot walled-garden ip
        add dst-address={$portalIp} protocol=tcp dst-port=443 action=accept comment="guest-portal"

        # Finally upload login.html (download from the admin UI) into the hotspot HTML directory
        # (default: hotspot/ or flash/hotspot/), replacing the existing login.html.

        RSC;
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
