# UniFi feasibility study (2026-11-03)

Question: can Ubiquiti UniFi access points serve as the NAS of a portal site, and what would a UniFi NAS driver need?
This is a study, not a design decision and not code. The current interface and its limits are described in
[nas-driver.md](nas-driver.md) §3; decisions belong in [CONCEPT.md](../CONCEPT.md).

**How to read it.** Every statement about UniFi carries a source tag `[Sn]` (list in §0, all read on 2026-11-03).
Statements marked **(estimate)** are the author's own assessment. Statements marked **(unverified)** could not be
confirmed from a primary source and must be tested before anyone relies on them.

## 0. Sources

| Id | Source | Page date |
|---|---|---|
| S1 | Ubiquiti help center, "External Hotspot API for Authorization Clients", https://help.ui.com/hc/en-us/articles/31228198640023 (read through the help center JSON API, the HTML page answers 403 to scripts) | updated 2026-10-15 |
| S2 | Ubiquiti help center, "UniFi Hotspots and Captive Portals", https://help.ui.com/hc/en-us/articles/115000166827 | updated 2026-11-03 |
| S3 | UniFi Network API OpenAPI files, https://developer.ui.com/network/v10.6.106/openapi.json and https://developer.ui.com/network/v11.0.97/openapi.json (v11.0.97 is the version https://developer.ui.com/network redirects to) | versions 10.6.106, 11.0.97 |
| S4 | Ubiquiti help center, "Getting Started with the Official UniFi API", https://help.ui.com/hc/en-us/articles/30076656117655 | updated 2026-09-22 |
| S5 | Art of WiFi, legacy PHP client `UniFi-API-client`, https://github.com/Art-of-WiFi/UniFi-API-client (`src/Client.php` class version 1.1.101, README) | last commit 2024-11-15 |
| S6 | Art of WiFi, official-API PHP client, https://github.com/Art-of-WiFi/unifi-network-application-api-client (README, `src/UnifiConnector.php`) | last commit 2026-10-19 |
| S7 | Art of WiFi blog, "UniFi API Authentication: Local Admin vs. API Key vs. Site Manager", https://artofwifi.net/blog/unifi-api-authentication-local-admin-vs-api-key-vs-site-manager | 2026-04-12 |
| S8 | Ubiquiti community post "UniFi Network 10.6: Final Release for Legacy USG Gateways and the Standalone Network Application", https://community.ui.com/questions/54a8e1dd-4210-47a4-86a5-572e40854fd0 (only the title was readable) | 2026-09-18 per S9 |
| S9 | UniHosted, "UniFi OS Server: what it is, requirements and how to get it", https://www.unihosted.com/blog/unifi-os-server | checked 2026-09 |
| S10 | IronWiFi help, "UniFi Hotspot (Network 8+)", https://help.ironwifi.com/configuration-guides/ubiquiti/unifi-hotspot | updated 2026-10-24 |
| S11 | Spotipo, "How UniFi Captive Portal Works", https://www.spotipo.com/post/unifi-hotspot-captive-portal-how-to-part1 | 2026-08-12 |
| S12 | Community wiki, UniFi controller API, https://ubntwiki.com/products/software/unifi-controller/api | modified 2025-05-12 |
| S13 | Ubiquiti help center, "UniFi Roles Explained", https://help.ui.com/hc/en-us/articles/1500011491541 | updated 2026-11-03 |
| S14 | Ubiquiti help center, "MAC Address Restricting", https://help.ui.com/hc/en-us/articles/18565355579799 | updated 2026-09-18 |
| S15 | Ubiquiti help center, "Configuring a RADIUS Server in UniFi", https://help.ui.com/hc/en-us/articles/360015268353 | updated 2026-10-22 |
| S16 | linuxserver.io, `docker-unifi-network-application` README, https://github.com/linuxserver/docker-unifi-network-application (latest release `10.6.109-ls152`) | release 2026-10-23 |

S5, S6, S7 and S10 to S12 are community or vendor-partner sources, not Ubiquiti documentation.

## 1. UniFi guest portal modes and products

**Modes.** A hotspot is enabled per SSID ("Hotspot Portal > Captive Portal") or, with zone-based firewalling in Network
9.0 and later, for a whole network via the Hotspot zone [S2]. Authentication options of the built-in portal: Facebook,
password, payment (Stripe), vouchers, RADIUS ("advanced") and External Portal Server ("advanced") [S2].

