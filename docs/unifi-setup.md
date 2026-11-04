# Ubiquiti UniFi (experimental)

Since v0.27.0 a site can use the NAS driver **"Ubiquiti UniFi (experimental)"** (key `unifi`). The UniFi
access points redirect guests to the portal (UniFi "External Portal Server"); after the login the portal
lets the device out through the API of the UniFi Network controller. There is no RADIUS in this mode.

**Experimental.** The driver was written from Ubiquiti's documentation, the OpenAPI files of the Network API
and the community clients listed in [unifi-feasibility.md](unifi-feasibility.md) §0, without UniFi access
points. Please report what works and what does not with the "Test report" issue template (§8). Design and
decision: [CONCEPT.md](../CONCEPT.md) §5.1; interface: [nas-driver.md](nas-driver.md) §3.

## 1. What works and what is unverified

| Area | State |
|---|---|
| Redirect route `/guest/s/<UniFi site>/?ap=..&id=..&t=..&url=..&ssid=..` | Implemented after Ubiquiti's example; the exact URL an AP sends (scheme, host with "Secure Portal" and "Domain") is **unverified** |
| Official Network API (API key): site lookup, client lookup by MAC, `AUTHORIZE_GUEST_ACCESS` with `timeLimitMinutes`, `UNAUTHORIZE_GUEST_ACCESS`, usage | Implemented after the OpenAPI files 10.6.106 / 11.0.97; **verified** against UniFi OS Server 5.1.42 (Network 10.5.67): site list, info, client lookup, check button, authorize/unauthorize calls; a MAC that is not connected cannot be authorized there (§7) |
| Legacy API (local account): login, `stat/sta`, `stat/guest`, `cmd/stamgr` `authorize-guest` / `unauthorize-guest`, check button | **Verified** against a standalone Network Application 10.6.109 without access points (§7) |
| Legacy API on UniFi OS (`/api/auth/login`, `TOKEN` cookie, CSRF token, `/proxy/network` prefix) | **Unverified** |
| API keys on the standalone application | **Not available** (verified, §7): use the local account there |
| The guest's traffic actually being let through after the authorization, timing until it takes effect | **Unverified** (needs an AP) |
| Pre-authorization allowances with host names and wildcards (social login) | **Unverified**; wildcard entries are probably not supported |
| Which UniFi role is the lowest that may authorize guests | **Unverified** (Hotspot Operator may not be enough) |
| Direction of the legacy `tx_bytes` / `rx_bytes` counters | **Unverified** (taken as tx = download) |

## 2. How it works

1. The guest connects to the guest SSID; the AP redirects the first web request to
   `http(s)://<portal host>/guest/s/<UniFi site>/?ap=<AP MAC>&id=<client MAC>&t=<time>&url=<original URL>&ssid=<SSID>`.
2. The portal finds the portal site whose controller settings name that UniFi site (several sites with the
   same UniFi site name: the one whose portal URL host is the requested host) and continues at `/portal`.
   All parameters are untrusted; an unknown UniFi site shows the "not connected" page.
3. The guest logs in with any login method (code, SMS, approval, social login, user account).
4. The portal looks up the client on the controller by MAC: it must be **connected**, a **guest** and, when the
   redirect named an AP, on that AP. Then it authorizes the client for the grant's remaining time (minutes,
   rounded up, at least 1), binds the device to the grant and records a session. The browser goes to the status
   page, which shows the controller's state on each reload and the original URL as a link.
5. The scheduler asks the controller every two minutes for each open session (`portal:poll-controllers`):
   IP and usage of the current authorization.
6. Revoke, rebind and expiry end the authorization through the controller (`portal:enforce-grants`,
   job `DisconnectSession`); an admin's extension or shortening authorizes the device again with the new
   remaining time.

## 3. Controller settings, step by step

The setup page of a UniFi site (Sites → site → "Setup assistant") shows this list with the site's own values
(portal host, portal IP, UniFi site, social login hosts) instead of RouterOS commands.

### 3.1 UniFi OS consoles and UniFi OS Server (Network 9 and later)

1. **Guest network:** a network for guests (own VLAN, client device isolation) and an SSID on it with
   "Hotspot Portal" switched on (Network 9+: in the Hotspot zone or Settings → Hotspot).
