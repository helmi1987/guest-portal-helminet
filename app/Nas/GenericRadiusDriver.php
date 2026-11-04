<?php

namespace App\Nas;

use App\Auth\Social\WalledGarden;
use App\Models\Grant;
use App\Models\NasClient;
use App\Models\RadiusAccounting;
use App\Models\Site;
use App\Nas\Generic\GenericSettings;
use App\Nas\Generic\Presets;
use App\Support\Mac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Generic RADIUS captive portal (CONCEPT.md §5.1, decision 2026-11-04, v0.28.0): EXPERIMENTAL. For NAS that
 * redirect guests to an external page and authenticate the posted credentials against RADIUS (PAP), like the
 * MikroTik hotspot. Parameter names, the login URL, the handoff fields and the RADIUS options come from the
 * per-site mapping (GenericSettings); vendor presets prefill it (Presets, unverified). No scripts: the router
 * configuration is a checklist.
 */
class GenericRadiusDriver implements NasDriver, ReadsRedirectSignals, SiteBoundDriver
{
    public const KEY = 'generic_radius';

    private ?Site $site = null;

    public function forSite(Site $site): static
    {
        $bound = clone $this;
        $bound->site = $site;

        return $bound;
    }

    public function label(): string
    {
        return 'Generic RADIUS captive portal (experimental)';
    }

    /** Mapping of the bound site (defaults while unbound). */
    private function mapping(?Site $site = null): array
    {
        $site ??= $this->site;

        return $site ? GenericSettings::forSite($site) : GenericSettings::forSite(new Site);
    }

    public function parseRedirect(Request $request, Site $site): HotspotContext
    {
        $m = $this->mapping($site);
        $params = $this->captureParams($request, $m);
        $ip = $m['param_ip'] !== '' ? filter_var(self::query($request, $m['param_ip']), FILTER_VALIDATE_IP) : false;

        return new HotspotContext(
            mac: Mac::fromRedirect(self::query($request, $m['param_mac'])),
            ip: $ip ?: null,
            loginUrl: $this->loginUrl($request, $site, $m, $params),
            // Kept for completeness: the handoff sends the status page as the success URL.
            origUrl: $m['param_orig'] !== '' ? self::httpUrl(self::query($request, $m['param_orig'])) : null,
            params: $params,
        );
    }

    /**
     * has_login_url: the mapped login URL parameter is present, or (fixed or template login URL) the
     * redirect carries the MAC parameter, i.e. it comes from the NAS. Code prefill from the original URL.
     */
    public function redirectSignals(Request $request, Site $site): array
    {
        $m = $this->mapping($site);
        $hasLogin = $m['param_login_url'] !== ''
            ? $request->filled($m['param_login_url'])
            : $m['login_url'] !== '' && $request->filled($m['param_mac']);

        return ['has_login_url' => $hasLogin,
            'prefill_code' => $m['param_orig'] !== '' ? RedirectSignals::codeFromUrl($request->query($m['param_orig'])) : null];
    }

    /**
     * Mapped form fields; extra fields first (the mapped names win), {name} placeholders filled from the
     * redirect, {handoff_secret} from the encrypted site setting. Method GET: the view navigates to the URL
     * with the fields as query string; fragment: with the fields after "#" (never sent to a server, a page of
     * the NAS reads and posts them).
     *
     * @return array{url: string, fields: array<string, string>, method: string}
     */
    public function loginHandoff(HotspotContext $context, string $username, string $password, string $successUrl): array
    {
        $m = $this->mapping();
        $fields = [];
        foreach (GenericSettings::extraFields($m['extra_fields']) as $name => $value) {
            $filled = $this->fill($value, $context->params, true);
            if ($filled !== null) {
                $fields[$name] = $filled;
            }
        }
        $fields[$m['field_username']] = $username;
        $fields[$m['field_password']] = $m['password_encoding'] === 'chilli'
            ? $this->chilliPassword($password, $context->params['challenge'] ?? null) : $password;
        if ($m['field_success'] !== '') {
            $fields[$m['field_success']] = $successUrl;
        }

        return ['url' => (string) $context->loginUrl, 'fields' => $fields, 'method' => $m['method']];
    }

    /** Standard attributes only (set by the core); WISPr bandwidth limits when switched on and the grant has a rate limit. */
    public function replyAttributes(Grant $grant): array
    {
        if (! $this->mapping()['wispr'] || ($rates = self::rates($grant->rate_limit)) === null) {
            return [];
        }

        return ['reply:WISPr-Bandwidth-Max-Up' => $rates[0], 'reply:WISPr-Bandwidth-Max-Down' => $rates[1]];
    }

