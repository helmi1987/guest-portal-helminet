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
| Official Network API (API key): site lookup, client lookup by MAC, `AUTHORIZE_GUEST_ACCESS` with `timeLimitMinutes`, `UNAUTHORIZE_GUEST_ACCESS`, usage | Implemented after the OpenAPI files 10.6.106 / 11.0.97; **not tested against a console or UniFi OS Server** |
| Legacy API (local account): login with cookie and CSRF token, `stat/sta`, `cmd/stamgr` `authorize-guest` / `unauthorize-guest` | Implemented after the community clients; checked against a standalone controller without access points where noted in §7 |
| Variant detection of the legacy API (standalone `/api/login` vs. UniFi OS `/api/auth/login` + `/proxy/network`) | Standalone path: see §7; UniFi OS path **unverified** |
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
   - External portal: the **IPv4 address** of the portal (the field takes no host name).
   - Domain (Network 10) / Redirect Hostname (8/9): the portal host name, e.g. `portal.example.com`.
   - "Use Secure Portal": on. The portal answers on its HTTPS guest port only (no plain HTTP listener) and needs a
     certificate that guests' devices trust for that host name. If an AP redirects to plain HTTP anyway, put a
     redirect to HTTPS in front of the portal (behaviour of the AP **unverified**).
3. **Pre-authorization allowances:** the portal host and its IP, plus the hosts of the switched-on social login
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
- **The client must be connected** when it is authorized (the official API lists connected clients only);
  guests cannot be pre-authorized.
- **Time limit per authorization:** the controller ends the authorization itself at the end of the grant
  (`timeLimitMinutes`). Extending or shortening a running grant authorizes the device again; a device that is
  offline at that moment keeps its old end until its next login.
- **Revoking an offline device:** the official API can only address connected clients, so a revoked device that
  is offline keeps its authorization until it expires on the controller or reconnects while the portal still
  retries (legacy mode sends `unauthorize-guest` by MAC; whether the controller ends a stored authorization of
  an offline MAC is unverified).
- **Device binding:** the MAC comes from the unsigned redirect; the portal checks it against the controller
  (connected guest, AP), but a guest can still authorize another connected guest device with their own code
  (the grant's single-device rule still applies).
- Rate limits of codes (`rate_limit`) are not passed to UniFi yet.

## 7. Verified against a controller

<!-- Filled in from the smoke test of v0.27.0 against a standalone Network Application without access points. -->
Not yet verified against a controller.

## 8. Reporting results

Open an issue with the "Test report" template and mention: UniFi hardware (AP models, console or self-hosted),
Network application version, access mode (API key or local account), the role of the account, whether the
redirect reached the portal (and with which URL: scheme, host, path), whether the device got online after the
login and how long it took, revoke/extension behaviour, and the result of "Check controller". Do not post keys,
passwords, real host names or IP addresses.