**External portal server.** A guest starts as `GUEST` with `authorized: false`; any web request is redirected to the
external portal, which authenticates the guest and then authorizes the client through the Network API [S1]. Ubiquiti's
example redirect [S1]:

```
http://PATH_TO_EXTERNAL_PORTAL/guest/s/default/?ap=<AP MAC>&id=<client MAC>&t=<unix time>&url=<original URL>&ssid=<SSID>
```

- `ap` = AP MAC, `id` = client MAC, `url` = original target, `ssid` = SSID [S1]; `t` is a Unix timestamp (value in
  the example; not described by S1). `default` in the path is the UniFi site name [S1, S12].
- No signature or secret is part of the redirect [S1]; every parameter is untrusted.
- The External Portal field takes an **IPv4 address, not a host name**; the host name goes into a separate field
  ("Domain" in Network 10, "Redirect Hostname" in 8/9), with "Use Secure Portal" on by default in Network 10 [S10].
  Which scheme, host and port the AP finally redirects to when Secure Portal and a Domain are set: **(unverified)**.
- The redirect itself is done by the AP (HTTP redirector on port 80, DNS of pending clients via the AP), the
  controller must be reachable for the portal to work [S11].
- "What the controller expects back": nothing over HTTP. The portal calls the API; after a successful authorization
  the client's state becomes `authorized: true` [S1]. Where the browser goes afterwards is up to the portal.

**Products and versions.**

| Platform | Network API base | Auth | Notes |
|---|---|---|---|
| Standalone Network Application (Linux/Windows, port 8443) | `https://host:8443/api/...` | local admin, cookie `unifises` [S5] | 10.6 is its final release [S8, S9]; no API keys [S7] |
| UniFi OS Server (self-hosted successor, Podman on Linux) | `https://host:11443/proxy/network/...` [S7] | local admin or API key [S7] | no official Docker image [S9]; captive portal port 8843 → 8444 [S9] |
| UniFi OS consoles (UDM, UDR, Cloud Gateway, Cloud Key Gen2) | `https://console/proxy/network/...` [S5, S12] | local admin (cookie `TOKEN` + CSRF) or API key [S5, S7] | port 443 [S5]; a Cloud Gateway reports one site [S1] |

## 2. Controller API for authorization

### 2.1 Legacy API (cookie login, undocumented)

- Login: `POST /api/login` (standalone) or `POST /api/auth/login` (UniFi OS), JSON `username`/`password`; UniFi OS
  returns a `TOKEN` cookie whose JWT payload carries `csrfToken`, sent back as `x-csrf-token`; all Network paths on
  UniFi OS get the prefix `/proxy/network` [S5, S12].
- Authorize: `POST /api/s/{site}/cmd/stamgr` with `{"cmd": "authorize-guest", "mac", "minutes"}` and optional `up`,
  `down` (kbps), `bytes` (the client passes megabytes), `ap_mac` ("should result in faster authorization") [S5].
- Revoke: `{"cmd": "unauthorize-guest", "mac"}`; also `kick-sta`, `block-sta` [S5, S12].
- Reading: `GET /api/s/{site}/stat/sta[/mac]` (online clients), `stat/guest` (guests with valid access, `within`
  hours), `stat/session` (session history by time range) [S5, S12].
- Accounts: a **local** account, no UI.com cloud account, no 2FA [S5, S7]. Ubiquiti enforced MFA on cloud accounts in
  July 2024 [S7]. Many functions "are not officially supported by Ubiquiti" [S5]; the API has no version scheme of its own (estimate).
- Least privilege: a "Hotspot Operator" role exists [S13]; a community report that it does not suffice for
  `authorize-guest` could not be confirmed, a portal vendor requires Owner or Super Admin [S10], and Super Admin is "equivalent to Owners from a security
  perspective" [S13]. Whether Hotspot Operator suffices: **(unverified)**.

### 2.2 Official Network API (API key, documented)

- Base path on the console: `https://<console>/proxy/network/integration`, paths under `/v1/...`; alternatively
  through the cloud connector `https://api.ui.com/v1/connector/consoles/{consoleId}/proxy/network/integration` [S3].
  Header `X-API-KEY` [S6]. Keys are created under Network > Integrations [S1, S4]; per S6 they are "site-specific and
  tied to your user account" and inherit that account's permissions.