2. **Hotspot → Authentication:** "External Portal Server".
   - External portal server: the **IPv4 address** of the portal (`custom_ip`; the controller refuses host
     names). The AP sends guests to that IP, so the portal's guest listener must be reachable at it.
   - Domain (Network 10) / Redirect Hostname (8/9), if offered: the portal host name, e.g. `portal.example.com`.
   - "Encrypted URL": off (its effect on the redirect parameters is unknown). "Success Landing Page": off.
   - "Use Secure Portal": on. The portal answers on its HTTPS guest port only (no plain HTTP listener) and needs a
     certificate that guests' devices trust for that host name; switch on "HTTPS Redirection Support" too. By the
     community convention the AP redirects to `http://<IP>/guest/s/<site>/...` (plain HTTP to the IP): the portal
     has no port-80 listener today, so that redirect would fail. **Known open point**, unverified without an AP:
     either the HTTPS options make the AP use `https://<host or IP>/`, or a plain-HTTP redirect to HTTPS has to
     be put in front of the portal.
   - The SSID: Application "Hotspot", Hotspot type "Captive Portal".
3. **Pre-authorization allowances:** the portal's IP (required: otherwise the default restricted subnets
   10.0.0.0/8, 172.16.0.0/12 and 192.168.0.0/16 block it) and host name, plus the hosts of the switched-on social login
   providers (listed on the setup page; see §5 for wildcards).
4. **API key:** Network → Settings → Control Plane → Integrations → create an API key. Create it as a dedicated
   local admin limited to this site; keys inherit the account's permissions.
5. **Portal:** site page → section "Controller (UniFi, experimental)": URL `https://<console>` (port 443; UniFi
   OS Server usually 11443), access "API key", the key, UniFi site (the short name from the redirect, usually
   `default`), and either "Verify the controller certificate" or a public key pin (§3.3). Save, then "Check
   controller".

### 3.2 Standalone Network Application (10.x, Linux/Windows/Docker)

Same steps 1–3 in its web UI (port 8443). The standalone application has **no API keys**: create a **local
account** (no UI.com account, no 2FA) with a role that may authorize guests (Hotspot Operator is unverified,
otherwise Site Admin), choose access "Local account" and enter user name and password. URL
`https://<host>:8443`. Version 10.6 is the last release of the standalone application.

### 3.3 Certificates

Consoles and the standalone application ship self-signed certificates. Prefer a **pin** of the controller's
public key over switching verification off:

```
openssl s_client -connect unifi.example.com:443 </dev/null 2>/dev/null | openssl x509 -pubkey -noout \
  | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```

Enter `sha256//<result>` as pin. With a pin the certificate chain and host name are not checked (only the key);
a certificate renewal with a new key breaks the pin. Without pin and with verification off the portal warns.

## 4. Least privilege

- API key: a dedicated local admin, limited to the site(s) the portal serves; never an owner account.
- Local account (legacy): no UI.com account, no 2FA (not supported by the login), the lowest role that may
  authorize guests. Report which role worked for you.
