# Generic RADIUS captive portal (experimental)

Since v0.28.0 the portal has a vendor-neutral driver `generic_radius` ("Generic RADIUS captive portal
(experimental)") for captive portals that work like the MikroTik hotspot: the device redirects unauthenticated guests
to an external page, the guest's browser posts a user name and password back to the device, and the device checks
them with RADIUS (PAP). Parameter names, the login URL and the form fields are set per site; vendor presets prefill
them. **Only CoovaChilli 1.9 and OPNsense 26.7 have been observed on real software** (2026-11-04, in a test setup;
their presets follow what was seen); the other presets follow public documentation (sources below and in
`app/Nas/Generic/Presets.php`), and a NAS simulator checks the driver against those conventions only. Please report
what works and what does not with the "Test report" issue template (§8). Decision and design:
[CONCEPT.md](../CONCEPT.md) §5.1.

## 1. How it works

1. The device redirects the guest to `https://<portal>/portal/g/<site>?<its parameters>` (or, from a page hosted on
   the device, to `/portal?site=<site>&...`). `/portal/g/<site>` exists for devices that append their query string to a
   configured URL.
2. The driver reads the client MAC, the client IP, the login URL and the original URL with the parameter names of the
   site's mapping. Without a MAC or a usable login URL the guest gets "not connected"; when the device sent a login URL
   that is not allowed, "not set up correctly".
3. After the portal login the guest's browser sends the one-time credentials (valid 60 s) to the login URL: a POST
   form, a GET navigation with the fields as query string, or a navigation with the fields in the URL fragment
   (`#user=..&password=..`, read and posted by a page on the device; never sent to a server). The success URL field
   carries the portal's status page.
4. The device sends an Access-Request (PAP, `Calling-Station-Id` = client MAC in any notation) to the portal's
   FreeRADIUS; the portal answers with `Session-Timeout`, `Acct-Interim-Interval` and optionally WISPr bandwidth
   limits. Accounting Start, Interim-Update and Stop as usual.
5. Revoke, shorten and rebind send a Disconnect-Request (RFC 5176) with the attributes chosen for the site to the
   router's CoA port. `Session-Timeout` stays the upper bound if the device does not support it.

## 2. Mapping reference (site page, section "Generic RADIUS captive portal")

| Field | Meaning |
|---|---|
| Preset | Prefills the fields ("Apply preset"); check them and save. Stored only as a label. |
| Parameter: client MAC | Required. Accepted notations: `aa:bb:cc:dd:ee:ff`, `aa-bb-cc-dd-ee-ff`, Cisco `aabb.ccdd.eeff`, bare hex. |
| Parameter: client IP | Optional, shown in the guest list until accounting reports it. |
| Parameter: login URL | Optional. The device sends its login URL; its presence also marks a configured device ("not set up correctly" when the URL is refused). |
| Fixed login URL | Used without a login URL parameter. `{name}` is replaced by the redirect parameter `name` (only letters, digits, `.`, `:`, `_`, `-`), e.g. `http://{uamip}:{uamport}/logon`. |
| Parameter: original URL | Optional; a portal URL with `?code=` in it prefills the code. |
| Method | `POST` (form), `GET` (navigation with query string) or `fragment` (navigation with the fields after `#`; a page on the device reads and posts them, see the OPNsense template). |
| Field: user name / password / success URL | Names of the login form fields; the success URL field is optional. |
| Fixed extra fields | Up to 10 lines `name=value`; `{name}` takes a redirect parameter, `{handoff_secret}` the handoff secret. The mapped user name, password and success fields win over extra fields with the same name. |
| Password encoding | `Plain (PAP)` or `CoovaChilli UAM`: hex of the password XOR MD5(challenge + uamsecret), or XOR the challenge without uamsecret (CoovaChilli `redir.c`). |
| Allow a login URL over HTTP | Required for an `http://` login URL (CoovaChilli, OPNsense). The one-time credentials then travel unencrypted over the guest network; with POST the browser first warns that the form is not secure (the portal itself is HTTPS). |
| Handoff secret | Optional, encrypted with the federation data key, never shown. CoovaChilli: the uamsecret. Put into an extra field it is visible to the guest's browser. |
| WISPr bandwidth limits | Default on: `WISPr-Bandwidth-Max-Up/Down` in bit/s from the grant's rate limit (RouterOS notation `rx/tx`, e.g. `2M/10M` = 2 Mbit/s up, 10 Mbit/s down; burst values are ignored). The WISPr dictionary is part of FreeRADIUS (`share/dictionary` includes `dictionary.wispr`, vendor 14122). |
| Attributes of the Disconnect-Request | Default `User-Name`, `Acct-Session-Id`, `Framed-IP-Address`; also `Calling-Station-Id` (sent as `AA:BB:CC:DD:EE:FF`). `Framed-IP-Address` only while the session is open and updated within `portal.radius.stale_after` (the IP may be reused); nothing is sent for stopped sessions. `NAS-Port-Id` is not offered: accounting does not record it. **None selected** = the device does not support Disconnect-Request (OPNsense): revoking or shortening ends access only at the login's `Session-Timeout`. |
| Parameters of the login page | Only for devices that host their own portal page: a query string with the device's variables (e.g. pfSense `$CLIENT_MAC$`). Empty: the downloaded `login.html` passes the whole query string on to `/portal/g/<site>` (needs JavaScript). |

The login URL must be HTTPS (HTTP only as above), without user name or password, and its host must be one of the
site's **hotspot host names** (an IP is fine, e.g. `10.1.0.1`). The mapping is a replicated security site setting
(`generic`): other instances than the hub and the site's home instance cannot change it.

**Simulate redirect** (site page and setup page) builds a sample redirect from the saved mapping and shows what the
portal reads (MAC, IP, login URL, original URL, complete or not) and what the handoff would send. Nothing is stored;
the link opens the sample in the guest portal.

## 3. Checklist and walled garden

The setup page and each router's "Router configuration" page show a plain-text checklist instead of scripts:

- RADIUS server = the site's RADIUS address, ports 1812/1813, the router's secret, NAS-Identifier if set, PAP,
  accounting with interim updates. **The router entry's NAS IP is the source address of the RADIUS packets as the
  portal sees them**: behind NAT or a Docker bridge that is the translated address (in one test setup both devices
  arrived from the Docker bridge gateway, so one router entry with that address served both).
- Disconnect-Request (CoA) from the RADIUS address on the router's CoA port, with the chosen attributes; with none
  chosen the checklist says that revoking ends access at the `Session-Timeout`.
- The redirect URL `https://<portal>/portal/g/<site>` and the parameters read.
- The login form: method, URL, fields; the success URL is the portal's status page.
- **Walled garden** (allowed before login; what the vendor calls it per preset): the portal host on TCP 443, the
  portal IP (`portal_address`, else the RADIUS address) on TCP 443 and on TCP 80 if the device redirects plain HTTP to
  it, DNS for the guests, and the social login hosts of the providers offered (`WalledGarden::hosts()`); wildcard
  entries such as `*.gstatic.com` need vendor support, otherwise list the host names you need.
- MAC authentication note (§5).

## 4. Presets

All presets are **unverified**. They prefill the mapping only.

| Preset | Redirect (parameters) | Login | Walled garden | Sources | Unverified |
|---|---|---|---|---|---|
| Cisco WLC / Catalyst 9800 (external web auth) | `client_mac`, `ap_mac`, `wlan`, `switch_url`, `redirect` | POST to `switch_url`: `username`, `password`, `redirect_url`, `buttonClicked=4`, `err_flag=0` | Pre-authentication ACL / URL filter | [Cisco 9800 external web auth](https://www.cisco.com/c/en/us/support/docs/wireless/catalyst-9800-series-wireless-controllers/217457-configure-and-troubleshoot-external-web.html), [community AireOS portal](https://github.com/stuartst/cisco-wlc-captive-portal/blob/master/README.md) | Success field (`redirect_url` in the AireOS sample page, `redirectUrl` in the 9800 document), `err_flag`; the 9800 sends the parameters only with `redirect append ap-mac/client-mac/wlan` and may name them differently; `switch_url` must be HTTPS (virtual IP with a certificate) |
| Aruba Instant / ArubaOS | `cmd=login`, `mac`, `essid`, `ip`, `apname`, `apmac`, `vcname`, `switchip`, `url` | POST to `https://securelogin.arubanetworks.com/cgi-bin/login`: `user`, `password`, `cmd=authenticate`, `url` | Whitelist of the external captive portal | [Aruba IAP guide, redirect parameters](https://www.manualslib.com/manual/1483534/Aruba-Iap-335.html?page=133), [walled garden](https://www.manualslib.com/manual/1483534/Aruba-Iap-335.html?page=118), [community login form](https://community.networking.hpe.com/discussion/external-captive-portal-login-url) | The `url` field in the login POST; `/cgi-bin/login` vs `/swarm.cgi`; the login host must match the AP certificate (add it to the hotspot host names, or use `https://{switchip}/cgi-bin/login` when "Switch IP" is on) |
| pfSense | Uploaded portal page (this driver's login page with the template `login_url=$PORTAL_ACTION$&zone=$PORTAL_ZONE$&redirurl=$PORTAL_REDIRURL$&mac=$CLIENT_MAC$&ip=$CLIENT_IP$`) | POST to `$PORTAL_ACTION$` (e.g. `https://<pfSense>:8003/index.php?zone=guest`): `auth_user`, `auth_pass`, `redirurl`, `accept=Continue`, `zone` | Allowed IP Addresses and Allowed Hostnames | [Netgate captive portal](https://docs.netgate.com/pfsense/en/latest/captiveportal/index.html), [Netgate forum, custom form](https://forum.netgate.com/post/826261) | The page variables `$CLIENT_MAC$` / `$CLIENT_IP$` (without a MAC the device cannot be bound: "not connected"); the HTTPS login port depends on the zone |
| OPNsense (observed on 26.7) | Custom template [`docs/opnsense-captive-template/index.html`](opnsense-captive-template/index.html) forwards with `nas_host`, `zone`, `mac`, `ip`, `redirurl` (§4.1) | POST over HTTP to `http://{nas_host}/api/captiveportal/access/logon/{zone}/`: `user`, `password` (the guest then sees OPNsense's JSON answer), or method `fragment` through the template | Allowed addresses of the zone (IPs, networks) | Observed; [OPNsense captive portal](https://docs.opnsense.org/manual/captiveportal.html), [AccessController.php](https://github.com/opnsense/core/blob/master/src/opnsense/mvc/app/controllers/OPNsense/CaptivePortal/Api/AccessController.php) | Template packaging per OPNsense version; no Disconnect-Request (preset selects none) |
| CoovaChilli / OpenWrt (UAM, PAP; observed on 1.9) | `res`, `uamip`, `uamport`, `challenge`, `called`, `mac` (`AA-BB-CC-DD-EE-FF`), `ip`, `nasid`, `sessionid`, `userurl` (empty for a request to a bare IP) | GET `http://{uamip}:{uamport}/logon`: `username`, `password` (UAM encoding: zero-padded to 16-byte blocks, XOR the raw challenge, or MD5(challenge + uamsecret) with the uamsecret as handoff secret), `redirurl` | `uamallowed` / `uamdomain` | [RadiusDesk dynamic keys](https://radiusdesk.com/wiki24/radius/login_dynamic_keys), [YFi UAM](https://sourceforge.net/p/hotcakes/wiki/yfi_explain_uam), [CoovaChilli `redir.c`](https://github.com/coova/coova-chilli/blob/master/src/redir.c) | `redirurl` as success field on real devices; walled-garden option names. CHAP (`response`) is not supported: the portal stores no clear-text password. Add `uamip` to the hotspot host names. |
| Ruckus Unleashed / ZoneDirector (hotspot WISPr) | `client_mac`, `uip`, `sip`, `nbiIP`, `url`, `ssid` | POST to `https://{sip}:9998/login`: `username`, `password`, `url`, `client_mac`, `uip` | Walled Garden of the hotspot service | [Ruckus redirect URL format](https://community.ruckuswireless.com/discussion/47226), [Netgate forum, POST fields](https://forum.netgate.com/post/1132205) | Redirect parameter names and MAC notation; HTTP port 9997 exists too (not usable: POST over HTTP); SmartZone encrypts MAC and IP in the redirect and logs in through its northbound interface (not covered). Add `sip` to the hotspot host names. |
| Custom | `mac`, `ip` | POST, `username`, `password`, `dst` | – | – | Enter your device's names |

Observed on real software: CoovaChilli 1.9 sends User-Name, User-Password, Service-Type, Acct-Session-Id,
Framed-IP-Address, NAS-Port-Type 19, NAS-Port, NAS-Port-Id, Calling-Station-Id and Called-Station-Id (dashed MACs),
NAS-IP-Address, NAS-Identifier, Message-Authenticator and its vendor attributes; it listens for Disconnect-Request on
UDP 3799; it drops the logon request after about 50 s without a RADIUS answer. OPNsense 26.7 sends User-Name,
User-Password (PAP), Service-Type Login, Framed-Protocol, NAS-Identifier, NAS-Port 0, NAS-Port-Type Ethernet and
Calling-Station-Id (client MAC), **no Framed-IP-Address and no Called-Station-Id**, honours Session-Timeout and
Acct-Interim-Interval, and has **no Disconnect-Request** support. For the other presets no source states whether the
device sends the client MAC as `Calling-Station-Id`; the portal requires it (the one-time credentials are bound to
the MAC of the redirect, CONCEPT.md §7.1).

### 4.1 OPNsense

OPNsense has no "external login page" option: every zone redirects unauthenticated guests to its own template
(`http://<zone address>:8000/index.html?redirurl=..`). The template
[`docs/opnsense-captive-template/index.html`](opnsense-captive-template/index.html) replaces the template's
`index.html`:

1. It asks `POST /api/captiveportal/access/status/<zone>/` for the guest's IP and MAC and forwards the guest to
   `PORTAL?nas_host=<its host:port>&zone=<zone>&mac=..&ip=..&redirurl=..`. Set `PORTAL`
   (`https://<portal>/portal/g/<site>`) and `ZONE` at the top of the file, put it into a copy of the zone's default
   template and upload that as the zone's custom template.
2. OPNsense identifies the guest by its source IP, so the guest's browser must log in. Default handoff (preset): a
   form POST to `http://{nas_host}/api/captiveportal/access/logon/{zone}/` with `user` and `password`; the browser
   first warns that the form is sent over HTTP, and afterwards shows OPNsense's JSON answer
   (`{"clientState":"AUTHORIZED",..}`) instead of the status page.
3. Method `fragment` (set the login URL to `http://{nas_host}/index.html`): the portal sends the browser back to the
   template with `#user=..&password=..&redirurl=<status page>`; the template removes the fragment from the address
   bar, posts the logon itself and opens the status page (only URLs of the portal). No form warning, no JSON page;
   recommended once the template is installed.

The RFC 8908 endpoint `/api/captiveportal/access/api/` announced `https://<zone address>:8000/index.html` as
user portal URL although the zone served HTTP (observed); the portal does not use it.

## 5. Limits

- No router state query, no setup assistant scripts or wizard, no automatic login page download (the device has to
  redirect to the portal URL, or the downloaded `login.html` is uploaded by hand), no RouterOS API section.
- **MAC authentication** (MAC whitelist, roaming between sites without a new login) only works if the device sends an
  Access-Request with the client MAC as `User-Name` and as `Calling-Station-Id` (the core's RADIUS rule, CONCEPT.md §5).
- Disconnect: the device decides which attributes identify a session; choose them per site. Without a working
  Disconnect-Request, access ends at `Session-Timeout` at the latest.
- HTTP handoff only with the explicit switch. The guest CSP allows `http:` form actions for it (decision
  2026-11-04); browsers still warn before a form goes from the HTTPS portal to HTTP. GET and `fragment` are
  navigations without that warning.
- The browser may keep a GET handoff URL (one-time credentials, valid 60 s and single use) in its history; the
  OPNsense template removes the fragment right away.
- Devices without Disconnect-Request support (OPNsense): revoke and shorten take effect at the `Session-Timeout`.

## 6. NAS simulator (Docker)

`tests/nas-sim` ([README](../tests/nas-sim/README.md)) emulates the captive portal of each preset as documented and
acts as the NAS: redirect with the vendor's parameters and MAC notation, the login form with its fields and fixed
values (CoovaChilli password decoding), Access-Request (PAP, Message-Authenticator), Accounting-Start, interim updates,
Disconnect-ACK after checking the attributes the driver sends, Accounting-Stop. `run.sh <portal-container>` seeds a
test instance, runs the simulator and a Playwright guest in the portal container's network namespace (code login →
handoff → status page "You are online" → revoke → Disconnect-ACK and Accounting-Stop) and prints a summary per preset;
`run.sh --local` checks redirect and handoff against `php artisan serve` without FreeRADIUS. The OPNsense runs
(`opnsense`, `opnsense_fragment`) use the real template file and expect no Disconnect-Request.

**What it proves:** that the driver, the portal and FreeRADIUS follow the conventions written down for each preset
(parameter names, MAC notations, login URL rules, form fields, password encoding, RADIUS reply and Disconnect
attributes). **What it cannot prove:** that a real device uses these conventions, accepts the posted form, sends the
MAC as `Calling-Station-Id`, honours the success URL, or answers the Disconnect-Request.

## 7. Reporting results

Open an issue with the "Test report" template and mention: device and firmware, the preset and every field you had
to change, whether the redirect, the login, accounting (guest list shows usage) and revoke (Disconnect-Request) worked,
what the device calls the walled garden and which entries were needed, and the device's log lines for a failed login
(without secrets, real host names or addresses).