- Flow [S1, S3]: `GET /v1/sites` (site UUIDs) → `GET /v1/sites/{siteId}/clients?filter=macAddress.eq('…')` (client
  UUID; filterable: `macAddress`, `ipAddress`, `access.type`, `access.authorized`, `connectedAt`) →
  `POST /v1/sites/{siteId}/clients/{clientId}/actions`.
- Actions (discriminator `action`) [S3, identical in 10.6.106 and 11.0.97]:
  - `AUTHORIZE_GUEST_ACCESS`, optional `timeLimitMinutes` (1 to 1,000,000; default from site settings),
    `dataUsageLimitMBytes`, `rxRateLimitKbps`, `txRateLimitKbps`. "Client must be a guest." It cancels an existing
    authorization, creates a new one and resets the guest traffic counters. The response holds
    `grantedAuthorization` (and `revokedAuthorization` if one was replaced).
  - `UNAUTHORIZE_GUEST_ACCESS`: "Unauthorizes network access and disconnects a guest client."
- Client details carry `access.authorization` with `authorizationMethod` (`VOUCHER`, `API`, `OTHER`), `authorizedAt`,
  `expiresAt` and `usage` (`bytes`, `rxBytes`, `txBytes`, `durationSec`) [S3].
- The client list covers **connected** clients only [S3]; guests cannot be pre-authorized before they connect [S6].
- Versions: available since Network 9.1.105 (Early Access, March 2025) [S1]; the S6 client requires 10.1.84 and is
  verified against 10.6.106 and 11.0.89 [S6]. Only UniFi OS consoles and UniFi OS Server have API keys [S7].
- Rate limits: cloud connector 100 requests/min per console, 25 s timeout [S6]. A limit for direct local calls is not
  documented **(unverified)**; handle HTTP 429 anyway.
- TLS: consoles ship self-signed certificates; both clients default to or recommend turning verification off for
  them [S5, S6]. The cloud connector uses a public certificate [S6].

### 2.3 Which path in 2026

Use the **official Network API with an API key** (estimate). Reasons: documented and versioned [S3], no cookie/CSRF/2FA
handling [S7], the standalone application ends at 10.6 [S8, S9], and the guest authorization action exists [S1, S3].
Fallback: the legacy `cmd/stamgr` path with a local account only for a standalone 10.6 controller; it is undocumented
and needs a high-privilege account (2.1). Recommendation: build only the official path first.

## 3. Mapping onto the portal

| `NasDriver` / core feature | UniFi driver (estimate unless tagged) |
|---|---|
| `label()` | "Ubiquiti UniFi (external portal)" |
| `parseRedirect()` | Read `id` (MAC), `ap`, `ssid`, `url`, `t` and the UniFi site from the path [S1]. `loginUrl` stays null; `HotspotContext` needs extra fields (§4). |
| `loginHandoff()` | Not used: replaced by a server-side `authorizeClient()` before the browser is sent to the status page. |
| `replyAttributes()` | Not used (no RADIUS). Rate limit of the grant → `rxRateLimitKbps`/`txRateLimitKbps` [S3]. |
| `disconnectAttributes()` | Not used. Disconnect = `UNAUTHORIZE_GUEST_ACCESS` [S3] (legacy: `unauthorize-guest` [S5]). |
| `loginPage()` / `loginPageFetch()` | Nothing to install: UniFi redirects by itself [S1, S11]. Return a short note. |
| `configScript()` | A checklist of controller settings instead of a script (§4.5). |
| `setupFields()` / `setupCommands()` | Do not fit (fixed MikroTik field names and block ids, [nas-driver.md](nas-driver.md) §2.9). |

**Server-side authorization.** After a successful login the portal looks up the client by MAC, checks it, sends
`AUTHORIZE_GUEST_ACCESS` with `timeLimitMinutes` = remaining grant minutes (rounded up, at least 1) [S3], binds the
device (`GrantManager::bindDevice()`) and creates the session summary from the response (`authorizedAt`, `expiresAt`)
[S3]. The MAC binding then comes from the redirect, not from RADIUS `Calling-Station-Id`: the portal must verify it
against the controller (client connected, `access.type` = GUEST, uplink AP matches `ap`) (estimate). A guest could
still authorize a different connected device with their own code; the grant's device limit applies as before.