- The portal stores the key and the password encrypted with the federation data key, never shows, logs or
  audits them, and sends them only to the configured controller URL. The controller setting is a security
  setting in a federation (only the hub or the site's home instance may change it).

## 5. Social login hosts

Pre-authorization allowances take host names, IPs or subnets; UniFi resolves host names when the list is
applied, so content delivery networks with changing addresses may break the sign-in page. Entries such as
`*.gstatic.com` (from the portal's walled-garden list) are probably not accepted: add the concrete host names
you see blocked, or switch the social login off on UniFi sites. Do not add the operating systems' captive
portal check hosts.

## 6. Limits

- **No RADIUS accounting:** no Accounting-Start/Stop, no terminate cause, no "router login not completed"
  detection. Usage in the guest list comes from the controller poll and counts per authorization: UniFi resets
  its counters on every authorization (also on an extension by an admin).
- **No MAC whitelist and no roaming between sites without a new login** on UniFi sites (both rely on RADIUS
  MAC authentication). A device bound to a running grant logs in through the portal again.
- **API key mode: the client must be connected** when it is authorized (the official API lists connected
  clients only). The legacy API (local account) also authorizes a MAC that never connected (verified), so the
  portal pre-authorizes such a device by MAC; the AP check then does not apply.
- **Time limit per authorization:** the controller ends the authorization itself at the end of the grant
  (`timeLimitMinutes`). Extending or shortening a running grant authorizes the device again; a device that is
  offline at that moment keeps its old end until its next login.
- **Revoking an offline device:** the official API can only address connected clients, so a revoked device that
  is offline keeps its authorization until it expires on the controller or reconnects while the portal still
  retries. Legacy mode sends `unauthorize-guest` by MAC, which works for offline MACs too (verified: the guest
  record stays with `end` = `start`).
- **Device binding:** the MAC comes from the unsigned redirect; the portal checks it against the controller
  (connected guest, AP), but a guest can still authorize another connected guest device with their own code
  (the grant's single-device rule still applies).
- Rate limits of codes (`rate_limit`) are not passed to UniFi yet.

## 7. Verified against a controller

Smoke test of v0.27.0 (2026-11-04) against a **standalone UniFi Network Application 10.6.109** (Docker image
`lscr.io/linuxserver/unifi-network-application`, no access points, no clients), legacy mode:

| Call | Answer |
|---|---|
| `POST /api/login` `{username, password, remember: false}` | 200 `{"meta":{"rc":"ok"},"data":[]}`, cookie `unifises` only; no CSRF header or cookie, and POSTs work without one (the client sends a token only when the controller gives one) |
| Without cookie / unknown site | 401 `api.err.LoginRequired` / 401 `api.err.NoSiteContext` |
| `GET /api/self/sites` | `name` = short name (`default`), `desc` = display name (`Default`) |
| `GET /api/s/default/stat/sysinfo` | `version` `10.6.109` (check button: "version 10.6.109, site default, 0 connected clients") |
| `GET /api/s/default/stat/sta/<mac>` for a MAC not connected | 400 `api.err.UnknownStation` (read as "not connected") |
| `cmd/stamgr` `authorize-guest` `minutes: 2` for a MAC that never connected | 200, `data: [{mac, start, end (= start + 120), site_id, authorized_by: "api", _id}]`: **pre-authorization works**; `stat/guest` then lists it (`expired: false`, counters 0), `stat/sta` stays empty. Without `minutes` the site default (480) applies, so the driver always sends it. `up`/`down`/`bytes` would become `qos_rate_max_up/down`, `qos_usage_quota` (not used yet) |
| `cmd/stamgr` `unauthorize-guest` | 200 `data: []`; the guest record stays with `end` = `start` and `unauthorized_by: "api"`. The driver judges authorization by `end` in the future and `expired` false, never by the record's presence |
| `authorize-guest` with `"mac": "nonsense"` | rc ok: the controller does not validate MACs; the driver refuses anything that is not a MAC before a call |
| `/proxy/network/integration/v1/sites` / `/integration/v1/sites` | 404 / 403 `api.forbidden`: **no Integration API and no API keys** on the standalone application (no Control Plane → Integrations menu); the UI suggests upgrading to UniFi OS Server |

`guest_access` setting fields for the external portal (`/api/s/<site>/rest/setting/guest_access`): `auth: "custom"`,
`custom_ip` (IPv4 only), `redirect_https` ("HTTPS Redirection Support"), `redirect_to_https` ("Secure Portal"),
`ec_enabled` ("Encrypted URL"), `portal_use_hostname` / `portal_hostname` ("Domain"), `allowed_subnet_1..n`
("Pre-Authorization Allowances"), `restricted_subnet_1..3`, `redirect_enabled` / `redirect_url` ("Success Landing
Page"), `expire*`, `portal_enabled`, `portal_customized: false`.

Smoke test of the same build in **API key mode against UniFi OS Server 5.1.42** (Network 10.5.67, installed from
Ubiquiti's Linux installer in a VM, no access points, no clients):

| Call | Answer |
|---|---|
| `GET /proxy/network/integration/v1/sites` with `X-API-KEY` | 200 `{"count":1,"data":[{"id":"<uuid>","internalReference":"default","name":"Default"}]}`; without a key 401 |
| `GET /proxy/network/integration/v1/info` | 200 `{"applicationVersion":"10.5.67"}` (check button: "version 10.5.67, site default, 0 connected clients") |
| `GET .../clients?filter=macAddress.eq('<mac>')` for a MAC that never connected | 200 with `count: 0`: the official API lists connected clients only |
| `POST .../clients/<mac>/actions` `AUTHORIZE_GUEST_ACCESS` with the MAC instead of a client id | 400 `api.request.argument-type-mismatch`: actions need the client UUID, so **no pre-authorization** in API key mode; `authorizeClient()` reports `not_connected` |
| API keys | Created under Network → Integrations → "Create New API Key" (name, description, expiry); shown once |
| `/integration/v1/sites` without the `/proxy/network` prefix | 200 but HTML (the UniFi OS login page), so always use the prefix on UniFi OS |

Not verified: the AP's redirect (URL, scheme, parameters), traffic actually let through, the legacy API on UniFi OS
(`/api/auth/login`), and the official API against a hardware console (only UniFi OS Server was available).

## 8. Reporting results

Open an issue with the "Test report" template and mention: UniFi hardware (AP models, console or self-hosted),
Network application version, access mode (API key or local account), the role of the account, whether the
redirect reached the portal (and with which URL: scheme, host, path), whether the device got online after the
login and how long it took, revoke/extension behaviour, and the result of "Check controller". Do not post keys,
passwords, real host names or IP addresses.