    /**
     * The identifying attributes chosen on the site page; none chosen: the NAS does not support it (OPNsense),
     * Session-Timeout ends access. Never for a session that already ended. The IP
     * can be reused by another guest after a DHCP lease change: Framed-IP-Address is only sent while the
     * session is open and was updated within portal.radius.stale_after (as the MikroTik driver does);
     * otherwise it is left out, and with nothing left nothing is sent.
     */
    public function disconnectAttributes(RadiusAccounting $acct): ?array
    {
        $summary = $acct->summary;
        $context = ['acct_session_id' => $acct->acct_session_id, 'session_summary_id' => $acct->session_summary_id];
        $selected = $this->mapping($acct->nasClient?->site)['disconnect_attrs'];
        if ($selected === []) {
            Log::info('RADIUS Disconnect-Request not sent: not supported by this NAS (no attributes selected); Session-Timeout ends access', $context);

            return null;
        }
        if ($summary?->stopped_at !== null || $acct->terminate_cause !== null) {
            Log::warning('RADIUS Disconnect-Request not sent: accounting session already stopped', $context);

            return null;
        }
        $staleBefore = now()->subSeconds((int) config('portal.radius.stale_after'));
        $fresh = $acct->last_update_at !== null && ! $acct->last_update_at->lt($staleBefore);
        $attrs = [];
        foreach ($selected as $name) {
            $value = match ($name) {
                'User-Name' => $acct->username,
                'Acct-Session-Id' => $acct->acct_session_id,
                'Framed-IP-Address' => $fresh && filter_var($summary?->ip, FILTER_VALIDATE_IP) ? $summary->ip : null,
                'Calling-Station-Id' => Mac::normalize($summary?->mac),
                default => null,
            };
            if (is_string($value) && $value !== '') {
                $attrs[$name] = $value;
            } elseif ($name === 'Framed-IP-Address') {
                Log::info('Framed-IP-Address left out of the Disconnect-Request: unknown, or session stale (IP may be reused)',
                    $context + ['last_update_at' => $acct->last_update_at?->toIso8601String()]);
            }
        }
        if ($attrs === []) {
            Log::warning('RADIUS Disconnect-Request not sent: no identifying attribute available', $context);

            return null;
        }

        return $attrs;
    }