**Time limits.** The controller expires the authorization itself (`expiresAt`) [S3]. Extending or shortening a running
grant = authorize again with the new remaining minutes, which also resets UniFi's traffic counters [S3]; possible only
while the client is connected [S3, S6]. After expiry the guest is redirected again and the portal can re-authorize a
bound device with a usable grant without a new login (estimate; like roaming today).

**No RADIUS accounting.** Lost: Accounting-Start/Interim/Stop, `Acct-Session-Id`, terminate cause, octet counters per
RADIUS session, the "router login not completed" detection. Substitute: poll the controller (official
`GET /v1/sites/{siteId}/clients` with `access.authorization.usage` [S3]; legacy `stat/sta`, `stat/guest`,
`stat/session` [S5]) every few minutes and update session summaries (estimate). Usage then counts per authorization,
not per association, and resets on each re-authorization [S3].

**Disconnect.** `UNAUTHORIZE_GUEST_ACCESS` addresses the client by UUID and disconnects it [S3]: no IP reuse risk as
with RouterOS. Gap: an offline client cannot be looked up in the connected-clients list [S3], so a revoked grant stays
authorized on the controller until `expiresAt` or the next poll that sees the device (estimate). Whether
`GET /v1/sites/{siteId}/clients/{clientId}` works for offline clients, and whether legacy `unauthorize-guest` works for
offline MACs: **(unverified)**.

**Walled garden.** Pre-authorization allowances take a host name, IP or subnet [S10]. Host names are resolved and
frozen into an ipset (`guest_pre_allow`) [S11], so CDN-backed social login hosts may break; wildcard entries
(`WalledGarden::hosts()` contains `*.gstatic.com`) are not documented **(unverified)**. Needed entries: the portal host
(and its IP) plus `WalledGarden::hosts()` of the switched-on providers.

**Login page.** Not needed (UniFi redirects). The portal needs a route for the UniFi path `/guest/s/{unifiSite}/`
[S1] on the host/IP configured as External Portal, over plain HTTP at least (estimate, see the open scheme question in
§1), which then redirects to `/portal?site=<slug>&...` on HTTPS.

**Site resolution.** The redirect carries the UniFi site name, not the portal slug [S1]. Several portal sites on
different controllers can all be called `default`. Options (estimate): a distinct host name per portal site in the
UniFi "Domain" field, or a per-site mapping (controller + UniFi site + SSID) with an error page when ambiguous.

**Controller credentials per site.** Controller URL, UniFi site (UUID for the official API, short name for the legacy
API [S3, S5]), API key (or local user + password for the legacy path), and certificate pin. Stored encrypted like the
OIDC secret ([OidcSettings.php](../app/Auth/Oidc/OidcSettings.php)), never in logs or the audit. Least privilege: an
API key of a dedicated local admin limited to that site (S6: keys are site-specific); which role is the lowest that
can authorize guests: **(unverified)**.

**Certificates.** Do not disable verification (as S5/S6 suggest for self-signed consoles); store the controller's
certificate or its SHA-256 fingerprint per site and verify against it (estimate).

**MAC whitelist and roaming.** UniFi's "MAC Address Filter" is an allow/deny list for association to an SSID, not a
portal bypass [S14]; RADIUS MAC authentication exists for 802.1X SSIDs [S14, S15], not for the external portal mode
(unverified). Equivalent in the driver (estimate): when a whitelisted or roaming-bound MAC hits the redirect, the
portal authorizes it without a login (long `timeLimitMinutes`, max 1,000,000 [S3]); optionally the poller authorizes
connected, unauthorized guests whose MAC is whitelisted, without a browser. Roaming between portal sites works at the
portal level because the grant and device binding live in the portal, also between UniFi and MikroTik sites.

**Multi-site controllers.** One controller hosts many UniFi sites; `GET /v1/sites` lists them [S1, S3]. Several portal
sites can point at one controller with different UniFi sites; one key per controller is enough if the key's account
covers those sites (unverified, see S6 "site-specific").

## 4. Required changes in the portal (estimate)

### 4.1 Interface

Refined from the sketch in [nas-driver.md](nas-driver.md) §3:

```php
interface AuthorizesViaController
{
    /** Per-site controller inputs (URL, UniFi site, API key, certificate pin) as validation rules. */
    public function controllerFields(): array;

    /** Check the redirect's client against the controller (connected guest of this site, AP matches). */
    public function verifyClient(Site $site, HotspotContext $context): ControllerClient;

    /** Authorize for $minutes with optional rate limits; returns authorizedAt/expiresAt or throws. */
    public function authorizeClient(Site $site, ControllerClient $client, int $minutes, ?string $rateLimit): ControllerAuthorization;

    /** End access (revoke, shorten, rebind); false when the client is unknown/offline or the call failed. */
    public function deauthorizeClient(Site $site, string $mac): bool;

    /** Authorized guests with usage, for the session poller. */
    public function authorizedClients(Site $site): iterable;

    /** Connection test for the settings page (reachability, certificate, key, site). */
    public function testConnection(Site $site): array;
}
```

`HotspotContext` gets an optional `vendor` array (`ap`, `ssid`, `controller_site`, `t`); `isComplete()` must accept a
null `loginUrl` for such drivers.

### 4.2 Guest flow

- New route `GET /guest/s/{unifiSite}/` → site resolution (§3) → redirect to `/portal`.
- [`PortalController::show()`](../app/Http/Controllers/Guest/PortalController.php): the "misconfigured hotspot" check
  and the code prefill read `link-login-only`/`link-orig` ([nas-driver.md](nas-driver.md) §2.3); the UniFi `url`
  parameter would need its own prefill path.
- [`GuestLogin::handoff()`](../app/Http/Controllers/Guest/Concerns/GuestLogin.php): branch on
  `instanceof AuthorizesViaController`: no RADIUS token; verify, authorize, bind the device and create the session
  summary in one transaction-safe step (API call outside the DB transaction), then redirect to the signed status URL.
- `status()`: today it waits for `RadiusToken::used_at`; for controller sites it shows the status directly or an
  error when the authorization failed.

### 4.3 Grants and enforcement

- `GrantManager::saveAndEnforce()`/`disconnectOpenSessions()` and the `DisconnectSession` job: dispatch
  `deauthorizeClient()` instead of `Disconnector` for controller sites.
- Shorten/extend: re-authorize with the new remaining minutes (UniFi enforces the expiry itself).
- `portal:enforce-grants` ([EnforceGrants.php](../app/Console/Commands/EnforceGrants.php)) works on `RadiusAccounting`
  rows; it needs a controller branch plus a poller command (session summaries from `authorizedClients()`).
- Guest list and session summary: usage from the poller, no `Acct-Terminate-Cause`, IP from the client object.

### 4.4 Settings UI

Controller section in the site form (driver-dependent), encrypted key, certificate pin, "test connection" button,
lang strings in all four languages.

### 4.5 Setup assistant

No RouterOS scripts. Instead a UniFi page with a checklist and copy fields: guest network + isolation, SSID with
Hotspot/Captive Portal, External Portal IP and Domain, Secure Portal, pre-authorization entries (portal host +
`WalledGarden::hosts()`), local admin/API key creation [S1, S2, S10]. The MikroTik assumptions listed in
[nas-driver.md](nas-driver.md) (`link-login-only`, fixed assistant field names, MAC as RADIUS user name, block ids)
mean the assistant views need a driver switch rather than reusing `setupCommands()`.

## 5. Test setup

**Container (legacy API only).** `lscr.io/linuxserver/unifi-network-application` (pin a tag such as
`10.6.109-ls152`, never `latest`) with an external MongoDB container whose major version is pinned (3.6–8.0
supported, 8.0 since Network 9.0), user created by the documented `init-mongo.sh` [S16]. Ports: 8443 (UI/API), 8080
(device inform), 3478/udp (STUN), 10001/udp (discovery), optional 8843/8880 (guest portal HTTPS/HTTP redirect) [S16].
This is the standalone application: it exercises the legacy login, `cmd/stamgr` and `stat/*` calls, but has no API
keys [S7]. Whether `authorize-guest` accepts a MAC that never connected: **(unverified)**, to test there.

**UniFi OS Server (official API).** Installed on a Linux host with Podman; Ubiquiti ships no Docker image [S9]. API
keys, `/v1/sites` and the client list work without hardware (estimate); `AUTHORIZE_GUEST_ACCESS` needs the UUID of a
**connected** client [S3, S6], so it cannot be exercised without an AP.

