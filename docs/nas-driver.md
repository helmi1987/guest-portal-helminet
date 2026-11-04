# Writing a NAS driver

The RADIUS core of the portal (grants, one-time credentials, `Session-Timeout`, accounting, Disconnect-Request per
RFC 5176) is vendor-neutral. Everything that depends on the router or access controller (the NAS) sits behind the
interface `App\Nas\NasDriver`; each site stores the key of its driver in `sites.driver`. The MikroTik RouterOS 7
driver is the reference; since v0.27.0 an experimental Ubiquiti UniFi driver authorizes through a controller API
instead of RADIUS (§3). This guide describes what a driver for another captive portal has to implement. Design
and decisions: [CONCEPT.md](../CONCEPT.md) §5 (RADIUS), §5.1 (NAS drivers), §9 (setup assistant).

**Quickest way to support a new RADIUS vendor:** since v0.28.0 the experimental generic RADIUS captive-portal driver
(`generic_radius`, [generic-captive-portal.md](generic-captive-portal.md)) covers NAS that redirect guests to an
external page and check the posted credentials with RADIUS PAP. Its parameter names, login URL and form fields are
set per site, with presets for several vendors. Try it (and the NAS simulator in `tests/nas-sim`) before writing a
driver; a dedicated driver is worth it when the vendor needs scripts, a state query or a controller API.

## 1. What a driver does and what the core does

| Responsibility | Driver method | Core side | MikroTik RouterOS 7 driver |
|---|---|---|---|
| Name in the site form | `label()` | Site form, `NasDrivers::options()` | "MikroTik RouterOS 7" |
| Read the captive-portal redirect | `parseRedirect()` | Stores the result in the guest session, checks `isComplete()` | `link-login-only`, `mac`, `ip`, `link-orig` of the `login.html` redirect |
| Hand the one-time credentials to the NAS | `loginHandoff()` | Issues the credentials, renders `guest/handoff.blade.php` (browser POST) | Form fields `username`, `password`, `dst`, `popup` to `link-login-only` |
| Vendor attributes in the Access-Accept | `replyAttributes()` | Adds `Session-Timeout` and `Acct-Interim-Interval` | `Mikrotik-Rate-Limit` when the grant has a rate limit |
| Identify a session for Disconnect-Request | `disconnectAttributes()` | Sends it with `radclient` to `nas_ip:coa_port` | `Framed-IP-Address` only, not for stopped or stale sessions |
| Page the NAS shows unauthenticated clients | `loginPage()` | Download in the site page, route `/router-login/{nas}/login.html` | `login.html` with a meta refresh to `/portal?site=...` |
| Command that installs that page on the NAS | `loginPageFetch()` | Shown per router in the setup assistant | `/tool fetch` with `X-Portal-Token` |
| Router configuration of one router | `configScript()` | "Router configuration" page of a router | RouterOS script (RADIUS, walled garden, hotspot, certificate script) |
| Setup assistant inputs (validation rules) | `setupFields()` | Validates the assistant forms | VLAN, bridge, subnet, pool, SSID, Wi-Fi modes, container inputs |
| Setup assistant command blocks | `setupCommands()` | Rendered with copy buttons, placeholders and line colours | RouterOS blocks per step |

The core also provides, independent of the driver: the FreeRADIUS clients file (one client `nas-<id>` per router with
its NAS IP and secret), the internal RADIUS API, the guest pages and status page, MAC whitelist and roaming, the router
certificate download (`/router-cert/{nas}/...`), the captive portal API for DHCP option 114 (`/captive/{slug}`) and the
federation.

### Lifecycle of one guest login

1. The NAS intercepts the guest's HTTP request and serves the page from `loginPage()`, which redirects the browser to
   `/portal?site=<slug>&...` with the NAS's own parameters.