    /**
     * Page for a NAS that hosts its own login page. Default: copies the whole query string to
     * <portal>/portal/g/<slug> (JavaScript; without it the link opens the portal without parameters). With a
     * template: a meta refresh to <portal>/portal?site=<slug>&<template>, the NAS replaces its variables.
     */
    public function loginPage(Site $site): string
    {
        $m = $this->mapping($site);
        $base = rtrim(self::portalUrl($site), '/');
        if ($m['login_page_template'] !== '') {
            $html = e($base.'/portal?site='.rawurlencode($site->slug).'&'.$m['login_page_template'], false);
            $head = '<meta http-equiv="refresh" content="0; url='.$html.'">';
        } else {
            $html = e($base.'/portal/g/'.rawurlencode($site->slug), false);
            $js = json_encode($base.'/portal/g/'.rawurlencode($site->slug), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            $head = "<script>location.replace({$js} + location.search);</script>";
        }

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {$head}
        <title>Guest Wi-Fi</title>
        </head>
        <body>
        <p><a href="{$html}">Continue to the guest login</a></p>
        </body>
        </html>

        HTML;
    }

    public function loginPageFetch(Site $site, NasClient $nas): string
    {
        return '# Generic RADIUS captive portal: upload the downloaded login page (site page > Download login page)'
            ."\n# to your device if it hosts its own portal page; most devices redirect to the portal URL instead (checklist).";
    }

    /** Checklist instead of a script; with the router's RADIUS secret (this page is admin only). */
    public function configScript(Site $site, NasClient $nas): string
    {
        return $this->checklist($site, $nas);
    }

    public function setupFields(): array
    {
        return ['network' => [], 'container' => []];
    }

    public function setupCommands(Site $site, array $container, bool $withSecrets = true): array
    {
        $note = '# Not applicable for the generic RADIUS captive portal, see the checklist on the setup page.';
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
     * What to configure on the NAS, as plain text: RADIUS, CoA, the redirect, the walled garden and the
     * success URL. Without a router the secret is a placeholder. Values are reduced to safe characters.
     */
    public function checklist(Site $site, ?NasClient $nas = null): string
    {
        $m = $this->mapping($site);
        $portal = rtrim(self::portalUrl($site), '/');
        $host = self::safe((string) parse_url($portal, PHP_URL_HOST)) ?: '<PORTAL_HOST>';
        $portalUrl = self::safeUrl($portal) ?: '<PORTAL_URL>';
        $radius = self::safe((string) $site->setting('radius_address')) ?: '<INSTANCE_IP>';
        $portalIp = self::safe((string) $site->setting('portal_address')) ?: $radius;
        $slug = self::safe($site->slug);
        $hotspots = array_map(self::safe(...), (array) $site->setting('hotspot_hosts', []));
        $preset = $m['preset'];
        $social = WalledGarden::hosts();
        $login = $m['param_login_url'] !== '' ? 'from the redirect parameter "'.$m['param_login_url'].'"'
            : ($m['login_url'] !== '' ? self::safeUrl($m['login_url']) : '<NOT SET>');

        $lines = [
            '# Generic RADIUS captive portal (experimental) - checklist for site '.$slug.($nas ? ', router '.self::safe($nas->name) : ''),
            '# Preset: '.$preset.' (unverified). Please report what works and what not (test report issue).',
            $nas ? '# Contains the RADIUS secret of this router: keep it private.' : '# The RADIUS secret of each router is on its "Router configuration" page.',
            '',
            '1. RADIUS server (authentication and accounting)',
            '   - Server: '.$radius.'  ports 1812 (auth) and 1813 (accounting), UDP',
            '   - Shared secret: '.($nas ? self::safe($nas->radius_secret) : '<RADIUS_SECRET>'),
            '   - Source address of the NAS: '.($nas ? self::safe($nas->nas_ip) : '<NAS_IP>').' (the router entry\'s NAS IP is the source address',
            '     of the RADIUS packets as the portal sees them: behind NAT or a Docker bridge the translated address)',
            $nas && $nas->nas_identifier ? '   - NAS-Identifier: '.self::safe($nas->nas_identifier) : '   - NAS-Identifier: optional; if set on the router entry it must match',
            '   - Authentication: PAP; Calling-Station-Id = the client MAC (any notation)',
            '   - Accounting: Start, Interim-Update (interval from the Acct-Interim-Interval reply), Stop',
            '2. Disconnect-Request (RFC 5176, CoA)',
            ...($m['disconnect_attrs'] !== [] ? [
                '   - Accept Disconnect-Requests from '.$radius.' on UDP port '.($nas ? (int) $nas->coa_port : '<COA_PORT>').' with the same secret',
                '   - Attributes sent: '.implode(', ', $m['disconnect_attrs']),
            ] : [
                '   - Not used (no attributes selected: the NAS does not support it). Revoking or shortening a grant ends',
                '     access only when the Session-Timeout of the login runs out.',
            ]),
            '3. Redirect of unauthenticated guests (external portal / external web authentication)',
            '   - Portal URL: '.$portalUrl.'/portal/g/'.$slug.'  (the NAS appends its parameters)',
            '   - Alternative for a NAS that hosts its own page: upload the login page (site page > Download login page)',
            '   - Parameters read: MAC "'.$m['param_mac'].'"'.($m['param_ip'] !== '' ? ', IP "'.$m['param_ip'].'"' : '')
                .($m['param_orig'] !== '' ? ', original URL "'.$m['param_orig'].'"' : ''),
            '4. Login (handoff) of the guest\'s browser after the portal login',
            '   - '.$m['method'].' to '.$login,
            '   - The login host must be one of the site\'s hotspot host names: '.($hotspots !== [] ? implode(', ', $hotspots) : '<HOTSPOT_HOSTS: none set>'),
            '   - Fields: '.$m['field_username'].', '.$m['field_password'].($m['password_encoding'] === 'chilli' ? ' (CoovaChilli UAM encoding)' : '')
                .($m['field_success'] !== '' ? ', '.$m['field_success'].' (success URL)' : '')
                .(($extra = array_keys(GenericSettings::extraFields($m['extra_fields']))) !== [] ? ', '.implode(', ', array_map(self::safe(...), $extra)) : ''),
            '   - Success URL: the portal status page (signed, per login); the NAS should send the browser there after the login.',
            '     A NAS that ignores it shows its own page; the portal works anyway.',
            ...($m['method'] === 'fragment' ? ['   - Method "fragment": the browser opens the login URL with the fields after "#"; a page on the NAS must read',
                '     and post them (OPNsense: docs/opnsense-captive-template/index.html).'] : []),
            ...(str_starts_with(strtolower($m['login_url']), 'http://') ? ['   - Plain HTTP: the one-time credentials travel unencrypted over the guest network'
                .($m['method'] === 'POST' ? '; browsers warn before posting a form from the HTTPS portal to HTTP' : '').'.'] : []),
            '5. Walled garden (allowed before login; '.self::walledGardenName($preset).')',
            '   - '.$host.'  TCP 443 (HTTPS)',
            '   - '.$portalIp.'  TCP 443 (and TCP 80 if the NAS redirects plain HTTP to the portal IP)',
            '   - DNS for the guests (UDP/TCP 53 to the resolver handed out by DHCP)',
        ];
        foreach ($social as $entry) {
            $lines[] = '   - '.self::safe($entry).'  TCP 443'.(str_starts_with($entry, '*.') ? '  (wildcard: only if the NAS supports it, otherwise list the host names you need)' : '');
        }
        if ($social === []) {
            $lines[] = '   - (social login off: no further hosts)';
        }
        array_push($lines,
            '6. MAC authentication (MAC whitelist, roaming between sites): only if the NAS sends the client MAC as User-Name',
            '   (and as Calling-Station-Id) in an Access-Request; otherwise these features do not work on this site.',
            '7. Test: site page > "Simulate redirect", then log in with a test device and check the guest list.',
            '',
            '# Not available for this driver: router state query, setup assistant scripts, automatic login page download.',
        );

        return implode("\n", $lines)."\n";
    }

    /**
     * Sample redirect of the mapping for "Simulate redirect": the preset's sample values, completed by
     * the mapped parameter names; {hotspot} becomes the site's first hotspot host.
     *
     * @return array<string, string>
     */
    public function sampleQuery(Site $site): array
    {
        $m = $this->mapping($site);
        $hotspot = ((array) $site->setting('hotspot_hosts', []))[0] ?? 'hotspot.example.com';
        $query = Presets::SAMPLES[$m['preset']] ?? [];
        $defaults = [$m['param_mac'] => 'aa:bb:cc:00:00:01', $m['param_ip'] => '10.0.0.20',
            $m['param_login_url'] => 'https://{hotspot}/login', $m['param_orig'] => 'http://example.com/'];
        foreach (array_merge($this->placeholders($m), $m['password_encoding'] === 'chilli' ? ['challenge'] : []) as $name) {
            $defaults[$name] ??= '{hotspot}';
        }
        unset($defaults['']);
        $query += $defaults;

        return array_map(fn (string $v) => str_replace('{hotspot}', $hotspot, $v), $query);
    }

    /** Parameter names the mapping uses as {name} placeholders (login URL template, extra fields). */
    private function placeholders(array $m): array
    {
        preg_match_all('/\{([A-Za-z0-9_.-]{1,40})\}/', $m['login_url'].' '.$m['extra_fields'], $found);

        return array_values(array_diff(array_unique($found[1]), [GenericSettings::SECRET]));
    }

    /** Redirect values the mapping refers to, when they are safe to put into a URL or field. */
    private function captureParams(Request $request, array $m): array
    {
        $params = [];
        $names = $this->placeholders($m);
        if ($m['password_encoding'] === 'chilli') {
            $names[] = 'challenge';
        }
        foreach (array_unique($names) as $name) {
            $value = self::query($request, $name);
            if ($value !== null && preg_match(GenericSettings::PLACEHOLDER_VALUE, $value) === 1) {
                $params[$name] = $value;
            }
        }

        return $params;
    }

    /**
     * The login URL: from the mapped parameter, else the fixed URL (placeholders filled). HTTPS only (HTTP only
     * when the site allows it), no credentials, host one of the site's hotspot hosts.
     */
    private function loginUrl(Request $request, Site $site, array $m, array $params): ?string
    {
        $raw = $m['param_login_url'] !== '' ? self::query($request, $m['param_login_url']) : null;
        if ($raw === null && $m['login_url'] !== '') {
            $raw = $this->fill($m['login_url'], $params, false);
        }
        $url = self::httpUrl($raw);
        if ($url === null) {
            return null;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $hosts = array_map('strtolower', (array) $site->setting('hotspot_hosts', []));
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (($scheme !== 'https' && ! ($scheme === 'http' && $m['allow_http']))
            || ! in_array($host, $hosts, true)) {
            Log::warning('Hotspot login URL rejected (scheme or not a configured hotspot host)', ['site' => $site->slug, 'url' => $url]);

            return null;
        }

        return $url;
    }

    /** Replace {name} placeholders; null when one is missing. {handoff_secret} only in fields. */
    private function fill(string $template, array $params, bool $field): ?string
    {
        $missing = false;
        $out = preg_replace_callback('/\{([A-Za-z0-9_.-]{1,40})\}/', function (array $match) use ($params, $field, &$missing) {
            if ($match[1] === GenericSettings::SECRET) {
                $secret = $field && $this->site ? GenericSettings::secret($this->site) : null;
                $missing = $missing || $secret === null;

                return (string) $secret;
            }
            $missing = $missing || ! isset($params[$match[1]]);

            return $params[$match[1]] ?? '';
        }, $template);

        return $missing ? null : $out;
    }

    /**
     * CoovaChilli UAM PAP (redir.c, observed on CoovaChilli 1.9): the password zero-padded to a multiple of 16
     * bytes, XOR key per 16-byte block, hex encoded; key = MD5(challenge bytes + uamsecret), or the raw
     * challenge bytes without uamsecret. Plain password when the challenge is missing (the login then fails;
     * logged).
     */
    private function chilliPassword(string $password, ?string $challenge): string
    {
        if ($challenge === null || preg_match('/^[0-9A-Fa-f]{32}\z/', $challenge) !== 1) {
            Log::warning('CoovaChilli handoff without a valid challenge: password sent unencoded', ['site' => $this->site?->slug]);

            return $password;
        }
        $secret = $this->site ? GenericSettings::secret($this->site) : null;
        $key = $secret !== null && $secret !== '' ? md5(hex2bin($challenge).$secret, true) : hex2bin($challenge);
        $out = '';
        $password = str_pad($password, (int) (ceil(max(1, strlen($password)) / 16) * 16), "\0");
        foreach (str_split($password) as $i => $char) {
            $out .= chr(ord($char) ^ ord($key[$i % 16]));
        }

        return bin2hex($out);
    }

    /**
     * Upload and download rate in bit/s from a RouterOS rate limit "rx/tx" (k, M, G suffixes; rx is the
     * client's upload). Only the first pair counts (burst values are ignored); null when not parseable.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function rates(?string $limit): ?array
    {
        if ($limit === null || preg_match('#^\s*(\d+(?:\.\d+)?)([kKmMgG]?)\s*/\s*(\d+(?:\.\d+)?)([kKmMgG]?)#', $limit, $m) !== 1) {
            return null;
        }
        $bits = fn (string $n, string $unit) => (int) round((float) $n * match (strtolower($unit)) {
            'k' => 1_000, 'm' => 1_000_000, 'g' => 1_000_000_000, default => 1,
        });
        $up = $bits($m[1], $m[2]);
        $down = $bits($m[3], $m[4]);

        return $up > 0 && $down > 0 && $up <= 4_294_967_295 && $down <= 4_294_967_295 ? [$up, $down] : null;
    }

    /** What the preset's vendor calls the walled garden (plain English, shown in the checklist). */
    private static function walledGardenName(string $preset): string
    {
        return match ($preset) {
            Presets::CISCO_WLC => 'Cisco: pre-authentication ACL / URL filter of the web-auth WLAN',
            Presets::ARUBA => 'Aruba: walled garden / whitelist of the external captive portal profile',
            Presets::PFSENSE => 'pfSense: Allowed IP Addresses and Allowed Hostnames of the zone',
            Presets::OPNSENSE => 'OPNsense: Allowed addresses of the zone (IPs or networks, no host names)',
            Presets::COOVACHILLI => 'CoovaChilli: uamallowed / uamdomain',
            Presets::RUCKUS => 'Ruckus: Walled Garden of the hotspot (WISPr) service',
            default => 'what the vendor calls the pre-login allow list',
        };
    }

    private static function query(Request $request, string $name): ?string
    {
        $value = $name !== '' ? $request->query($name) : null;

        return is_string($value) && $value !== '' && strlen($value) <= 2048 ? $value : null;
    }

    private static function portalUrl(Site $site): string
    {
        return (string) ($site->setting('portal_url') ?: config('app.url'));
    }

    /** http(s) URL without embedded credentials, else null. */
    private static function httpUrl(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 2048 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }
        $parts = parse_url($value);

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! isset($parts['user']) && ! isset($parts['pass']) ? $value : null;
    }

    /** Host names, IPs, names and secrets of the checklist: no spaces, quotes or control characters. */
    private static function safe(?string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9.*:_@\/+=-]/', '', (string) $value);
    }

    private static function safeUrl(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9:\/._\-?&=%~{}\[\]]/', '', $value);
    }
}