**Cannot be tested without hardware:** the AP's redirect (parameters, host/scheme with Secure Portal), the walled
garden, the client actually getting through after authorization, timing until it takes effect, disconnect.

**Minimal hardware for an end-to-end test (estimate):** one current UniFi AP with PoE power, plus a controller: either
a UniFi OS console (e.g. a Cloud Gateway, which also brings the Hotspot zone [S2]) or UniFi OS Server on an existing
Linux host. One phone and one laptop as guests.

**Automated tests:** feature tests with `Http::fake()` and JSON fixtures shaped after the OpenAPI schemas [S3]:
redirect parsing (hostile values), site resolution, authorize/verify/deauthorize, poller, enforcement, TLS pin
mismatch, HTTP 401/404/429/5xx handling.

## 6. Effort and risks

**Work packages (estimate, person-days):**

| Package | Days |
|---|---|
| Interface, `HotspotContext`, route, site resolution, handoff/status branch | 3–4 |
| UniFi driver, official API client (TLS pin, errors, 429) | 3–4 |
| Poller, enforcement, disconnect, guest list | 2–3 |
| Settings UI, encryption, connection test, lang ×4 | 2 |
| Setup assistant UniFi page | 1–2 |
| Tests (unit/feature), container smoke test | 2–3 |
| Hardware end-to-end test and fixes | 1–2 |
| Docs (this guide, nas-driver.md, guest-network-setup.md, CONCEPT.md) | 1 |
| **Total** (official API only; legacy fallback +3–4) | **15–21** |

**Risks:**

- API drift: the official API is young (Early Access in 2025 [S1]); client actions were unchanged between 10.6.106 and
  11.0.97 [S3], but new versions add required fields elsewhere [S6]. Pin a tested minimum version.
- Legacy API: undocumented, may break with any release [S5]; standalone application frozen at 10.6 [S8].
- Privileges: the legacy path likely needs Super Admin (2.1); a leaked key or password gives control of the site.
- Self-signed certificates on every console [S5, S6]: pinning is mandatory, and a certificate renewal breaks the pin.
- Reachability: the portal must reach the controller (443/8443/11443 [S7]); remote UniFi OS gateways need firewall
  rules on WAN [S5]; the cloud connector adds a cloud dependency and 100 requests/min [S6].
- Rate limits for direct calls unknown; polling load grows with sites × clients.
- Login quirks (legacy only): local account, no 2FA, no cloud account [S5, S7]; cookie/CSRF handling [S5].
- Weaker device binding (MAC from an unsigned redirect, §3) and no exact session accounting.
- Pre-authorization by host name freezes IPs [S11]; social login may be unreliable.

**Recommendation (estimate): do it later.** It is feasible with the official Network API, but it changes the login and
enforcement core (CONCEPT.md §5, §7) and can only be verified with a real AP; start once the open questions below are
answered and test hardware is available, and build the official API path only.

## 7. Open questions for the maintainer

1. Which UniFi hardware (AP models, gateway/console) and which Network Application version would be used? Self-hosted
   (standalone 10.6 or UniFi OS Server) or a UniFi OS console?
2. Is the official API (Network ≥ 10.1 on UniFi OS) acceptable as the only supported path, i.e. no standalone 10.6
   controllers?
3. Can the portal reach the controller directly, or would the cloud connector (`api.ui.com`) be acceptable?
4. Is weaker accounting (polled usage, no RADIUS sessions) acceptable for the guest list?
5. Interim path: the built-in UniFi hotspot with RADIUS authentication [S2]. That would give: guests on UniFi
   hardware checked against the portal's FreeRADIUS, no driver code. It would **not** give our guest pages (terms,
   SMS, approval, social login, branding): guests type into UniFi's own portal page, so codes (or other credentials)
   would go through the controller, which contradicts the one-time token decision (CONCEPT.md §5). Whether UniFi sends
   RADIUS accounting and accepts Disconnect-Requests in hotspot RADIUS mode is **(unverified)**; Ubiquiti documents
   CoA only for WPA-Enterprise SSIDs [S15]. Acceptable as a stopgap or not?
6. Is a test AP and controller available for the end-to-end test (§5)?