2. [`PortalController::show()`](../app/Http/Controllers/Guest/PortalController.php#L57) resolves the site from `site`
   and calls `parseRedirect()`. The returned `HotspotContext` is kept in the session; without a MAC or login URL
   (`isComplete()`) the guest gets the "no hotspot" error page.
3. The guest logs in (code, SMS, approval, social login). The core issues one-time RADIUS credentials (random user name
   and password, valid 60 s, `portal.radius.token_ttl`) bound to the guest's MAC.
4. [`GuestLogin::handoff()`](../app/Http/Controllers/Guest/Concerns/GuestLogin.php#L48) calls `loginHandoff()` with the
   credentials and a signed status page URL; the browser POSTs the returned fields to the returned URL
   ([`handoff.blade.php`](../resources/views/guest/handoff.blade.php#L5), auto-submit with a button as fallback).
5. The NAS sends an Access-Request (PAP) to FreeRADIUS, which forwards it to `/internal/radius/authorize`. The core
   checks token, MAC (`Calling-Station-Id`), site and grant, binds the device and answers with `Session-Timeout`,
   `Acct-Interim-Interval` and `replyAttributes()`
   ([`RadiusController.php:78`](../app/Http/Controllers/Internal/RadiusController.php#L78)).
6. The NAS lets the guest out and sends the browser to the status page; accounting (Start, Interim, Stop) goes to
   `/internal/radius/accounting`.
7. Revoke, shorten or rebind: the queued job `DisconnectSession` asks `disconnectAttributes()` per accounting session
   and sends a Disconnect-Request ([`Disconnector.php:22`](../app/Radius/Disconnector.php#L22)). `Session-Timeout`
   stays the upper bound when that fails.

## 2. Writing the driver

### 2.1 Create and register the class

Create a class in `app/Nas/` implementing [`NasDriver`](../app/Nas/NasDriver.php#L14) and add it to
[`NasDrivers::DRIVERS`](../app/Nas/NasDrivers.php#L10). The key is stored in `sites.driver` (`string(32)`), so keep it
short, lower case and stable; never rename it once sites use it. Drivers are resolved through the container
(`app($class)`), so constructor injection works.

```php
// app/Nas/NasDrivers.php
public const DRIVERS = [
    'mikrotik_ros7' => MikrotikRos7Driver::class,
    'example_nas' => ExampleNasDriver::class,
];
```

The site form lists `NasDrivers::options()` (key => `label()`,
[`form.blade.php:22`](../resources/views/admin/sites/form.blade.php#L22)) and the controller only accepts registered
keys ([`SiteController.php:231`](../app/Http/Controllers/Admin/SiteController.php#L231)). `Site::driver()` throws for an
unknown key, so a site must never point at a driver that was removed.

### 2.2 `label(): string`

Display name in the site form. Plain English product name, no translation key (it is shown as is).

### 2.3 `parseRedirect(Request $request, Site $site): HotspotContext`

- **When:** on every `GET /portal` that carries `site=` (the redirect from the NAS).
- **Input:** the query string as the NAS sent it; `$site` is the site named by `site`.
- **Returns:** [`HotspotContext`](../app/Nas/HotspotContext.php#L9) with `mac`, `ip`, `loginUrl`, `origUrl`. `mac` and
  `loginUrl` are required (`isComplete()`); `ip` and `origUrl` are optional (the core does not use `origUrl`).
- **Pitfalls:** all parameters are untrusted. The MAC is a UX hint only, the binding MAC is the one RADIUS reports
  (`Calling-Station-Id`). The login URL receives the one-time credentials, so accept it only when it uses HTTPS and its
  host is one of the site's hotspot hosts (`$site->setting('hotspot_hosts')`); otherwise return `null` and log why.
  Normalise the MAC with `App\Support\Mac::normalize()`.
- **MikroTik:** [`MikrotikRos7Driver.php:213`](../app/Nas/MikrotikRos7Driver.php#L213) reads `link-login-only`, `mac`,
  `ip`, `link-orig` and validates URLs with `httpUrl()` (http/https only, no embedded credentials).

Redirect signals (since v0.28.0, optional interface [`ReadsRedirectSignals`](../app/Nas/ReadsRedirectSignals.php)):
`redirectSignals(Request $request, Site $site)` returns `has_login_url` (the NAS is configured to send a login URL;
when `parseRedirect()` then dropped it, the core shows the "misconfigured hotspot" page instead of "no hotspot") and
`prefill_code` (a 6-digit code in the redirect's original URL, `RedirectSignals::codeFromUrl()`) and optionally
`login_result` (`success` or `failed`: the NAS sent the guest back after its own login; `success` opens the status
page of the browser's last handoff at the site, `failed` shows the "login not completed" notice). The core no longer
reads vendor parameter names ([`PortalController::show()`](../app/Http/Controllers/Guest/PortalController.php)).
MikroTik: `link-login-only` / `link-orig`; UniFi: no login URL, code from `url`; generic: the mapped names. A driver
without the interface gives no signals (guests see "no hotspot", no prefill from the original URL).

### 2.4 `loginHandoff(HotspotContext $context, string $username, string $password, string $successUrl): array`

- **When:** right after a successful portal login (every login method).
- **Input:** the context from `parseRedirect()`, the one-time credentials, and `$successUrl`, the signed status page
  (valid until the grant ends plus one hour, no cookie needed).
- **Returns:** `['url' => <form action>, 'fields' => [name => value]]`, optionally `'method' => 'GET'`. The view renders
  a POST form with hidden fields and submits it with inline JavaScript, or for GET navigates to the URL with the
  fields as query string (since v0.28.0); it cannot send headers or call an API. The guest CSP allows any `https:`
  form action; a plain-HTTP login URL only works with GET (navigation).
- **Pitfalls:** the NAS must authenticate these credentials with PAP against RADIUS and should send the browser to
  `$successUrl` afterwards. The status page waits until RADIUS accepted the token; if it never was, the next portal
  visit shows the "router login not completed" notice.
- **MikroTik:** [`MikrotikRos7Driver.php:233`](../app/Nas/MikrotikRos7Driver.php#L233) posts `username`, `password`,
  `dst` (= `$successUrl`) and `popup=true` to `link-login-only`.

### 2.5 `replyAttributes(Grant $grant): array`

- **When:** in every Access-Accept for a grant: portal login and roaming device
  ([`RadiusController.php:81`](../app/Http/Controllers/Internal/RadiusController.php#L81) and
  [`:123`](../app/Http/Controllers/Internal/RadiusController.php#L123)); not for MAC whitelist entries.
- **Returns:** rlm_rest JSON keys, e.g. `['reply:Vendor-Attribute' => 'value']`; `[]` when nothing applies.
- **Pitfalls:** `Session-Timeout` and `Acct-Interim-Interval` are set by the core; do not return them. The attribute
  must exist in the FreeRADIUS dictionaries of the image (the Alpine `freeradius` package; `docker/freeradius` adds
  none).
- **MikroTik:** [`MikrotikRos7Driver.php:248`](../app/Nas/MikrotikRos7Driver.php#L248) returns
  `reply:Mikrotik-Rate-Limit` when `$grant->rate_limit` is set.
- **Per-site settings:** `replyAttributes()`, `loginHandoff()` and `disconnectAttributes()` get no site. A driver that
  needs one implements the optional interface [`SiteBoundDriver`](../app/Nas/SiteBoundDriver.php) (`forSite(Site): static`);
  `Site::driver()` then returns a copy bound to the site (since v0.28.0, used by the generic driver).

### 2.6 `disconnectAttributes(RadiusAccounting $acct): ?array`

- **When:** for every accounting session of a session summary that must end (revoke, shorten, rebind, whitelist
  change).
- **Returns:** radclient attribute name => value for the Disconnect-Request (RFC 5176), or `null` when the session
  cannot be addressed safely (log a warning with the reason). `null` or `[]` means nothing is sent and the job fails
  and retries; `Session-Timeout` remains the upper bound.
- **What it may return:** only identifying attributes the NAS accepts for this session, typically one or more of
  `User-Name`, `Acct-Session-Id`, `Framed-IP-Address`, `Calling-Station-Id`. Available data: `$acct->acct_session_id`,
  `$acct->username`, the session summary (`$acct->summary`: `mac`, `ip`, `stopped_at`), `$acct->last_update_at`.
  The core adds nothing; the secret goes via a file (`-S`) and the request goes to `nas_ip:coa_port` of the router
  ([`Disconnector.php:42`](../app/Radius/Disconnector.php#L42)). An ACK is recognised by `Disconnect-ACK` in the output.
- **Pitfalls:** routers differ in what they accept. Prefer an attribute that cannot hit another guest; if you must use
  one that can be reused (an IP), guard it as the MikroTik driver does.
- **MikroTik:** [`MikrotikRos7Driver.php:264`](../app/Nas/MikrotikRos7Driver.php#L264). RouterOS 7 rejects `User-Name`
  and `Acct-Session-Id` (NAK, Error-Cause 406), so it sends `Framed-IP-Address` alone, and only while the accounting
  session is open and updated within `portal.radius.stale_after` (the IP can be reused after a DHCP lease change).

### 2.7 `loginPage(Site $site): string` and `loginPageFetch(Site $site, NasClient $nas): string`

- `loginPage()` returns the HTML the NAS shows unauthenticated clients. It must send the browser to
  `<portal URL>/portal?site=<slug>` plus the NAS's parameters, without JavaScript (the MikroTik page uses a meta
  refresh and a link). Portal URL: site setting `portal_url`, else `config('app.url')`. Served as a download on the site
  page ([`SiteController.php:220`](../app/Http/Controllers/Admin/SiteController.php#L220)) and to the router at
  `/router-login/{nas}/login.html` ([`RouterLoginPageController.php:22`](../app/Http/Controllers/RouterLoginPageController.php#L22)).
- `loginPageFetch()` returns the command that makes the NAS download that page itself. The route requires the header
  `X-Portal-Token: <$nas->certToken()>` and, by default, the router's NAS IP as source address. A NAS that cannot
  fetch files should return a short instruction to upload the downloaded file instead.
- **MikroTik:** [`MikrotikRos7Driver.php:287`](../app/Nas/MikrotikRos7Driver.php#L287) (RouterOS `$(mac-esc)` style
  variables in the URL) and [`:822`](../app/Nas/MikrotikRos7Driver.php#L822) (`/tool fetch` into the profile's
  html-directory).

### 2.8 `configScript(Site $site, NasClient $nas): string`

Configuration for one router, shown on its "Router configuration" page
([`SiteController.php:203`](../app/Http/Controllers/Admin/SiteController.php#L203)). It must cover at least: RADIUS
client (address `radius_address` of the site, secret `$nas->radius_secret`, source `$nas->nas_ip`), accepting
Disconnect-Requests on `$nas->coa_port`, the walled garden (2.10), the captive portal itself with RADIUS
authentication and accounting, and how `loginPage()` gets onto the NAS.

Rules, as the MikroTik driver follows them ([`MikrotikRos7Driver.php:311`](../app/Nas/MikrotikRos7Driver.php#L311)):

- **Sanitize every value** that comes from settings or the database before it goes into a script; values may arrive by
  replication without form validation. The MikroTik driver strips characters per context: `safe()`, `url()`, `token()`,
  `quoted()` ([`MikrotikRos7Driver.php:1457`](../app/Nas/MikrotikRos7Driver.php#L1457) ff.).
- **Placeholders** such as `<INSTANCE_IP>` or `<HOTSPOT_DNS_NAME>` for missing values; never invent defaults that
  would silently work wrongly.
- The router script contains the RADIUS secret and the download token; say so in its header comment.
- **Only own objects:** re-running must replace only what the script created and never change or remove objects of
  others.

The comment keys `guest-portal:<block>:<object>` (`MikrotikRos7Driver::key()`,
[`:58`](../app/Nas/MikrotikRos7Driver.php#L58)) and the list of generated objects `managedObjects()`
([`:71`](../app/Nas/MikrotikRos7Driver.php#L71)) are **MikroTik specific and optional**: they exist so the RouterOS
scripts can find their own objects and so the router state query (2.12) can compare them. A new driver needs them only
if it implements that comparison; any other scheme that identifies its own objects exactly (never by prefix) is fine.

### 2.9 `setupFields(): array` and `setupCommands(Site $site, array $container, bool $withSecrets = true): array`

`setupFields()` returns Laravel validation rules for the setup assistant: `network` (stored in the site setting
`setup`) and `container` (instance-local). The forms themselves are not generated from these rules: the views use
fixed field names (`vlan_id`, `bridge`, `interface`, `subnet`, `gateway`, `pool`, `ssid`, `wifi_modes[]`,
`wifi_password`, `html_directory`, `veth_address`, `veth_gateway`, `disk`, `image`, ...) and
[`SetupController.php:250`](../app/Http/Controllers/Admin/SetupController.php#L250) checks that `gateway` and `pool`
lie inside `subnet`. A driver must therefore provide rules for these names; additional fields are validated but never
shown.

`setupCommands()` returns ready-to-copy blocks by step and block id. The views read these ids without fallback, so a
driver must return all of them (an empty string or a short note where nothing applies):

| Step | Block ids |
|---|---|
| `container` | `device_mode`, `container_network`, `container_add` |
| `network` | `vlan`, `vlan_filtering`, `wifi`, `dhcp`, `captive_option`, `firewall` |
| `hotspot` | `dns`, `hotspot` |
| `diagnostics` | `verify`, `diagnostics` |
| `uninstall` | `uninstall`, `uninstall_container`, `uninstall_network` |

With `$withSecrets = false` (what the assistant page renders) stored passwords must be replaced by placeholders such
as `<WIFI_PASSWORD>`; the script semantics stay the same. Sanitize as in 2.8. MikroTik:
[`MikrotikRos7Driver.php:441`](../app/Nas/MikrotikRos7Driver.php#L441) and
[`:476`](../app/Nas/MikrotikRos7Driver.php#L476).

### 2.10 Walled garden

Before login a guest must reach:

| Host | Why | Source |
|---|---|---|
| Portal host (HTTPS) | The portal pages | Host of the site's portal URL; `portal_address` (else `radius_address`) for an IP rule |
| Social login hosts | Sign-in pages of the providers offered to guests, incl. their CDNs | [`WalledGarden::hosts()`](../app/Auth/Social/WalledGarden.php#L33) |

`WalledGarden::hosts()` is empty while social login is off and lists only switched-on, configured providers; wildcard
entries such as `*.gstatic.com` are included, so the NAS must support wildcard host rules or the driver must document
what to do instead. The generated configuration should remove the entries of providers switched off later
(`WalledGarden::allHosts()`). Do not add captive-check hosts of the operating systems (CONCEPT.md §9). MikroTik:
[`MikrotikRos7Driver.php:382`](../app/Nas/MikrotikRos7Driver.php#L382).

### 2.11 What the RADIUS core expects from the NAS

| Item | Expectation (code) |
|---|---|
| Identification | Source IP and secret of the FreeRADIUS client `nas-<id>`; `NAS-Identifier` must match when one is set on the router |
| Authentication | PAP with the one-time user name/password; `Calling-Station-Id` = the client MAC (any common notation) |
| Message-Authenticator | Added by FreeRADIUS to every reply (`docker/freeradius/sites/default`) |
| Session limit | The NAS enforces `Session-Timeout` (wall-clock seconds left of the grant) |
| Accounting | Start, Interim-Update, Stop (`Acct-Status-Type` as name or 1/2/3) with a per-NAS unique `Acct-Session-Id`, `User-Name` as authenticated, `Calling-Station-Id`, `Framed-IP-Address` in the Start, `Acct-Input/Output-Octets` (+ `-Gigawords`), `Acct-Terminate-Cause` in the Stop |
| Interim updates | At `Acct-Interim-Interval`; sessions without updates for `portal.radius.stale_after` count as stale |
| MAC authentication | Optional. An Access-Request whose `User-Name` is a MAC equal to `Calling-Station-Id` is treated as MAC authentication (MAC whitelist, roaming between sites). Without it those features do not work on that NAS |

Accounting whose `User-Name` is neither a used one-time token nor a MAC equal to `Calling-Station-Id` is ignored, so
sessions not started through the portal or MAC authentication never appear.

### 2.12 Optional interfaces of the MikroTik driver

`App\Nas\RouterOs\RouterStateDriver` (read-only router state query, comparison by comment key, line colours,
prefill) and `App\Nas\RouterOs\ProvisioningEditor` (CAPsMAN rules) are separate interfaces. The core checks them with
`instanceof` and works without them: the state query reports "the NAS driver does not support a state query"
([`RouterStateQuery.php:25`](../app/Nas/RouterOs/RouterStateQuery.php#L25)) and the assistant shows the commands
without colours or suggestions. They talk to RouterOS (REST, API-SSL); a new driver should not implement them.

## 3. Portals that authorize through a controller API (`AuthorizesViaController`)

The interface of §2 assumes that the NAS itself runs a RADIUS-backed login form: the handoff is a browser POST of
one-time RADIUS credentials to the NAS (`loginHandoff()`), the device is bound when RADIUS accepts them, sessions
are known from RADIUS accounting, and disconnects are RADIUS Disconnect-Requests.

Some captive portals work differently: the controller redirects the guest to an external portal and the portal
then lets the client out through the controller's API. Since v0.27.0 (decision 2026-11-04, CONCEPT.md §5.1) the
core supports this through the **optional interface** [`App\Nas\AuthorizesViaController`](../app/Nas/AuthorizesViaController.php),
implemented by the **experimental** Ubiquiti UniFi driver ([`UnifiDriver`](../app/Nas/UnifiDriver.php), setup and
limits: [unifi-setup.md](unifi-setup.md); background: [unifi-feasibility.md](unifi-feasibility.md)).

```php
interface AuthorizesViaController
{
    /** Validation rules of the per-site controller inputs (site setting "controller"). */
    public function controllerFields(): array;

    /** Let the client of $context out for $seconds; checks it first (connected guest, AP). Never throws. */
    public function authorizeClient(Site $site, HotspotContext $context, Grant $grant, int $seconds): ControllerAuthorization;

    /** End access (revoke, shorten, rebind); true also when the client is not connected. */
    public function deauthorizeClient(Site $site, string $mac): bool;

    /** connected, authorized, ip, bytes_in, bytes_out, expires_at; null when the controller cannot be asked. */
    public function clientStatus(Site $site, string $mac): ?array;

    /** Check button of the site page: array{status, message}. */
    public function checkController(Site $site): array;
}
```

What the core does differently for a site whose driver implements it (`ControllerSessions::supports()`):

| Step | RADIUS driver | Controller driver |
|---|---|---|
| Redirect | `parseRedirect()` from `/portal?site=...` | A vendor route forwards to `/portal?site=...` (UniFi: `/guest/s/{unifiSite}`); `HotspotContext` carries the vendor fields `ap`, `ssid`, `unifiSite`, `timestamp`; `isComplete()` accepts a context without login URL when `unifiSite` is set |
| Handoff | `loginHandoff()` → browser POST to the NAS | [`GuestLogin::handoff()`](../app/Http/Controllers/Guest/Concerns/GuestLogin.php) calls `ControllerSessions::authorize()`: `authorizeClient()` outside the database transaction, then device binding, the one-time token marked used and a session summary with one local accounting row (`nas_client_id` null, `acct_session_id` `controller:<token id>`); redirect to the status page, error page `controller_failed` otherwise |
| Status page | waits for the RADIUS accept | shows `clientStatus()` on each load (no JavaScript) and the original URL as a link |
| Usage | RADIUS accounting | `portal:poll-controllers` (every two minutes) writes `clientStatus()` into the accounting row |
| Revoke, rebind, expiry | `DisconnectSession` → Disconnect-Request | `DisconnectSession` → `deauthorizeClient()`, then the summary is closed; `portal:enforce-grants` never closes such sessions as stale while the grant runs |
| Extend / shorten | Session-Timeout stays the upper bound | job `ReauthorizeControllerSession` → `authorizeClient()` with the new remaining time |
| MAC whitelist, roaming | RADIUS MAC authentication | not supported |
| Setup assistant | `setupCommands()` blocks | the driver returns notes for every block id; the setup page shows the driver's checklist, the wizard redirects there |

The `NasDriver` methods still have to exist: return no-ops with a short explanation (`replyAttributes()` `[]`,
`disconnectAttributes()` `null`, `loginHandoff()` the success URL, `loginPage()`/`loginPageFetch()` a note,
`configScript()` the checklist). Controller credentials belong into a replicated security site setting with the
secrets encrypted by the federation data key and re-encrypted on rotation (`FederatedSecrets`), like
[`UnifiSettings`](../app/Nas/Unifi/UnifiSettings.php); they never appear in logs, the audit or exception
messages (`Redact::secrets`).

## 4. Testing and contributing

### 4.1 Unit tests

Test the driver methods directly with an in-memory site (`tests/Unit` or a feature test with `RefreshDatabase`):
redirect parsing with valid, missing and hostile parameters (wrong host, `http://`, embedded credentials, non-MAC),
the handoff fields, reply and disconnect attributes (including the `null` cases), and that generated scripts contain no
unsanitized input. [`SetupAssistantTest::test_values_are_validated_and_sanitized_against_script_injection`](../tests/Feature/SetupAssistantTest.php#L75)
shows the pattern: write hostile values directly into the site settings (bypassing validation), render all blocks and
assert that the script characters are gone.

### 4.2 Feature test through the guest flow

[`RadiusFlowTest`](../tests/Feature/RadiusFlowTest.php) runs the whole login against the internal RADIUS API; copy its
helpers and use your driver's redirect parameters:

```php
$site = Site::create(['slug' => 'site-a', 'name' => 'Site A', 'driver' => 'example_nas',
    'settings' => ['hotspot_hosts' => ['hotspot.example.com']]]);
$nas = $site->nasClients()->create(['name' => 'Router A', 'nas_ip' => '10.0.0.1', 'coa_port' => 3799]);
Process::fake(['*' => Process::result('Received Disconnect-ACK Id 1')]);

// 1. Redirect from the NAS (your driver's parameter names), then a code login;
//    $code from GrantManager::createCode() as in RadiusFlowTest::code()
$this->onZone('guest')->get('/portal?'.http_build_query(['site' => 'site-a', 'client_mac' => 'AA:BB:CC:00:00:01',
    'login_url' => 'https://hotspot.example.com/login']));
$fields = $this->onZone('guest')->post('/portal/code', ['first_name' => 'Test', 'last_name' => 'Guest',
    'terms' => '1', 'code' => $code, 'terms_version' => TermsVersion::current(app()->getLocale())?->id])
    ->assertOk()->assertSee('https://hotspot.example.com/login', false)->viewData('fields');

// 2. Access-Request as FreeRADIUS forwards it (rlm_rest JSON), see RadiusFlowTest::radius()
```

Then assert the reply attributes, an Accounting-Start/Stop, and that `DisconnectSession` runs `radclient` with your
attributes (`Process::assertRan(...)`, as in `test_disconnect_sends_only_framed_ip_address_and_records_attempt`).
[`SocialLoginTest`](../tests/Feature/SocialLoginTest.php) (`enter()`) shows the same entry for social login.

### 4.3 End to end

The MikroTik driver is verified against a real RouterOS CHR by the harness in [`tests/routeros`](../tests/routeros/README.md):
it renders every block (`render.php`), runs them on a seeded router, checks that only own objects change, re-runs
them and compares the configuration after the uninstall with a snapshot. It is not part of `php artisan test`. A new
driver should come with an equivalent check against a real or virtual NAS where one exists, or at least a documented
manual test (redirect, login, accounting, Disconnect-Request, uninstall).

### 4.4 Contribution checklist

- [ ] Driver class in `app/Nas/`, registered in `NasDrivers::DRIVERS` with a short stable key.
- [ ] Every method of `NasDriver` implemented; all setup assistant block ids returned.
- [ ] Untrusted input validated in `parseRedirect()`; credentials only to configured HTTPS hotspot hosts.
- [ ] All script values sanitized; placeholders for missing values and for secrets in the assistant view.
- [ ] Unit and feature tests; `php artisan test` and `vendor/bin/pint --test` pass.
- [ ] Everything in English; user-facing strings in `lang/{en,de,fr,it}` (all four).
- [ ] Generic example values only (`portal.example.com`, `10.0.0.0/24`, "site A"); no secrets in code, tests or docs.
- [ ] Docs updated (this guide, `docs/guest-network-setup.md` where the setup differs); decisions only in CONCEPT.md.
- [ ] Pull request against `main` as described in [CONTRIBUTING.md](../CONTRIBUTING.md).
