# Concept

Captive portal ("guest login") for MikroTik RouterOS 7 hotspots, multi-site, open-source-ready.
Developed privately first, published later. Facts not yet checked against vendor documentation
are marked **(to verify)**; proposals not yet confirmed by the maintainer are marked **(proposal)**.

## 1. Goals and scope

- Guests on a hotspot network get internet access only after logging in through the portal.
- Login methods: admin-issued code, SMS one-time password (OTP), push approval by an admin, social login
  (Google, Apple, Facebook, GitHub; decision 2026-11-03, v0.22.0), user account of the site's Active
  Directory / LDAP (decision 2026-11-03, v0.24.0).
- Every login requires first name, last name and acceptance of the current terms of use.
- Multi-site: one instance can serve several sites, or each site runs its own self-sufficient
  instance; instances can join a **federation** so grants are valid everywhere (§10).
- One container image, configurable via environment, volumes and a web setup; nothing
  site-specific is baked in.
- Small user base: a handful of admins/managers (household / small business).

Out of scope: payment, user self-registration, billing.
License: AGPL-3.0-or-later (decided), see `LICENSE`.

## 2. Architecture overview

```
 Guest device ──Wi-Fi/VLAN──> RouterOS hotspot (site A)
     │                              │  RADIUS 1812/1813 UDP ───────────┐
     │  HTTPS guest port (443)      │  <── Disconnect 3799 UDP ───┐    │
     └──────────────────────────────┼─────────────────────────────┼──> Instance (container)
                                    │                             │    ├─ nginx: guest :443, admin :8443,
 Admin/manager ──VPN / proxy──> admin port                        │    │         federation :9443 (mTLS)
                                                                  │    ├─ php-fpm (Laravel)
 Other instances (peers) <──── federation API (mTLS) ────────────>│    ├─ FreeRADIUS (rlm_rest)
                                                                  └────├─ queue worker, scheduler
                                                                       └─ Telegram poller (owner only)
```
Port numbers 8443/9443 are defaults **(proposal)**, configurable via env.

### 2.1 Per site (router side)

- Guest SSID/VLAN (CAPsMAN v1/v2 or any AP) on an interface with a RouterOS Hotspot,
  `use-radius=yes`.
- Custom `login.html` immediately redirects to the portal, passing `$(link-login-only)`, `$(mac)`,
  `$(ip)`, `$(link-orig)` and a site identifier (query string).
- Walled garden: only the portal host (guest port).
- `login-by=https` with a router certificate (§6) so the HTTPS portal can post to the router
  without mixed-content warnings. `mac-cookie` optional; validity is always governed by RADIUS.
- `/radius incoming` enabled for Disconnect-Request (port 3799).
- A site's routers talk RADIUS to exactly one instance (the site's **home instance**).

### 2.2 Container

One Alpine Linux based image (decided):

| Component | Choice | Reason |
|---|---|---|
| Web server | nginx + php-fpm (Alpine packages) | Small, mature, multi-arch; separate listeners per port are trivial. |
| App | Laravel (full stack), Livewire 3, Tailwind | One codebase for portal, admin, internal RADIUS API, federation API, jobs. |
| RADIUS | FreeRADIUS 3 + `rlm_rest` | Proven; all decision logic in Laravel via internal REST API. |
| Supervisor | s6-overlay | Proper PID 1, signal handling, service dependencies, tiny, no Python. |
| Queue / cache | Laravel `database` drivers | No Redis needed. |
| Scheduler | `php artisan schedule:work` as s6 service | No cron daemon. Decision 2026-11-02: every scheduled command appends its stdout and stderr to the standard output of `schedule:run` (`/proc/<pid>/fd/1`, `App\Console\SchedulerOutput`; without `/proc` to `storage/logs/scheduler.log`), which `schedule:work` copies to the container log; Laravel's default `/dev/null` dropped their console and `Log::` lines (stderr channel). |
| Database | SQLite (WAL) on volume `/data` | Zero ops. Optional MariaDB/MySQL via `DB_CONNECTION` env for larger installs. |

- Time zone (decision 2026-10-29, v0.10.0): `app.timezone` stays UTC, so the database, expiry and
  rate-limit logic and the scheduler (fixed UTC times, no DST gaps) are unchanged. Only display and
  input use the display zone (`APP_TIMEZONE`, else `TZ`, else UTC; unknown names: UTC): admin UI
  times, audit date filter, `datetime-local` inputs (converted to UTC on save), notification texts ("open until"),
  backup and CSV export file names. Rejected: setting `app.timezone` to local time (Eloquent stores
  wall-clock times without offset, existing rows would shift and DST makes local times ambiguous).
- Internal API: nginx listener on `127.0.0.1` only (e.g. port 8080) for FreeRADIUS → Laravel,
  authenticated by a random shared token generated at first start (sent by `rlm_rest` as HTTP
  basic auth password).
- Port separation (implemented M1): each nginx listener passes a zone (`guest`, `admin`,
  `internal`) to Laravel as FastCGI parameter (not a client header); every route is bound to one
  zone and answers 404 elsewhere. Admin routes live under `/admin`, internal ones under
  `/internal`. Separate session cookies per zone. Ports via env `GUEST_PORT`, `ADMIN_PORT`,
  `INTERNAL_PORT`.
- FreeRADIUS clients (routers + secrets): Laravel renders a clients file into `/data` and
  restarts FreeRADIUS via s6 on change. Recommended over dynamic clients: few, static routers;
  restart takes about a second; router sessions are unaffected. Implemented M2: an s6 service
  (`radius-watch`, root) polls the file checksum and runs `s6-svc -r` (the app runs unprivileged).
  FreeRADIUS passes the matched client (`FreeRADIUS-Client-Shortname` = `nas-<id>`) to Laravel, so
  the NAS is identified by source IP + secret.

### 2.3 Runtime targets

| Target | Status |
|---|---|
| Regular Docker host (e.g. Unraid) | Supported; healthcheck available. |
| RouterOS container, amd64 / arm64 | Supported. |
| armv7 | Not built now; revisit at public release. |

Measured memory (M1 skeleton, amd64, idle after a few requests): ~120–150 MB. Re-measure with features.
Image: Alpine 3.24, PHP 8.4, Laravel 13, Livewire 3 (code stays compatible with PHP 8.3). RouterOS devices mostly need external storage.

## 3. RouterOS container specifics

- Container package installed and device mode allows containers
  (`/system/device-mode/update container=yes`, confirmed physically within 5 min by button or cold boot).
- Networking: `/interface/veth` with static address + gateway, port of a dedicated bridge
  (e.g. `containers`) with an IP on the router; firewall/NAT as needed. No NAT between routers and
  the instance for RADIUS (source IP identifies the NAS).
- Mounts (`/container/mounts add list=..`, container property `mountlists`; envs via `envlist`):
  persistent `/data` (SQLite, generated configs, env file, certificates, backups in `/data/backups`).
- Env: only non-secret bootstrap values via `/container/envs` (visible in config exports);
  secrets in an env file on `/data` (§12).
- `start-on-boot=yes`, `logging=yes`; memory limit (property name `memory-high`/`memory-max`
  depends on RouterOS version **(to verify)**).
- Private GHCR: `/container/config set registry-url=https://ghcr.io username=<user>
  password=<read-only token>`; not needed once public.
- No healthcheck/compose: the image self-heals (s6 restarts services). Update = pull new tag + restart.

## 4. Build and distribution

- GitHub Actions, Docker Buildx + QEMU, push to GHCR. Platforms: `linux/amd64`, `linux/arm64`.
- Front-end assets and Composer install run in a builder stage on `$BUILDPLATFORM`.
- Tagging scheme:

| Trigger | Source | Tags pushed |
|---|---|---|
| Push to `main` | `main` | `edge`, `sha-<short>` |
| Git tag `vX.Y.Z` | tag | `X.Y.Z`, `X.Y`, `X`, `latest`, `X.Y.Z-<YYYYMMDD>` |
| Weekly, Monday (cron) | `main` | `edge`, `sha-<short>` (rebuilt with `--pull`) |
| Weekly, Monday (cron) | newest release tag | `X.Y.Z-<YYYYMMDD>` (new, immutable) and moves `X.Y.Z`, `X.Y`, `X`, `latest` to it |

- Same code, new base layer: version stays, dated suffix identifies the build (pin it or a digest for immutability).
- OCI labels, SBOM/provenance via buildx; vulnerability scan (e.g. Trivy) as report **(proposal)**.

## 5. RADIUS design

- Access-Request (PAP over hotspot) → FreeRADIUS → `rlm_rest` → `POST /internal/radius/authorize`.
- Credentials are **one-time portal tokens** (decided): after a successful portal login Laravel
  issues a random username/password, single use, valid ~60 s; the portal auto-submits it to
  `link-login-only`. Codes, OTPs or phone numbers never go to the router.
- Checks: token valid, NAS known (source IP + NAS-Identifier) and site allowed for the grant,
  `Calling-Station-Id` (MAC reported by the router) matches the token's MAC (decided).
- Access-Accept: `Session-Timeout` = remaining wall-clock seconds of the grant;
  `Acct-Interim-Interval`; optional `Mikrotik-Rate-Limit` per grant/site (default: none).
- Accounting (Start/Interim/Stop) → `POST /internal/radius/accounting`, stored locally.
- The MAC binding (device) is created when RADIUS accepts the one-time token (MAC from
  `Calling-Station-Id`), not at code entry; a new MAC unbinds the old device and disconnects its
  session (M2).
- Shortening a running grant does not disconnect immediately: the scheduler
  (`portal:enforce-grants`, every minute) disconnects sessions of expired, revoked or rebound
  grants (M2).
- Revoke / shorten / rebind: a job sends Disconnect-Request (`radclient`) to the router's
  `/radius incoming` port 3799. The identifying attributes come from the NAS driver
  (`disconnectAttributes`). Decision 2026-10-31 (v0.12.6, verified on a RouterOS 7 hotspot): RouterOS
  answers User-Name and/or Acct-Session-Id (also with Message-Authenticator) with Disconnect-NAK, Error-Cause
  406; only `Framed-IP-Address` alone removes the hotspot active entry. The MikroTik driver sends only
  `Framed-IP-Address` from accounting (session summary `ip`); unknown IP: warning, nothing sent, job fails
  (no User-Name fallback). Risk: a hotspot IP can be reused by another guest after a DHCP lease change;
  mitigation: sent only while the accounting session is open (no Stop) and its last update is within
  `PORTAL_RADIUS_STALE_AFTER`. The session ends with the router's Accounting-Stop as before. After an extension the current session ends at the old
  Session-Timeout and the guest is re-authenticated automatically via mac-cookie or roaming
  login (§10.4) **(to verify)**.
- **MAC whitelist** (v0.8.0, decided): devices entered by admin/manager (MAC, required comment, all or
  selected sites, optional expiry, active flag) get online without the portal via RouterOS MAC
  authentication (hotspot profile `login-by=https,mac`; before the router certificate exists
  `login-by=mac`; default `mac-auth-mode=mac-as-username`, empty password **(to verify)**). The router
  sends an Access-Request with User-Name = MAC first; Laravel accepts only when User-Name is a MAC
  (strict format; portal token names never are) equal to `Calling-Station-Id`, and an active, not
  expired entry allows the NAS's site; the password is not checked. Accept without `Session-Timeout`
  (unlimited) unless the entry expires (then remaining seconds), with `Acct-Interim-Interval`; no
  `Idle-Timeout` (router user profile applies). Otherwise Access-Reject (logged at debug level) and the
  normal portal flow follows; rejects use a cached "no valid entry" flag and a 60 s negative cache per
  site + MAC, both invalidated by any change of an entry or its site links (cache generation).
  Accounting creates session summaries without grant (`mac_whitelist_id`); an Accounting-Start without
  a valid entry is still recorded (linked to the newest entry of the MAC, also deleted) and disconnected.
  Deactivate, delete, site removal or MAC change disconnect open sessions of the entry (after commit);
  expiry is enforced by `portal:enforce-grants`. User-Name and MAC format sent by RouterOS: **(to verify)**.

### 5.1 NAS drivers (vendor neutrality)

The RADIUS core (grants, Session-Timeout, accounting, Disconnect-Request per RFC 5176) is
vendor-neutral. Vendor-specific parts sit behind a `NasDriver` interface; each site has a
`driver` field.

| Responsibility | MikroTik RouterOS 7 driver | Ubiquiti UniFi driver (experimental, v0.27.0) | Generic RADIUS captive portal (experimental, v0.28.0) |
|---|---|---|---|
| Parse captive-portal redirect parameters | `login.html` query: `link-login-only`, `mac`, `ip`, `link-orig`, site id | Route `/guest/s/{unifiSite}`: `id`, `ap`, `ssid`, `url`, `t` | Route `/portal/g/{slug}` or `/portal?site=`: parameter names per site (MAC, IP, login URL, original URL), MAC in colon, dash, dot or bare notation |
| Post-portal login handoff | Auto-submit one-time credentials to `link-login-only` | Server side: controller API authorizes the client (`AuthorizesViaController`) | Browser POST, GET or `fragment` navigation to the login URL (parameter, or fixed URL with `{name}` placeholders), mapped field names, fixed extra fields, optional CoovaChilli UAM password encoding |
| Vendor RADIUS attributes | e.g. `Mikrotik-Rate-Limit` | none (no RADIUS) | Optional `WISPr-Bandwidth-Max-Up/Down` |
| Disconnect capability | RFC 5176 via `/radius incoming`; only `Framed-IP-Address` (§5) | Controller API: unauthorize guest | RFC 5176 with the attributes chosen per site (`User-Name`, `Acct-Session-Id`, `Framed-IP-Address`, `Calling-Station-Id`); none = not supported (OPNsense), Session-Timeout ends access |
| Config generator | RouterOS script + `login.html` (§9) | Controller checklist (setup page) | Device checklist with the generic walled-garden list (setup page), generic login page |

Other drivers (e.g. Omada) are future/community work; the generic driver is the quickest way to try another
RADIUS captive portal.
Guide: [docs/nas-driver.md](docs/nas-driver.md).

Decision 2026-11-04 (v0.27.0, experimental UniFi driver): the owner wants a UniFi driver that "should work" although
no UniFi hardware is available; it ships as **experimental** (label "Ubiquiti UniFi (experimental)", banner in the
site's controller section, feedback through the test report issue), against the study's recommendation to wait
([docs/unifi-feasibility.md](docs/unifi-feasibility.md), 2026-11-03). Design: an optional interface
`AuthorizesViaController` next to `NasDriver` (`controllerFields`, `authorizeClient`, `deauthorizeClient`,
`clientStatus`, `checkController`); for such sites the core authorizes after the portal login instead of the
RADIUS handoff, records device binding, used token and session summary itself (one local accounting row without
NAS), shows the controller's state on the status page, ends access through the controller on revoke, rebind and
expiry, authorizes again on an admin's extension or shortening (UniFi enforces the time limit itself), and polls
the controller for usage (`portal:poll-controllers`, every two minutes). Both API modes: the official Network API
with an API key (`/proxy/network/integration/v1`, UniFi OS consoles and UniFi OS Server) and the legacy cookie
login with a local account (`/api/login` on the standalone application, `/api/auth/login` + `/proxy/network` on
UniFi OS, detected at login), because the standalone application (the only one testable without a console) has
no API keys. **No RADIUS accounting** (usage per authorization from the controller, reset on every
authorization), **no MAC whitelist and no roaming** on UniFi sites; with an API key the client must be connected
when it is authorized, the legacy API also pre-authorizes a MAC that never connected (verified on a standalone
10.6.109, which has no API keys). The UniFi external portal server is an IPv4 address (`custom_ip`): the portal's
guest listener must be reachable at it over HTTPS; the AP's plain-HTTP redirect is an open point. Controller settings: replicated security site setting `controller` (URL, mode, UniFi site, TLS
verification or public key pin, fallback duration), API key and password encrypted with the federation data key.
Setup, limits and verification state: [docs/unifi-setup.md](docs/unifi-setup.md).

Decision 2026-11-04 (v0.28.0, experimental generic RADIUS captive-portal driver): the owner asked for "generic"
support (e.g. Cisco) and a vendor-neutral walled garden, experimental like UniFi. The driver `generic_radius`
(label "Generic RADIUS captive portal (experimental)") serves NAS that redirect guests to an external page and check
the posted credentials with RADIUS PAP, as the MikroTik hotspot does. Its mapping is the replicated **security** site
setting `generic` (it decides where the one-time credentials go): redirect parameter names, the login URL from a
parameter or a fixed URL with `{name}` placeholders filled from the redirect (HTTPS only; HTTP only with an explicit
switch, for CoovaChilli and OPNsense; the host must be one of the site's hotspot hosts), handoff method (`POST`, `GET`
or `fragment`: navigation with the fields after `#`, read and posted by a page on the device) and field names, up to
ten fixed extra fields, the password encoding (`plain` or CoovaChilli UAM: the password zero-padded to 16-byte
blocks, XOR MD5(challenge + uamsecret), or the raw challenge without uamsecret), WISPr bandwidth limits from the grant's RouterOS rate limit, the Disconnect-Request
attributes (`Framed-IP-Address` only while the session is open and fresh, as for MikroTik; `NAS-Port-Id` is not
offered because accounting does not record it; none selected = the device has no Disconnect-Request, access ends at
the Session-Timeout) and an optional login page template. An optional handoff secret
(the uamsecret) is encrypted with the federation data key like the UniFi secrets. Vendor presets (Cisco WLC /
Catalyst 9800, Aruba Instant / ArubaOS, pfSense, OPNsense, CoovaChilli, Ruckus Unleashed / ZoneDirector, custom)
only prefill the form; they follow public documentation and cite their sources in `app/Nas/Generic/Presets.php`.
CoovaChilli 1.9 and OPNsense 26.7 were observed on real software (same day): chilli's UAM password encoding without
uamsecret, and OPNsense's behaviour, which the presets follow; the others are **unverified**. OPNsense has no external
login page: a custom template (`docs/opnsense-captive-template/index.html`) forwards guests to the portal, and the
guest's browser logs in through OPNsense's logon API (OPNsense binds the login to the client IP) by a form POST over
HTTP or the `fragment` handoff through the template; OPNsense sends no Framed-IP-Address and has no
Disconnect-Request (the preset selects no attributes). The guest CSP therefore allows `http:` form actions in
addition to `https:` (only sites with the explicit HTTP switch post there; browsers warn before posting from HTTPS to
HTTP). No scripts: the setup page and each router's configuration page show a checklist
(RADIUS, CoA, redirect URL, login form, generic walled-garden list with the portal host and IP, DNS and the social
login hosts, MAC authentication note), "Simulate redirect" shows what the portal reads from a sample redirect. MAC
whitelist and roaming need the NAS to send the MAC as `User-Name` (unchanged RADIUS rule). Core clean-up: the
portal no longer reads MikroTik parameter names itself; the optional interface `ReadsRedirectSignals` gives
"NAS sends a login URL" (misconfigured page) and the prefill code from the original URL, and the optional interface
`SiteBoundDriver` binds a driver to its site so that `loginHandoff()`, `replyAttributes()` and
`disconnectAttributes()` can use per-site settings; the handoff view also navigates by GET. A NAS that sends the
guest back to the portal after its own login (CoovaChilli: `res=success|already|failed…` to the UAM server; `/logon`
takes `userurl`, not `redirurl`) is handled through the signal `login_result` (mapping field "login result"): success
opens the status page of the browser's last handoff at the site (session, at most 10 minutes old), failed shows the
"login not completed" notice. A Disconnect-NAK ends the disconnect job without retries for all drivers (only no
answer is retried), so a session the NAS no longer knows does not hold up the queue; a NAS without
Disconnect-Request support completes the job once and marks the session (`disconnect_skipped_at`), and
`portal:enforce-grants` does not dispatch it again. A NAS simulator
(`tests/nas-sim`, Docker and Playwright) checks the driver against the documented conventions, not against real
devices. Guide: [docs/generic-captive-portal.md](docs/generic-captive-portal.md).

## 6. TLS

- Guest and admin ports use a publicly trusted certificate for a public DNS name resolving to an
  internal IP (e.g. `portal.example.com` → `10.0.0.10`), issued via DNS-01.
- Certificate sources: `file` (implemented M3): `/data/certs/portal.crt` + `portal.key` (full chain,
  PEM); legacy fallback `fullchain.pem` + `privkey.pem` mounted at `/certs`; self-signed fallback so
  setup works. Invalid or non-matching pairs and symlinks in `/data/certs` are ignored (logged);
  `chown -h`/`chmod` only on regular files. An s6 service (`cert-watch`, root) polls the files every
  10 s and reloads nginx after `nginx -t`. While no valid pair exists (e.g. during a replacement)
  cert-watch changes nothing (nginx keeps its loaded certificate) and retries on the next poll; the
  self-signed fallback is chosen only at container start (M6).
- Certificate pull (implemented M6; local settings per target instead of the planned env
  `TLS_SOURCE`): targets `portal` (`portal.crt`/`.key`) and `router` (`router.crt`/`.key`), each with
  source `none` (files by the operator), `certwarden` (`GET <base>/certwarden/api/v1/download/
  privatecertchains/<name>`, header `X-API-Key: <cert API key>.<key API key>`, PEM key + cert + chain)
  or `url` (generic URL returning a PEM bundle, optional bearer token). CertWarden (decision
  2026-10-29, v0.12.1): server URL stored without path (a pasted `.../certwarden` or
  `.../certwarden/app...` is cut on save and when pulling), resulting download URL shown; certificate
  and private key API keys entered in two fields, stored combined `<cert>.<key>` (unchanged format,
  existing values keep working; an empty field keeps the stored part); HTTP 404/401/403 shown with a
  hint. API key encrypted (APP_KEY),
  never shown, logged or audited; optional own CA PEM for a private CA, TLS verification always on.
  Scheduler every 6 h (`portal:cert-pull`) + "pull now" in the admin UI. Validation before writing:
  exactly one unencrypted key, a certificate matching it (placed first), currently valid; target
  `portal` must cover `PORTAL_HOSTNAME` (else the `APP_URL` host), otherwise rejected; target `router`
  only warns about site hotspot host names it does not cover. HTTP redirects are not followed (3xx =
  error, the key must not reach another host); source URLs without user info, the query is masked in
  UI, audit and errors. Compared by content (no ETag); changed files are written atomically (temp file
  + rename in the same folder), cert-watch reloads nginx. The app (user `portal`) needs write access
  to `/data/certs` (folder `root:portal 770`). Status, warnings and redacted errors in the admin UI and
  log; subject, SAN, issuer, expiry, fingerprint shown for both targets.
- No built-in ACME client in v1 (recommendation): keeps the image small; any external ACME client
  can write the files or serve the API. Revisit on demand.
- Router certificates for `login-by=https` (decided): one wildcard certificate (e.g.
  `*.guest.example.com`) obtained the same way; routers use names like `hotspot-a.guest.example.com`.
  The instance offers the cert to its routers via a token-protected download; the generated
  RouterOS script includes a scheduler entry that fetches and imports it periodically (decided).
  Implemented M3: files `/data/certs/router.crt` + `router.key`; `GET /router-cert/<router id>/
  {fingerprint|router.crt|router.key}` on the guest port, stateless (no session/cookies), with a
  per-router token in header `X-Portal-Token` (local, encrypted) and only from the router's NAS IP
  (fetch with `src-address`; also behind `TRUSTED_PROXIES`, off only with `PORTAL_ROUTER_IP_CHECK=false`,
  see §12); denied requests are logged. Key files (decided M6): all keys in
  `/data/certs` (`portal.key`, `router.key`, `selfsigned.key`, operator-provided or pulled) are set to
  `root:portal 640`, certificates `root:portal 644`, so the app can serve the router key, compare
  pulled files and include them in backups; legacy keys in `/certs` stay 600. The generated script is idempotent (removes its own entries named or
  commented `guest-portal` first, incl. hotspot profile `guest-portal`). The script (scheduler at startup + every `PORTAL_ROUTER_CERT_INTERVAL`,
  default 1d) compares the SHA-256 fingerprint and, when changed, imports the files and sets the
  hotspot profile `ssl-certificate` **(RouterOS script syntax to verify)**. Decision 2026-10-29
  (v0.12.1, chain import creates several certificates): compare with the certificate referenced by the
  profile (not by name, renaming is harmless); fingerprints normalised (lowercase hex, no colons);
  imported certificates get the temporary name `guest-portal-import*`, leaf = fingerprint match
  (fallback private key), CAs renamed `guest-portal-ca-<n>`; errors logged with the failing step
  (`:onerror`). Decision 2026-10-29 (v0.12.3): RouterOS certificates have no comment property, so
  certificates are identified by name only (`guest-portal`, `^guest-portal-ca-[0-9]+$`,
  `guest-portal-import*`, legacy `guest-portal-new`, `guest-portal-new_*`); nothing else is removed.
  Decision 2026-10-29 (v0.12.5, critical: earlier scripts removed all certificates because a local
  named `name` is not substituted inside `find where`): script locals never share a property name
  (prefix `gp`, enforced by a test); certificates removed only by id after explicit string checks on
  the name (no `find` filter for `/certificate remove`), never one referenced by a hotspot profile,
  abort with error log when more than 10 would be removed.
  Portal name pinned on the router as CNAME to `guest-portal-v4.internal` (static A): a static A entry
  alone lets AAAA queries go upstream, a public AAAA then breaks `/tool fetch src-address=<IPv4>`.
  Site setting `portal_address` must be IPv4, not the guest gateway, not inside the guest subnet.

## 7. Guest login

### 7.1 Common rules

- Entry: `login.html` redirect → `GET /portal?site=<id>&mac=..&ip=..&link-login-only=..&link-orig=..`.
  URL parameters are untrusted UX hints; the binding MAC is the one RADIUS reports.
- Access-ended notice (decision 2026-10-29, v0.12.1): when a device returns via the router redirect,
  the login page shows "session ended" (last session of the MAC at this site disconnected by an admin),
  "access blocked" (latest grant of the MAC for this site revoked) or "access expired", only within
  `PORTAL_ENDED_NOTICE_HOURS` (default 24, 0 = off), not for unknown MACs or when another usable grant
  is bound to the MAC. Reason only, no name or code; "sign in again" jumps to the normal login form.
  The MAC is a UX hint (spoofing reveals only one of three generic reasons).
  Decision 2026-11-05 (v0.29.0): fourth reason "logged out" («Du hast dich abgemeldet.»), checked first: the last
  session of the MAC at this site has `logged_out_at` (logout on the status page) or an accounting row with
  `Acct-Terminate-Cause` User-Request (logout on the router), within the same window; shown although the grant is
  still usable, like "session ended".
- Method chooser (decision 2026-11-03, v0.22.0): every method has a switch in the settings (code, which also
  covers the site code; SMS; approval; social login); a method is offered when its switch is on and its
  prerequisites are met (SMS provider, notification channel, a configured social provider; no approval and no
  social login in federation limited mode). More than one available method: `/portal` shows a chooser (plain
  links `?method=<m>`, no JS); exactly one: its page directly; a prefilled code opens the code page. Each
  method page has its own form and links back to the chooser; the access-ended and router-failed notices
  appear on the first page only. None available: error page. The server refuses a disabled method (403).
  Decision 2026-11-04 (v0.25.0): order of login methods and social providers is configurable (global,
  hub-only). Settings → "Guest login methods" and "Social login" have up/down arrow buttons per row; the
  order is stored in the existing replicated settings (`login_methods.order`, `social_login.order`, lists of
  keys) and applies to the chooser (the first available method keeps the primary button), the social box, the
  admin settings lists and the guest text preview. Default: the previous fixed order (code, account, SMS,
  approval, social; Google, Apple, Facebook, GitHub). Stored values are read leniently (unknown and duplicate
  entries dropped, missing keys appended in default order); form input must list every key exactly once.
  Without JS an arrow button submits the form with `move=<key>:<up|down>` and saves it; with JS the row moves
  in place and is saved with the form. The walled-garden host list keeps the fixed provider order (reordering
  does not change the router script). Audited in `settings.login_methods` / `settings.social` (`order`).
- Required for all methods: first name, last name, acceptance of current terms version.
  Decision 2026-11-03 (v0.24.1, email on the code login): the code page (normal codes and the site code) also
  requires an email address (`email:rfc`, max. 191 = column length; no DNS check, the guest has no internet yet),
  trimmed, lower-cased and stored in `guests.email`; the guest list shows it like the social login email (masked
  for managers); the data paragraph of the default terms names it. SMS and approval stay name only: the operator
  asked for the code login only.
  Decision 2026-11-03 (v0.22.0, default terms): every language has a formal default text in the lang
  files (`portal.terms.default`, neutral "operator of this guest Wi-Fi", 8 paragraphs incl. stored data).
  While nothing at all is published, guests see it marked "default text" and accept it with version
  null. A new portal publishes it as version 1 in every language with its first site (not earlier: a
  fresh instance with replicated rows can no longer join a federation; never after a join); the admin
  terms page has "Publish default texts in all languages" (only languages without a version) and loads
  the default text into a language's form on request. Guests should never accept a blank statement.
- Languages DE, IT, FR, EN (Laravel lang files); auto-detect via `Accept-Language`, switcher.
  Decision 2026-11-03 (v0.22.1, admin language): the admin UI has the same switcher (login page and
  header). Guest and admin sessions are separate cookies, so each zone has its own route (`/lang/{locale}`,
  `/admin/lang/{locale}`) storing the locale in its own session; no per-user language column.
- After success: one-time RADIUS credentials → auto-submit to router → status page (implemented M3:
  router `dst` = signed portal URL valid until grant expiry + 1 h, no cookie needed; remaining time, waiting page until RADIUS
  accepted the token). `link-orig` is not used. Friendly error pages: no hotspot redirect,
  misconfigured hotspot host / unknown site, expired, revoked, lockout, router login not completed.
  Decision 2026-11-05 (v0.29.0, status page with usage and logout): the signed status page also shows the usage of the
  open session of the login's device at its site (`session_summaries` with `stopped_at` and `logged_out_at` null,
  newest): connected for (`started_at`, as duration), data down/up (sum of `bytes_out`/`bytes_in` of its accounting
  rows, NAS view, binary units KiB/MiB/GiB like RouterOS), "as of" the newest `last_update_at` with the hint that the
  router reports every `portal.radius.interim_interval` s; nothing without an open session (right after the handoff
  the row appears with the accounting Start). A "Refresh" link (plain reload) on every site; controller sites keep
  their own block. "Log out" posts to the signed route `guest.logout` (`/status/{grant}/{token}/logout`, same
  signature as the status page, CSRF like every guest POST, 10/min): it ends the session, not the grant. Every open
  session of the grant at the token's MAC (all sites) gets `session_summaries.logged_out_at` (local column like
  `kicked_at`; session summaries are not replicated, the remote session list of the federation carries it) and a
  `DisconnectSession` job (Disconnect-Request, or the controller); `Log::info('Guest logged out')`. The code stays
  usable on the same device until it expires (single-device rule and device binding unchanged); roaming MAC
  authentication at that site stays off until the grant changes, as after an admin disconnect (§10.4), else the
  router's MAC re-authentication would log the device in again at once. The page `guest.logged-out` says the
  connection ends within seconds (NAS without Disconnect support, `ReportsDisconnectSupport` false: at the session
  time of the login) and links "Sign in again" to `/portal?site=..&mac=..&ip=..&link-login-only=<the NAS login URL>`
  (driver `ServesHotspotPages::hotspotLoginUrl()`, MikroTik `https://<first hotspot host>/login`; other drivers have
  none and show no button: their NAS redirects by itself). Reloading the status page after the logout shows that
  page again. The admin guest list marks such sessions "logged out".
  Decision 2026-11-05 (v0.29.0, target of the router's status.html): `GET /portal/status?site=<slug>&mac=..&ip=..`
  (`guest.status.current`, 30/min) resolves the site and the device like `/portal` (driver `parseRedirect`), takes the
  newest open, not logged-out session of this MAC at the site (the session IP must match the `ip` parameter when
  both are known), the newest used RADIUS token of that grant with that MAC at the site, and redirects to the signed
  status page; otherwise to `/portal` with the same query (login page). The MAC is a UX hint as on `/portal`: a
  device on the guest network that sends another guest's MAC and IP reaches that guest's status page and can log
  it out (not its code, the grant stays usable). Accepted: both values are visible on the guest network anyway,
  client isolation of the setup assistant limits it, and the effect is a re-login.
- Guest pages are plain server-rendered HTML (no Livewire); only the handoff auto-submit uses inline
  JS, with a button as fallback.

### 7.2 Sequences

**A. Code (6 digits, admin/manager-issued)**
1. Guest enters name and email address, accepts terms (current version of the guest's locale, fallback default locale;
   the submitted version must still be current), enters code. The acceptance records guest, terms
   version, grant, site, MAC and time. Without any published terms the default text of the lang files
   is shown and accepted (version null, §7.1).
2. Instance checks rate limits, code status, validity window, site restriction.
3. If the code is bound to another MAC with an active session: Disconnect-Request for the old
   session (via its serving instance, §10.4), rebind code to the new MAC.
4. On first redemption with `expiry_mode=first_login`: `expires_at = now + duration`.
5. Issue one-time RADIUS token → auto-submit → Access-Request → Accept with Session-Timeout.

**B. SMS OTP (provider drivers, one active provider with backups)**
1. Guest enters name, phone number, accepts terms.
2. Number normalised to E.164 and checked against the country allowlist (default CH, DE, AT, IT,
   FR; configurable). Rate limit per phone/MAC/IP; OTP created (10 min, max 3 tries); SMS queued.
   Implemented (M5): only an HMAC of challenge id + 6-digit code is stored (key `APP_KEY`), single use; max. 3
   sends per request (first SMS + resends, 60 s apart; a resend replaces the code, tries are not
   reset). Cost caps on sent SMS (setting, defaults): per number 3/h and 6/d, per device (MAC)
   3/h and 6/d, per site 30/h and 200/d, per instance 500/d; checked and recorded under one lock.
   Over a cap nothing is stored and the guest sees an error page; site/instance cap hits are logged
   as warnings. Resend and verify share the send lock (a code cannot be replaced while checked); a
   busy lock shows the same error page. Known limit: exhausting a cap (SMS pumping, or flooding a
   victim's number) blocks SMS login for that number/site/instance until the window passes; accepted,
   no captcha. Phone numbers are masked in logs and on the code page; provider errors are logged
   with the HTTP status only. Sending: queued job (encrypted payload, `QUEUE_CONNECTION=sync` not
   supported), first enabled SMS provider by name (until v0.25; see the v0.26.0 decision below).
   Decision 2026-11-04 (v0.26.0, one active SMS provider with ordered backups and failover): SMS channels have a
   role (`primary` | `backup`) and a position, stored in the replicated, encrypted channel `config` under the
   reserved keys `_sms_role` and `_sms_order` (`App\Sms\SmsProviders`). Chosen over new columns: no migration,
   no change to replication or the applier, older instances ignore the keys (a save there drops them; the
   channel then sorts last). `enabled` stays the master switch ("off"). Exactly one active provider: «Aktiv
   setzen» enables the channel and makes it primary, the previous primary becomes the first backup; «Nach
   oben/unten» moves backups (the active provider stays first). Lists from before 0.26.0 are read as before:
   the first enabled channel by name is active, the others follow by id (creation order); the keys are written
   at the first change of the list (also when a provider is added, which is appended as last backup). Free
   Mobile reaches only the subscriber's own number: test button only, never sends guest codes and does not
   enable the SMS login (`SmsDrivers::TEST_ONLY`).
   Failover (`App\Sms\SmsSender`, used by the `SendSms` job): enabled channels in list order; channels in
   cooldown are skipped and tried last, only when all others failed. A provider failure (connection error,
   timeout, redirect, HTTP 5xx, 429, 401/403, credit, any other exception, provider error codes not listed as
   recipient errors) puts the channel into cooldown (`sms_failover_cooldown_s`, replicated setting, default
   300 s, 60-3600) and the next channel is tried at once. A rejection of the message itself
   (`App\Sms\SmsFailure::$recipient`: provider codes for an invalid or blocked number, e.g. Twilio 21211,
   seven.io 202, SMSC 7/8, SerwerSMS 3103; or HTTP 400/422 without a code the driver knows) stops: the backups
   would refuse it as well, and the provider is not put into cooldown. Ambiguous codes (e.g. SMS Partner 9,
   "a constraint") count as provider failure. Delivery by a backup while the active provider is in cooldown
   starts an outage: `Log::warning('SMS failover')`, audit `sms.failover` {primary, used} and one message on
   every enabled notification channel (their test-message path, notification language), each once per outage;
   the settings page shows «zuletzt fehlgeschlagen <time>, Reserve aktiv» and the cooldown per provider. The
   next delivery by the active provider ends it (`Log::info`, audit `sms.recovered`, banner gone). When no
   provider delivers, the SMS is recorded as failed (last error) and the challenge is closed (expires now,
   no try used up), so the code page shows the `sms_failed` error page (text extended by "could not be
   sent") and the guest can start again. The cost caps count all SMS regardless of provider, as before.
   Failure and outage state are instance-local (cache), never replicated: each instance fails over and
   reports on its own; roles and order replicate with the channel.
   Decision 2026-11-02 (v0.19.2, SMS text for autofill): the text uses the domain-bound one-time code
   format (WebKit origin-bound codes, WHATWG sms-one-time-codes, Chrome WebOTP) so iOS/Android offer the code
   for the portal page: text (see v0.20.1 below), a blank line, last
   line `@<host> #<code>` (`OtpService::text()`). Host = host of the guest request that triggered the send
   (`Request::getHost()` of the start/resend request, the page the code is entered on; not APP_URL, since the
   guest zone may be reached under other names); lower case, no scheme, no port; invalid host (not a DNS name)
   or none: the last line is omitted. Decision 2026-11-02 (v0.21.0, security review): the request host is used
   only when it is one of the portal's own names (APP_URL host, `PORTAL_HOSTNAME`, host of the site's portal
   URL, the site's hotspot host names), otherwise the line is omitted, so a forged Host header cannot put a
   foreign domain into an SMS. The guest page `/portal/sms` additionally calls WebOTP
   (`navigator.credentials.get({otp: {transport: ['sms']}})`, feature-detected, aborted on leaving, errors
   ignored) and fills the input; the script is inline (allowed by the guest CSP, §12). No length limit exists in the SMS
   code; the text stays short (about 100 characters, one GSM-7 segment for typical hosts).
   Decision 2026-11-02 (v0.20.0, own SMS text): the whole text is editable per locale in the admin settings
   (replicated setting `sms_text`, locale => template, max. 300 characters, `:code` required in a line without `:host`); placeholders
   `:code`, `:minutes`, `:host`. The language default (lang `portal.sms.text`) is the format above including the
   line `@:host #:code`; only texts differing from the default are stored, an empty field resets to it. Without
   a valid host every template line containing `:host` is omitted. The admin is responsible for keeping the
   autofill line in an own text.
   Decision 2026-11-02 (v0.20.1, text for iOS code detection, tested on iOS): iOS only offers/copies the code
   when it directly follows the word "code" (IT "codice") and a colon, e.g. `Your code: 123456`; text between
   the word and the colon (e.g. "Wi-Fi") or after the code on the same line, or the code first, prevents it.
   Defaults: line 1 `Your code: :code` (DE `Dein Code:`, FR `Votre code :`, IT `Il tuo codice:`), line 2
   guest Wi-Fi and validity, blank line, `@:host #:code`. An own text is rejected unless a line without
   `:host` contains `:code` preceded by the word "code" or "codice".
3. Guest enters OTP; a grant is created with the configured SMS duration (setting, default 1 d),
   time running from login.
4. Step 5 of A.

**C. Push approval (Telegram / Gotify / webhook, driver interface)**
1. Guest enters name (optional message), accepts terms, requests access.
2. Instance creates `approval_request` (pending, expires e.g. after 15 min) and notifies all
   enabled channels (only this instance sends, §10.5).
3. Approver approves/denies and chooses duration:
   - Telegram: message in the configured **group** with inline buttons (durations + deny).
     A callback is accepted only if `callback_query.message.chat.id` equals the configured group
     and, if an allowlist of Telegram user ids is set, `callback_query.from.id` is in it.
     Updates via long polling `getUpdates` (s6 service `telegram`, `artisan portal:telegram-poll`);
     webhook mode not implemented (optional, later). After a decision the message is edited
     (decision, approver, duration; buttons removed).
   - Gotify / generic webhook: signed, time-limited approve/deny links to a confirmation page
     (duration picker) on the admin port (base URL: setting "admin URL"). Links expire with the
     request and are single use (request no longer pending); GET only shows, POST decides (link
     previews cannot approve). The page runs without login and without session (an admin session
     cookie is not replaced).
   - Admin UI: admin/manager approve (8 h / 1 d / 1 w / custom hours) or deny.
   - Notification language: setting "notification language". Decisions go to the app log
     (audit table: M6).
4. Guest waiting page reloads every 3 s (meta refresh, no JS/Livewire); on approval a grant
   (type approval, restricted to the request's site) is created, time running from approval.
   Denied/expired (15 min) requests show an error page. Max. 3 requests per device per 15 min and max.
   10 open requests per site (`portal.approval.max_pending_per_site`); a pending request of the same
   device is reused.
5. Step 5 of A, triggered automatically from the waiting page.

**D. Social login (Google, Apple, Facebook, GitHub; decision 2026-11-03, v0.22.0)**
1. Guest accepts the terms on the social page and picks a provider (one form button per configured
   provider). `POST /portal/social/{provider}` checks terms version, provider, hotspot context and lockout,
   stores the attempt in the session (state, nonce, PKCE verifier, redirect URI, terms version, site, MAC;
   single use, 10 min) and redirects to `GET .../go`, which answers the 302 to the provider: the hop to a
   foreign origin is never the direct answer of the form POST (Chrome applies the CSP `form-action` to such
   redirects; kept although the guest CSP allows `https:`).
2. The redirect URI is the request origin plus `/portal/social/{provider}/callback`, only when that origin is
   APP_URL or a site portal URL (otherwise "misconfigured", warning logged): the callback must reach the host
   the session cookie lives on, and every origin must be registered at the provider.
3. Providers via laravel/socialite (stateless; state, nonce and PKCE are the portal's own): Google
   (`openid email profile`, PKCE, nonce checked in the ID token of the token response), Facebook (`email
   public_profile`, fields id, first/last name, email), GitHub (`read:user user:email`, PKCE, name split at
   the first space, login as fallback first name), Apple (own provider: `name email`, `response_mode=
   form_post`, nonce, client secret = ES256 JWT of the .p8 key; ID token verified against Apple's JWKS,
   cached 1 h: RS256 signature, iss, aud, exp, nonce; name from the `user` JSON of the first authorisation).
   Outbound requests as in §12 (TLS verified, 10 s, no redirects). Provider errors and failed checks show a
   generic error page; the log gets the reason without tokens or codes.
4. Apple posts the callback cross-site (`form_post`), without the SameSite=Lax session cookie: that POST
   route runs without session and CSRF and answers a page that forwards code, state and `user` encrypted
   with the instance key (valid 5 min) to the GET callback as a same-site navigation (meta refresh).
5. Decision 2026-11-05 (v0.29.2, owner): social login never asks for a name. The name is stored as far as
   the provider reports it (Facebook first/last name need `public_profile`; Apple sends it only at the first
   authorisation and only when the guest shares it; GitHub falls back to the login), otherwise first and last
   name stay empty (the columns stay NOT NULL, empty strings, so older federation instances read the rows) and
   the login continues to step 6 without interruption. The former "complete your name" page, its routes
   (`/portal/social/name`, now 404) and its texts (`guest.social_name_*`, preview screen `social_name`) are
   removed. Display: `Guest::displayName()` gives "First Last" (guest list "Last, First"), without a name
   the email (masked for managers like the email line), else "<Provider> #<6 hex>" (first 6 characters of
   SHA-256 of provider and subject, never the raw provider ID). It is used in the guest list, the devices of a
   grant, the MAC whitelist comment prefill, the SMS grant label and the approval texts (Telegram, Gotify,
   approval link page, admin approvals); the approval webhook keeps `guest.first_name` / `last_name` and adds
   `guest.display_name`. Other login methods keep their required name fields.
6. Like the SMS verify path: guest (names as far as known, locale, provider, provider subject, email), terms acceptance (the
   version accepted at the start must still be current, otherwise back to the social page), grant type
   `social` restricted to the site with the duration from the settings, time from login, label = provider;
   recorded as login attempt `social`. Each login gets a new grant (the same device too). Then step 5 of A.
   Guest list: method "Social (provider)", email in full for admins, masked for managers.
7. Walled garden: the router script adds the sign-in hosts of the configured providers (§13; list in
   `App\Auth\Social\WalledGarden`, docs/guest-network-setup.md 6.6); limited mode does not change it.

**E. Directory account (Active Directory / LDAP; decision 2026-11-03, v0.24.0)**
1. Per site an optional directory (site setting `directory`, site page, admin only): server, port (636),
   TLS `ldaps` (default), `starttls` or `none` (warning), certificate check (default on; system store, or a
   CA certificate of the site in PEM, which then replaces it), base DN, service account (bind DN and password,
   encrypted with the federation data key, §10.2; empty bind DN = anonymous search), user filter (default
   `(&(objectClass=user)(sAMAccountName={username}))`, `{username}` escaped per RFC 4515), optional group DN
   (direct `memberOf`, compared case-insensitively; nested groups via the AD matching rule in the filter),
   name attributes (default `displayName`, "Last, First" or split at the last space; fallback `givenName` +
   `sn`), email attribute (`mail`), timeout (5 s), access duration (empty = default of the login method
   settings, 1 d; 5 min to 30 d). Method `account` is offered at a site when its switch is on, the site's
   directory is configured (enabled, server, base DN, readable password) and not in limited mode.
2. Guest page: user name, password, terms. `POST /portal/account` (5/min per IP) checks method, site and
   terms version; lockout per MAC, IP and global like the other methods, plus at most
   `PORTAL_LOGIN_MAX_PER_ACCOUNT` (5) attempts per site and user name within the login window, counted before
   the bind (parallel guesses cannot exceed it; cleared on success), so guessing through the portal stays
   below a typical directory lockout threshold.
3. The portal binds with the service account, searches the user (size limit 2: none = wrong credentials, two =
   refused), binds as the found DN with the guest's password (an empty password, an unauthenticated bind, and
   NUL bytes are refused before), then checks the group (only after a successful bind). The password goes only
   to the directory: never stored, logged, audited or flashed back. Wrong user name or password: one generic
   message; AD data codes 530/531/532/533/701/773/775 (disabled, locked, expired): "locked or expired"; not in
   the group: "not allowed"; directory unreachable, TLS error, service account rejected or search failed:
   error page "unavailable" (503, not counted as a failed attempt). The directory is asked outside the global
   login lock.
4. Success like D step 6: guest (names, email), terms acceptance, grant type `account` restricted to the site
   (label = site name, duration of the site or the default, time from login), login attempt `account`, audit
   `guest.account_login` with site and user name only. Guest list: "User account (site)".
5. LDAP client behind `App\Auth\Directory\DirectoryClient` (ext/ldap; tests use a fake): LDAPv3, no
   referrals, network, operation and search time limit = timeout. ext/ldap cannot give a connection its own
   TLS context, so all TLS options (certificate check, CA file and directory) are set globally for the PHP
   worker right before each connect. Check button on the site page (10/min): service bind and the user search
   with a made-up name; ok, incomplete, unreachable, TLS error, service account rejected, search failed or
   ambiguous filter; audited `site.directory_checked`.
6. Federation: `directory` is a security site setting (`Federation::SECURITY_SITE_SETTINGS`, it decides where
   guest passwords go), the default duration (`account_login`) a hub-only security setting, the password is
   re-encrypted after a key rotation. No walled-garden change: the check runs on the portal.
Decision 2026-11-03 (v0.24.0): a guest may sign in with the user name and password of the site's directory
(e.g. staff), verified by a bind of the portal instead of a stored hash, so the portal keeps no passwords and a
disabled account stops working at once; configured per site since each site has its own directory, and not
offered in limited mode, like social login. Instances older than 0.24.0 cannot read grants of type `account`:
every instance of a federation must run 0.24.0 or later before the method is switched on.

### 7.3 Drivers

| Interface | Drivers (initial) | Notes |
|---|---|---|
| `SmsDriver` | Twilio, seven.io, ClickSend, Clickatell, SMSAPI, 46elks, Cellsynt, Octopush, SMS Partner, SMS Planet, PromoSMS, SerwerSMS, SMSC, SmsManager, GTX Messaging, ASPSMS, eCall, SMSEagle, Free Mobile (test only), generic SMS webhook | Outbound only, one class per provider in `app/Sms/Drivers` on `HttpSmsDriver` (OutboundHttp, 15 s, errors as HTTP status or provider code only, `SmsFailure` with the recipient flag for the failover, §7.2 B). Selection (decision 2026-11-04, v0.26.0): the SMS providers of Uptime Kuma with a plain HTTPS API, plus ASPSMS and eCall (Switzerland); not taken: providers with time-based request signing and SDKs (Aliyun, also Huawei and similar), chat/push services (WhatsApp, Signal, Telegram etc. are not SMS), regional ones outside the scope (SMS.ir, AmootSMS, EgoSMS, BearSMS, Ooredoo Maldives), device gateways that need a reachable phone or router (Android SMS gateway, Teltonika); Plivo and Telnyx would fit and can follow. Each driver's docblock cites the provider docs it was verified against (2026-11-04) and names the unverified points (notably PromoSMS: no public docs, format from Uptime Kuma's driver; Cellsynt: parameter details from the PDF manual; Free Mobile: community sources). Numbers in the provider's notation (E.164 with or without "+", Cellsynt 00-prefix), text UTF-8, unicode flag where the provider needs one (GSM 03.38 check). Clickatell: API key in `Authorization` header, endpoint configurable; request/response format **(to verify)** with a real account. SMS webhook: JSON `{event, to, text}` signed like the notification webhook. |
| `NotificationChannel` | Telegram, Gotify, generic webhook | Webhook: JSON + `X-Signature` HMAC-SHA256, timestamp header against replay. |

## 8. Codes / access grants

All login methods produce a **grant**; a code is a grant created in advance.

| Field | Notes |
|---|---|
| Duration | Presets 8 h, 1 d, 1 w; special codes any duration. |
| Expiry mode | Codes: `first_login` (default) or `creation`, per grant. SMS: from login. Approval: from approval. Wall-clock. |
| Validity window | `valid_from`, `redeem_until`. |
| Sites | All or selected sites (federation-wide site list). |
| Label / note | Free text. |
| Extensions | Optional max number of extensions; extensions only by admin/manager, never by the guest. |
| Rate limit | Optional bandwidth limit (`Mikrotik-Rate-Limit`), default none. |

- All fields stay editable after redemption: extend, shorten, revoke, change expiry. Shortening
  below zero remaining or revoking triggers Disconnect-Request.
- 1 grant = 1 active device (MAC). New MAC with same code → old session disconnected, rebind.
  No IP binding.
- Vouchers (implemented M6): 1-100 codes per batch with the same settings (one audit entry
  `grant.bulk_created` with count and settings, never codes). Print view: A4 sheet, 2 x 5 cards
  (code large, label, duration, expiry/redeem-until, SSID). **QR decision:** the QR is a Wi-Fi join
  QR (`WIFI:S:<ssid>;T:nopass;;`, special characters escaped, an all-hex SSID quoted; guest SSID from the site's setup assistant input), rendered as
  inline SVG (bacon-qr-code), no external service. Shown only when the code's sites (all sites when
  unrestricted) have exactly one distinct SSID; otherwise the card shows the code only. Decision
  2026-10-31: with the optional guest Wi-Fi password of the setup assistant the QR is
  `WIFI:T:WPA;S:<ssid>;P:<password>;;` (same escaping) and the card prints the password as text; the
  network must be unambiguous as SSID plus password (same SSID with different passwords: no QR). No portal URL /
  `?code=` pre-fill in the QR: a URL opened before joining the guest network cannot reach the portal,
  after joining the hotspot redirect replaces it (the original URL only survives in `link-orig`, which
  is unused by design), so the guest types the printed code. CSV export (admin only; code, label,
  status, duration, expiry mode, validity, sites, created; formula prefixes neutralised; audited
  `grant.exported` with count).
- Decision 2026-11-03 (v0.22.0, list and deletion): the codes list shows current grants (pending,
  unused, active) by default, every status and "all" via the filter; ended grants (lapsed, expired,
  revoked) can be deleted, singly or among a selection (usable ones are refused/skipped), as a soft
  delete = federation tombstone (devices, tokens and sessions go with the retention purge; audited
  `grant.deleted` / `grant.bulk_deleted`, never the code). Printed voucher batches otherwise clutter the
  list for good; site codes stay deactivate-only, their history keeps the login counts.
- **Site code** (decision 2026-10-29, v0.12.0): per site at most one active shared 6-digit code for any
  number of devices (`site_codes` R, site-scoped: valid only at its own site). Fields: duration per
  device (presets or custom hours), optional `valid_until` (no new logins afterwards), optional max.
  concurrent devices (empty = unlimited; when reached the guest sees "currently full, use SMS login"),
  active flag, created/deactivated at/by. Generating a new code deactivates the previous one (rows kept
  as history with login counts); no reactivation. Unique among usable grant codes and active site codes;
  the guest uses the normal code field (normal code is looked up first). Login: same form (name +
  terms); each device gets its own grant (type `site_code`, `site_code_id`, code copied, restricted to
  the site, time from its login), so Session-Timeout, accounting, guest list, extend/revoke and
  disconnect work per device; the single-device rule does not apply. The same MAC with a running grant of
  the code reuses it (no second slot, time not restarted). Running device grants count against the
  limit. **(Assumption)** deactivation and expiry do not end running devices; "disconnect all devices"
  revokes the running grants of a code (Disconnect-Request). Admin + manager; audited
  `site_code.created/updated/deactivated/disconnect_all/printed` (never the code). Notice (A4/A6, light):
  logo, site name, text DE/IT/FR/EN, one QR: Wi-Fi join (site SSID); the portal prefills the code from
  `?code=` or from a portal URL with `?code=` in `link-orig` (prefill only, no auto-submit). Decision 2026-11-02 (v0.20.3,
  maintainer): the second QR (sign-in via `https://<hotspot host>/login?dst=<portal URL>?code=`) is
  removed, it worked poorly or not at all in tests; the captive portal opens after joining. M7 hook:
  `isAllowedWhileOffline()` (true for site codes/their grants, false for normal codes) for the planned
  "hub unreachable → SMS only" rule; no offline logic yet.
- Brute force: rate limit per MAC and IP, global rate limit, lockout after N failures
  (configurable, e.g. 5 per 15 min per MAC), OTP 10 min / 3 tries. Trivial codes excluded.
  Route throttles (decision 2026-10-29, v0.12.2): every `throttle:` middleware has its own key prefix,
  otherwise all throttled routes share one counter per IP (page assets used up the 5/min of the approval
  request).
- Error pages (decision 2026-10-29, v0.12.2): HTTP errors render a translated page in the guest or admin
  look by zone (`resources/views/errors`, `gp-error-page`), Retry-After shown for 429, never exception
  details; 5xx use the built-in look without database access. Internal and federation zones keep JSON.

## 9. Admin

- Admin UI on its **own port** (default 8443), guest portal on its own port (443): firewall rules
  decide reachability. Access via VPN or reverse proxy; behind a proxy set `TRUSTED_PROXIES` to its
  address (§12).
- 2FA (TOTP) strongly recommended, enforceable by setting (implemented M6: enrolment in the own
  profile with QR code, RFC 6238 ±1 step, each step accepted once, 8 hashed single-use recovery codes,
  disable with password + code; login asks for the code after the password (5 failures: start over
  with the password; per-IP limit; since v0.21.0 also 10 failures per user in 15 minutes across IPs and
  restarts), no "remember me"; password or 2FA changes and deletion end the
  user's other sessions and rotate the remember token; failed-login audit rows capped per hour; setting
  `require_2fa` applies to all admin UI users (both roles) and sends users without 2FA to their profile;
  `artisan portal:admin --reset-2fa <email>` and the user page reset it).
- **OIDC login** (v0.9.0, admin port only; decision 2026-10-29): optional, e.g. Authentik. Settings
  (admin only, replicated): enabled, button label, issuer URL (https), client ID, client secret
  (encrypted, masked, never logged/audited), scopes (default `openid profile email groups`), groups
  claim (default `groups`), group names for admin/manager (default `portal-admin`/`portal-manager`),
  portal 2FA mode, ACR value, max. auth age (optional), link by email (default on); a secret that
  cannot be decrypted is logged (without value) and flagged in the settings. Redirect URI `<admin URL>/admin/oidc/callback` (admin URL setting,
  fallback `APP_URL` with `ADMIN_PORT`, default 8443, warning in the settings while empty; v0.10.0), shown in the settings. Flow: discovery (`<issuer>/.well-known/openid-configuration`,
  cached 1 h, issuer must match; saving the settings clears discovery and JWKS cache), Authorization Code with PKCE (S256), state + nonce + verifier in the
  session (10 min, single use), token request with `client_secret_basic` (or `_post` if only that is
  offered); ID token checked with firebase/php-jwt: RS256/ES256 only, key from JWKS (cached 1 h, one
  refetch per minute on unknown `kid`), `iss`, `aud`/`azp` = client ID, `exp`/`iat` (60 s leeway),
  `nonce`, `sub`. Userinfo (sub must match) fills email/`email_verified`/groups when missing in the ID token. Non-string
  query parameters (code, state, error) fail with the generic error. HTTPS only,
  TLS verified, 10 s timeout, no redirects followed; redirect 10/min, callback 20/min per IP.
  Decision 2026-11-02 (v0.21.0): the admin session cookie stays `SameSite=Strict`, so the browser does not
  send it on the identity provider's cross-site redirect; a callback without the cookie and without
  `bounce=1` answers (before the session starts, so no new cookie replaces the old one) a minimal page that
  reloads the same URL plus `bounce=1` by meta refresh; that navigation is same-site and carries the
  cookie; without the flow after the bounce the login fails as before. Not yet verified with a real IdP.
  Users: matched by `oidc_subject` (`<iss> <sub>`); first login links an existing user by email only
  when linking is enabled and `email_verified` is true (otherwise denied; audited `user.oidc_linked`;
  a linked user with TOTP always enters the portal code on that linking login, whatever the 2FA mode),
  else creates the user (email claim with `email_verified` true required; random unusable password,
  local password login only after an admin sets one). Role from the groups
  (admin group wins), re-synced on every login (audited `user.role_synced`); a role change ends the user's other sessions (the new one stays); no matching
  group: denied and the linked user's sessions end;
  demoting the last admin: denied; soft-deleted users: denied (stay deleted). Portal 2FA after OIDC:
  `skip_if_idp_mfa` (default: skipped when `amr` contains mfa/otp/hwk/swk/sc/fpt/face/iris/retina/vbm
  (not sms/tel) or `acr` equals the configured value; with max. auth age set, `auth_time` must be at
  most that old (60 s leeway), missing `auth_time` counts as no MFA), `always` (code step if the user has TOTP), `never`; the code
  step is the one of the password login; "require 2FA" still sends users without TOTP to their profile.
  Success: session regenerated, audited `auth.login` (method `oidc` / `oidc_two_factor`, `idp_mfa`).
  Errors: generic message, details redacted to the app log, audited `auth.oidc_failed` with a reason
  code (capped per hour). The password login stays as emergency access (always available). Deactivating a user or
  removing groups at the provider takes effect at the next OIDC login or when the session expires. Authentik
  `amr`/`acr` and `email_verified` values **(to verify)**.
- **Login methods and social login settings** (decision 2026-11-03, v0.22.0; admin only, hub-only
  in a federation via `Federation::SECURITY_SETTINGS`): Settings → "Guest login methods" has one switch
  per method (code, SMS, push approval, social; at least one stays on, audited `settings.login_methods`)
  and up/down arrows for the display order (decision 2026-11-04, v0.25.0, §7.1; same for the providers);
  a switched-on method whose prerequisite is missing (enabled SMS provider, enabled notification channel,
  a ready social provider, hub reachable) shows a hint, because guests are only offered available
  methods. Settings → "Social login": access duration (hours, from login) and one card per provider
  (Google, Apple, Facebook, GitHub): enabled, client ID (Apple: services ID), secret (Apple: the `.p8`
  private key, validated as EC P-256; plus team ID and key ID, 10 characters each), status (secret
  stored / unreadable / none, ready or what is missing, warning when ready but the social switch is
  off), the redirect URIs to register and a translated setup guide. Secrets are encrypted with the
  federation data key (§10.2), masked, never logged, audited (`settings.social` records only
  `credential_changed` per provider) or flashed back as old input; an empty field keeps, "remove"
  deletes. Redirect URI rule: one `<origin>/portal/social/<provider>/callback` per guest origin (APP_URL
  and each site's portal URL), since the callback must return to the host that holds the guest session.
  A GitHub OAuth app allows only one callback URL and the portal keeps one client per provider, so with
  several guest origins GitHub login works only on the first origin (APP_URL); the settings say so.
  Check button per provider (`App\Auth\Social\ProviderCheck`, 10 s timeout, TLS verified, no
  redirects, 10 per minute): it sends the provider's token request with the made-up code `portal-check`
  (Facebook: an app access token request); providers check the client credentials before the code, so
  "invalid client" means rejected credentials and "invalid code" means accepted ones (`ok`); missing
  data gives `incomplete` without a request, transport errors or other answers `unreachable` with the
  HTTP status. Apple's client secret is an ES256 JWT built per request (`AppleClientSecret`, 5 minutes).
  Only provider, outcome and HTTP status are logged; audited `settings.social_checked`.
- First-start web setup: first admin (one-time setup token in container log), URLs, TLS source,
  "create federation" or "join federation".
  Until it exists (and later as fallback), `artisan portal:admin` creates an admin or manager
  from the container shell.

Roles:

| Role | UI label (DE) | Permissions |
|---|---|---|
| `admin` | Admin | Everything. |
| `manager` | Verwaltung | Create/print/extend/shorten/revoke codes and grants, site codes, approve requests, view sessions and guests (phone numbers masked), MAC whitelist. No settings, sites, setup, terms, channels, users, audit log, federation. |

User management (admin only, M6): create/edit/delete users, password min. 12 characters; nobody can
delete or demote themselves, the last admin cannot be removed.

Features:

| Area | Content |
|---|---|
| Sites | Name, identifier, NAS driver, home instance, routers (NAS IP, NAS-Identifier, RADIUS secret per router, CoA port), enabled login methods; directory for the account login (v0.24.0, §7.2 E: server, TLS, service account, filter, group, duration, check button); for the UniFi driver (v0.27.0, experimental, §5.1) the controller (URL, API key or local account, UniFi site, TLS pin, check button) instead of routers, and a controller checklist instead of the setup assistant's RouterOS blocks; for the generic RADIUS captive-portal driver (v0.28.0, experimental, §5.1) the mapping section (preset, redirect, handoff, RADIUS, login page, "Simulate redirect"), routers without the RouterOS API, and a device checklist instead of the RouterOS blocks. |
| Codes | Create single/bulk (1-100), print vouchers (A4 sheet, Wi-Fi join QR + code, §8), CSV export (admin only), edit, revoke, delete ended codes (list shows current codes by default). |
| Guests & sessions | Implemented M6 (admin + manager), one page: guests newest first (each login creates a guest row) with name, phone (masked for managers), login method (grant type; without grant: approval request or SMS challenge), site and accepted terms version + time of the latest acceptance, created; its grant (code/status/remaining, extend and revoke via the grant routes) and devices (MAC, online = open session summary of grant + MAC, IP, start/end, traffic from local accounting `bytes_in`/`bytes_out`). An open session without accounting update for `PORTAL_RADIUS_STALE_AFTER` (default 3 x interim interval = 900 s) is shown as "stale" (Stop probably lost; display only, data unchanged). Disconnect: Disconnect-Request job for an open session served by this instance (sessions of other instances: M7); the grant stays valid, audited `grant.disconnected`. Filters: site, method, running grants only, name (LIKE wildcards literal) or exact code; invalid filter values are ignored; 25 per page. Extend/shorten takes whole hours; a revoked grant cannot be extended (422). No new fields. |
| MAC whitelist | Implemented v0.8.0 (admin + manager, §5): list with filters (MAC/comment search, site, status active/inactive/expired), online state, create/edit/delete, activate/deactivate; guests list: per device "allow this device permanently" (link carries the device id only; the form is prefilled server-side with MAC, guest name as comment, site of the latest session or acceptance) or a "Whitelist" badge. Whitelist sessions are shown on the whitelist page, not in the guest list (no guest). Audited `whitelist.created/updated/activated/deactivated/deleted` (no comment text). |
| Approvals | Pending requests (approve/deny in UI as well). |
| Channels | Notification channels and SMS providers (v0.26.0: provider list with badges «Aktiv» / «Reserve n» / «Aus», buttons «Aktiv setzen» and «Nach oben/unten», outage banner, help line per driver; §7.2 B), test button (SMS: test SMS to a number; notification channels: test message, implemented M6; errors shown as HTTP status only, audited). |
| Login methods | Decision 2026-11-03 (v0.22.0, admin only, Settings): switch per guest login method with prerequisite hints; social login providers (Google, Apple, Facebook, GitHub) with encrypted secrets, redirect URIs per guest origin, setup guide and check button (see the bullet above); switch and default access duration of the account login (v0.24.0, directory per site, §7.2 E). |
| Terms | Text per language, versioned; new version requires re-acceptance on next login; default texts in all languages (shown until published, publishable by button). |
| Guest texts | Decision 2026-11-03 (v0.23.0, admin only, System → Guest texts, `/admin/texts`): labels and texts of the guest pages editable per language, with a live preview. Storage: replicated setting `guest_texts` (key → locale → text), not a security setting, so any instance may edit it (like `sms_text`); global for all sites (site-specific texts are out of scope). Only an allowlist of lang keys (`App\Guest\GuestTexts::KEYS`: titles, intros, method buttons, form labels, SMS/approval/social/status texts, notices, error pages) is editable; plain text, one line, max. 500 characters; empty or equal to the default = default (only differences are stored); every placeholder of the default (`:provider`, `:phone`, `:minutes`, `:version`) must stay. Saving is per language and audited (`settings.guest_texts` with locale and changed keys). Applied by the middleware `guest.texts` of the guest routes only (`Lang::addLines` for the guest's locale, undone after the response); admin pages, voucher prints, SMS and notification texts keep their own texts. Preview: an iframe on the admin page loads `/admin/texts/preview` (admin listener, admin only), which renders the real guest views with demo data (`App\Guest\GuestPreview`: first site or the selected one for branding, demo phone and remaining time, current terms) and never creates sessions, grants, audit rows or driver calls; links and forms of the guest views lead to the next preview screen. The admin page posts its unsaved drafts to the iframe (debounced 300 ms, `POST /admin/texts/preview`, never stored); a click inside the preview asks the page by `postMessage` to re-post the drafts for the next screen. Without JS the preview shows the saved texts and its links work. Framing: §12. |
| Retention | Retention period (default 1 year); nightly purge job. |
| Backup | Implemented M6, per instance: daily `portal:backup` (02:45 UTC) to `/data/backups` (may be a bind mount to a NAS share): one `portal-backup-<timestamp>.tar.gz` (mode 0600) with SQLite snapshot (`VACUUM INTO` on an own connection, WAL-safe), `.env` (APP_KEY), `certs/` and `branding/` (v0.10.0); entries mirror `/data`; files unreadable for the app user, symlinks and names outside `[A-Za-z0-9._-]{1,94}` or with `..` are skipped and listed in `backup-manifest.json`. Timestamp in the display time zone since v0.10.0 (before: UTC; known limit: two backups within the repeated DST hour may sort in the wrong order). Keep N (local setting, default 14; sorted by timestamp + counter). Admin page (admin only): list, create now, download, delete, retention, warning if the folder is not 0700 / not owned by the app user; create/download/delete and failures (incl. scheduled) audited. Restore by CLI (stop container, extract into `/data`), no `portal:restore` (needs stopped services). SQLite only. Since M7 also `federation/` (federation key and certificate); a restore of a federated instance triggers the resync (§10.3). |
| Audit log | Admin/manager actions, logins, revocations, setting changes (implemented M6): code create (bulk: one entry with count)/edit/extend/expiry/revoke/disconnect/CSV export, approval decisions (admin UI, Telegram, signed link; actor label of the channel user), MAC whitelist changes, settings, sites, routers, setup inputs, terms, channels (incl. tests, SMS role and order), SMS failover and recovery (`sms.*`, actor "system"), users, 2FA enable/disable/reset, login success/failure, logout, backups (create/download/delete), certificate sources, pulls and updates. `data` never holds secrets (keys like password/secret/token are stripped) nor phone numbers. Admin page with filters (area, user, date, object) and pagination; purged with the retention period. |
| Federation | Peers, status, lag, join token, Telegram owner. |
| RouterOS generator | Per site: copy-paste script (hotspot, RADIUS client, `/radius incoming`, walled garden, cert fetch), `login.html` download and upload instructions. Decision 2026-11-05 (v0.29.0): the downloads offer every hotspot page of the driver (MikroTik: `login.html`, `status.html`, `logout.html`, `alogin.html`; other drivers: `login.html` only), `/admin/sites/{site}/{page}.html`. |
| Setup assistant | Per site (implemented v0.6.0): guided steps with copy buttons, commands generated by the NAS driver from saved inputs (sanitized): 1 RouterOS container (optional, inputs local to the instance; Docker host as alternative), 2 guest network (VLAN, bridge, subnet, gateway, DHCP pool, SSID, Wi-Fi mode CAPsMAN v1 / wifi CAPsMAN / local radio; stored in site setting `setup`, also fill the router script), DHCP option 114, firewall isolation, 3 hotspot + DNS names (no captive check hosts in the walled garden), 4 router script, 5 `login.html` via `/tool fetch` (route `/router-login/{nas}/login.html`, router token + NAS IP like the certificate download), 6 status per router (last RADIUS contact, certificate and `login.html` fetched), 7 diagnostics, 8 uninstall script (decision 2026-10-29, v0.12.1: removes by name/comment `guest-portal` in dependency order; container and guest network infrastructure as separate optional blocks; `/radius incoming` and `login.html` only as hints). v0.12.1: bridge = the bridge with the trunk port to the CAPs (diagnostic print, bridge VLAN entry only as optional block for `vlan-filtering=yes`, filtering no longer switched on); hotspot host names must differ from the portal host (validated); certificate/login checklist on the assistant and site page; assistant firewall/address-list entries commented `guest-portal: ...`. Decision 2026-10-31 (v0.12.7, verified on a RouterOS 7.24.5 CHR by `tests/routeros`): generated scripts never change or remove objects they did not create; every object of the assistant blocks carries the comment `guest-portal` (or `guest-portal: ...`) and is removed only by its name/list plus that comment (exact or `^guest-portal: `, never a bare prefix); adds that may collide with existing entries (interface list GUEST, address-list entries, option 114) are guarded so the blocks are re-runnable; foreign walled-garden ip entries (e.g. a drop covering the portal IP) are only printed in diagnostics; the hotspot profile sets `html-directory` explicitly from the assistant field (`hotspot` default, `flash/hotspot` for devices with a flash folder), used by the `login.html` fetch. Decision 2026-10-31 (verified on the CHR): every generated object with a comment property gets its own comment key `guest-portal:<block>:<object>` (blocks radius, walled, cert, dns, container, net, wifi, fw; list: `MikrotikRos7Driver::managedObjects($site)`, basis for a router state query); removal and re-run guards match name plus the exact key, plus the legacy comments `guest-portal` / `^guest-portal: ` (and no comment for the certificate script/scheduler) so older installs are updated or removed; network, Wi-Fi and container objects are add-or-update (set by id after name + key match, legacy comment replaced by the key), so these blocks are re-runnable; hotspot, profile and certificates have no comment and stay identified by name. Optional guest Wi-Fi password per site (field `wifi_password` of step 2, 8-63 printable ASCII; empty keeps the stored one, a checkbox removes it): stored in `setup.wifi_password` encrypted with the federation data key (site settings are replicated; re-encrypted on key rotation), never audited, logged or rendered into the form; sets `sec-guest` to `wpa2-psk` + `aes-ccm` (CAPsMAN v1) or `wpa2-psk,wpa3-psk` (wifi package) with the passphrase escaped (`\ " $ ?`); without a password the own `sec-guest` is open (`unset` passphrase/authentication types). Decision 2026-10-31 (storage): per-site checkbox "Share with other instances" (`setup.wifi_password_shared`, default off; sites saved before the flag keep a stored replicated password as shared). Shared: as above (replicated `setup.wifi_password`, federation data key, re-encrypted on rotation). Not shared: only in the local column `sites.wifi_password_local` (`encrypted` cast, APP_KEY; `Federation::LOCAL_COLUMNS`, never in the replication payload, never overwritten by remote events); toggling moves the value, no copy stays in the other storage; a shared site keeps no local value on any instance (cleared on every save of the site and by the apply hook when a remote event switches it to shared, decision 2026-10-31; while shared a local value would be ignored); only when the site carries a non-empty shared value: a shared flag without a value never clears a local password (it may be the only copy). Ticking "share" with an empty field moves this instance's local value into the shared setting; a WPA site with no value on this instance (neither entered, shared nor local) is refused with a validation error, an open site accepts the flag. The flags `setup.wifi_security` (`wpa`|`open`) and `setup.wifi_password_shared` replicate (no secret), so other instances know the network has a password. A WPA site whose password is not available on this instance: no Wi-Fi QR (same as the ambiguous case), no password text on vouchers or notices, the router script leaves `sec-guest` untouched and prints a hint (never a silent downgrade to open), the assistant shows a hint. Decision 2026-10-31 (router state query): optional read-only API access per router (`api_host`, `api_type` rest = REST over www-ssl `/rest` or api-ssl = RouterOS API over TLS, default ports 443/8729, all `api_*` columns local to the instance (not replicated, `Federation::LOCAL_COLUMNS`), `api_user`/`api_password` encrypted with APP_KEY (cast `LocalEncrypted`, storage format of Laravel's `encrypted` cast, an undecryptable value reads as null with a warning; not the federation data key; in backups only inside the database; values of older versions encrypted with the data key are re-encrypted by a migration, or the access is removed if the data key is unknown on the instance), optional `api_pin`); never audited, logged or flashed, the password never rendered into a page (only returned on the explicit "Insert passwords" of the setup assistant, see below). TLS: the server's public key must match a pin (`sha256//<base64>`, curl `CURLOPT_PINNEDPUBLICKEY` for REST, checked before the login for api-ssl), by default the pin of the router certificate managed by the portal (the router sets it on the service), else the configured pin; a TLS error with a stored pin is shown as such on the setup page, and a checkbox in the site form removes only the stored pin (decision 2026-10-31); no chain or host name check (router addressed by IP). Client: one interface `App\Nas\RouterOs\RouterOsClient` with `print` only, menus from a whitelist (`Menus::ALLOWED`), explicit property list required, secret-like properties refused; REST via Laravel Http, api-ssl as own minimal implementation of the sentence protocol (login, print with `.proplist` and `?` OR queries, `!re`/`!done`/`!trap`/`!fatal`/`!empty`), no new dependency. Assistant block per router: group `guest-portal-ro` (policy `read,api,rest-api`, no write/sensitive) and user (password placeholder), add-or-update by name + key `guest-portal:api:group|user`, removed by the uninstall block; `/ip service` is only printed (not an own object); decision 2026-10-31: the uninstall block does not reset the service certificate either (the portal never sets it), it reports every `/ip service` still using the certificate `guest-portal` before the certificates are removed (reset manually). "Query state" per router and "Refresh" (all routers) read the menus of `managedObjects()` plus the prefill menus (decision 2026-10-31: "Query state" synchronous within the request, bounded by the per-router deadline; "Refresh" queues one job per router on the low-priority queue `router-state`, the container's queue worker takes `default` first so SMS, disconnects and pushes wait at most one router query; routers still queued are shown on the page with a reload link, cache marker of at most 10 min); result + timestamp stored per router as local status (`router_state*`, not replicated); a failed query keeps the previous state and stores the error (kind: config, connect, timeout, tls, auth, denied, protocol; missing menus per menu), shown localized. Per block: present / missing / differs (expected values by exact comment key) / legacy (legacy comment, present) / foreign (guarded firewall add: equal entry of others used); hotspot server and profile by name. Prefill: empty assistant inputs are derived from the routers with a fresh successful state of their current API host (own objects by exact key, CAPsMAN v1/v2 enabled, html-directory of the own or default profile, the only bridge as marked assumption), validated with the driver rules, shown marked (with the source router) and used in the commands, not saved until the form is saved; decision 2026-10-31 (sites with several routers): chosen per field, the first router (by name) whose own object provides a valid value, else the first router providing a valid value at all. Commands are greyed out (aria-disabled, copy button disabled) when any router with API access has no successful query newer than `portal.router_api.max_age_minutes` (default 15), the site's last change, the container inputs or the router's access settings; routers without API access do not grey out anything (previous behaviour); the access block itself is never greyed out. Verified on the RouterOS 7.24.5 CHR (`tests/routeros`). Decision 2026-10-31 (script display, display only, script semantics unchanged): status badges use solid fixed colours (WCAG AA in both schemes); each command line is coloured by the fresh router state of its comment key (hotspot server/profile by name; continuation and `{ ... }` group lines inherit): missing red, differs/legacy amber ("needs update"), present/foreign green, others neutral, with a legend; no colours while the commands are greyed out, none for diagnostics and uninstall blocks; the per-router access block and the router script page use that router's state. Every `<PLACEHOLDER>` of a shown block gets an input (suggestions from the stored router states, e.g. interfaces, bridges, pools, Wi-Fi configurations, addresses, as a datalist with free text; prefill from site values) that replaces it live in the shown and copied text (browser only, not saved). Stored passwords are never rendered into the page: the assistant shows the Wi-Fi block with `<WIFI_PASSWORD>` plus an abort guard (like `<API_PASSWORD>`), and the per-block toggle "Insert passwords" (default off) fetches the Wi-Fi password and the router's API password (escaped for quoted strings) from an admin-only POST endpoint (`no-store`, never logged or audited); the copy button warns when passwords are not inserted or placeholders are unfilled. Router state menus for disks are not queried (no `/disk` menu): `<DISK>` stays free text. Decision 2026-11-01 (guest Wi-Fi on 1..n masters, all three Wi-Fi modes): the admin selects the masters per site and Wi-Fi mode in step 2, as checkboxes from the stored router states (local: wifi interfaces without master-interface; CAPsMAN v1/v2: master configurations of the provisioning rules), without router data as a free-text list (RouterOS name characters, at most 16, not `cfg-guest`/`wifi-guest*`); stored in the local column `sites.wifi_masters_local` (JSON mode => names, `Federation::LOCAL_COLUMNS`, not replicated, like the router API access: the masters belong to the routers this instance serves; assumption: one selection per site serves all its routers). Script per master: local = one virtual AP `wifi-guest-<master>` (master-interface=<master>, configuration cfg-guest, own key `guest-portal:wifi:wifi-guest-<master>`, add-or-update; the single `wifi-guest` of earlier versions on that master is renamed to it, to verify on a radio); CAPsMAN v1/v2 = for every provisioning rule with master-configuration=<master> the script reads the slave list and appends `cfg-guest` only when missing, existing slaves kept (replaces `<EXISTING_SLAVES>`); this append is a change to objects of others, other rules are never touched; decision 2026-11-01 (second approved exception, same as the append): the CAPsMAN v1/v2 network uninstall takes `cfg-guest` out of the slave configurations of every provisioning rule that lists it (all rules, not only the selected masters, since the selection may have changed), only while the own `cfg-guest` exists, before the configuration, security and datapath are removed; the other slaves are kept in their order, an empty list is set with `slave-configurations=""` on `/caps-man` (`unset` does not work there) and with `unset` on `/interface wifi` (restores a rule without slaves; `set ""` would leave an explicit empty value), nothing else of the rules is changed; re-running changes nothing. A master missing on the router (or a placeholder left unfilled when nothing is selected: `<WIFI_INTERFACE>` / `<MASTER_CFG>`) is reported with `:put` and skipped. Router state: per virtual AP by key (master-interface, configuration), per CAPsMAN master a status key `guest-portal:wifi:slave-<master>` (present when every rule of the master lists cfg-guest, else missing), used for the line colours of the rule's lines; placeholder suggestions `<WIFI_INTERFACE>` = radios, `<MASTER_CFG>` = master configurations of the rules. Verified on the RouterOS 7.24.5 CHR (`tests/routeros`: two masters per mode; mode local only the skip path, the CHR has no radio). Decision 2026-11-01 (objects without comment): versions 0.6.0 to 0.12.6 and the manual commands of `docs/guest-network-setup.md` created objects without comment (Wi-Fi `dp-guest`/`sec-guest`/`cfg-guest`/`wifi-guest`, container veth/bridge port/mounts/envs, VLAN interface, pool, DHCP server and network, option 114, list GUEST and member); the scripts' find does not match them and their add fails ("already exists" / "must use unique name"). The router state reports a managed object with a name as "unmanaged" ("name taken (no comment)", own violet badge and line colour with legend entry, ranked above missing) when no row has its key or a legacy comment and a row with its identifying property (name, list, interface, address; env additionally `key=APP_URL`) has an empty comment; rows with any other comment stay "missing" (not ours); guarded adds (list GUEST, member, address-list entries) keep "foreign"; the certificate script/scheduler without comment stay "legacy" (replaced by the router script); mode local: the old `wifi-guest` (no comment) on a selected master is reported on `wifi-guest-<master>` with the key of the old name, so the Wi-Fi block renames it. The setup page shows per router a one-time adopt command per object, e.g. `/caps-man datapath set [find where name="dp-guest" && !comment] comment="guest-portal:wifi:dp-guest"` (condition `!comment`: only while the object has no comment, never overwrites a foreign comment; `comment=""` does not match an object without comment on RouterOS 7.24.5; several matching rows: no command, resolve by hand; a wifi package configuration without own comment reports the comment of its datapath, else of its security, as its own (RouterOS 7.24.5, verified on the CHR): such an inherited comment counts as no comment in the comparison, and the adopt command of `cfg-guest` also accepts the current comment of its datapath/security, evaluated on the router, so the commands work in any order), run manually by the admin; the scripts themselves never adopt foreign objects. `docs/guest-network-setup.md` sets the comment keys in every manual command of objects the assistant also creates. Verified on the RouterOS 7.24.5 CHR (`tests/routeros`). Decision 2026-11-02 (v0.19.0, phase 1 of the setup wizard; phase 2, a guided wizard view, reuses the partials `admin/sites/partials/wifi-modes` and `admin/sites/partials/provisioning-editor` and the driver methods below): Wi-Fi modes freely combinable: site setting `setup.wifi_modes` (replicated list of capsman1, capsman2, local, ordered like `Site::WIFI_MODES`); the single `setup.wifi_mode` of earlier versions is read as a one-element list (`Site::wifiModes()`, no data migration: read compatibility suffices, the next save writes the list), saving also writes `wifi_mode` = first mode for instances on older versions; forms of earlier versions posting `wifi_mode` are accepted. The Wi-Fi block has one sub-block per mode (order capsman1, capsman2, local) with dp-guest/sec-guest/cfg-guest generated once per package path (`/caps-man` for capsman1, `/interface wifi` shared by capsman2 and local), one password guard and one `{ ... }` group around everything; a single mode gives the block of earlier versions. Masters per mode as before (placeholder `<MASTER_CFG>` per CAPsMAN mode without selection, the same placeholder in both packages: assumption, rarely both empty). Uninstall per package path (own CAP rules, cfg-guest out of the rules, virtual APs, objects). Router state: both packages carry the same comment keys, so the `/caps-man` objects get the state id `<key>@caps-man` (entry field `key`) while the wifi package is selected too; line colours use the worse status of both. Prefill derives `wifi_modes` (every mode with own objects, else the enabled CAPsMAN of one package) only when no modes are saved. Decision 2026-11-02 (provisioning editor, second approved exception (b) to "never change objects of others"): per router with a stored state, every CAPsMAN v1 (`/caps-man provisioning`) and v2 (`/interface wifi provisioning`) rule is shown (band matcher `hw-supported-modes` / `supported-bands`, master dropdown of the configurations of the state, slaves list with add/remove, radio-mac/identity, disabled, own CAP rules read-only), plus "add rule" with band (any, 2.4, 5, 6 GHz (v2 only), mapped to a fixed band list per package), master and slaves; one master per rule (RouterOS). Input is not persisted (decision: the rules live on the router and the state query is the source; a stored draft would go stale against the router): browser form, POST JSON `/admin/sites/{site}/setup/routers/{router}/provisioning` (admin only, throttled, `no-store`, not logged or audited), validated server-side against the stored state (rule ids of the state, own CAP rules not editable, configuration names with RouterOS name characters existing in the state, cfg-guest never as master, at most 8 new rules and 8 slaves). Script: changed rules only, addressed by `.id`, set only while the router still has the master and slave configurations of the state (`:if`, else `:put` skipped), empty slave list `slave-configurations=""` (v1) / `unset` (v2); new rules (`action=create-dynamic-enabled`, at the end) added only when no rule with the same band set and master exists, without comment (they belong to the admin; the uninstall never removes them); ends with a `print` and the hint below. The cfg-guest append of the Wi-Fi block keeps working on edited rules (it matches by master name on the router). Decision 2026-11-02 (guest SSID per CAP, "variant A", verified on wifi package hardware with RouterOS 7.24.5; legacy CAPsMAN untested on hardware): per CAPsMAN mode the admin may select CAP radios (radio-mac) from the router state (v2 `/interface wifi radio` with a cap, v1 `/caps-man radio`; current configuration from the master interface with that radio-mac: v2 `/interface wifi`, v1 `/caps-man interface`) or as free text; stored normalized (`AA:BB:CC:DD:EE:FF`, at most 64 per mode) in the local column `sites.wifi_caps_local` (JSON mode => MACs, `Federation::LOCAL_COLUMNS`, like the masters). Per selected radio the Wi-Fi block adds or updates an own rule `radio-mac=<mac>` with key `guest-portal:wifi:cap-<12 hex>`, `place-before` the rule of the admin that provisions it now (found on the router: configuration of the radio's master interface, then the first rule with that master that is no own CAP rule and has no other radio-mac), copying action, band matcher, master, slaves and name formats (v2 `name-format`, `slave-name-format`; v1 `name-format`, `name-prefix`; empty values unset) plus cfg-guest; moved before the admin rule only when after it (re-run changes nothing); radio not connected or no rule found: `:put` and skipped (an existing own rule stays; limitation: after the admin changes the master of that rule the own rule keeps the old values until the radio is deselected). With a selection the masters of that mode are not used and cfg-guest is taken out of every other rule (existing remove logic, only while the own cfg-guest exists, own CAP rules excluded by the exact key pattern); own CAP rules of radios no longer selected are removed by id after the comment check (also without a selection). The uninstall removes own CAP rules by the exact key pattern `^guest-portal:wifi:cap-[0-9a-f]{12}$` (by id) before cfg-guest is taken out of the rules. Router state: per own rule by key (radio-mac expected, without cfg-guest "differs"); own rules are left out of the master options and the slave check. Never provisions: scripts never contain `provision` / `remote-cap provision` (provisioning without a matching rule wiped a real AP); rule changes are applied to the CAPs by CAPsMAN (auto-deploy of rule changes reported by the maintainer 2026-11-02, not verified on the CHR); the scripts end with a `print` and the hint to provision a CAP manually if it did not pick them up. New read-only menus: `/interface wifi radio`, `/caps-man radio`, `/caps-man interface`; additional properties of the provisioning menus (`disabled`, band matcher, `radio-mac`, `identity-regexp`, `common-name-regexp`, name formats) and `radio-mac` of `/interface wifi`. Decision 2026-11-02 (v0.19.0 phase 2, guided wizard): additional view `GET /admin/sites/{site}/wizard?step=N` (admin only, same middleware as the setup page; linked from the setup page and the site page; the setup page is unchanged in function). Steps in order (`WizardController::STEPS`; order approved by the maintainer 2026-11-02, replaces the first order): 1 router API access (per router), 2 RouterOS container (optional; option "Not used (Docker host)" counts as done, kept in the browser like the other progress, no preselection since no site setting records "no container"), 3 guest network, 4 guest Wi-Fi (modes, masters, CAPs, provisioning editor partial), 5 firewall and DHCP option 114, 6 hotspot + DNS + walled garden, 7 router script (absorbs the former certificate and RADIUS steps: certificate checklist, RADIUS address and diagnostics; the check covers radius, walled, cert), 8 `login.html` fetch, 9 status. Browser progress keys are step-name based (`step:<name>`, `manual:<name>:<router id>`), so a reorder never mixes progress; keys of earlier versions are ignored. Each step: explanation, its inputs (saved through the existing setup actions; hidden field `wizard_step` redirects back to the step, without it the setup page as before; the network form is split into groups network / Wi-Fi / hotspot, the other groups posted hidden with their current values, Wi-Fi password never posted from another group), its blocks of `setupCommands()` / `apiAccessCommands()` / `loginPageFetch()` with the setup page's placeholder and "Insert passwords" handling (no script logic of its own), and "Check" = the existing per-router state query, the result shown only for the step's scope (compare blocks, for `net` by object: network = vlan, gateway, pool, dhcp-server, dhcp-network with bridge-vlan shown but not required; firewall = `fw` + `net` option-114; router script = radius, walled, cert; hotspot = dns, hotspot server/profile, walled). The router script (RADIUS secret, certificate token) is never rendered in the wizard, only linked to the existing router configuration page per router. "Next" only when the portal-side preconditions hold (inputs saved, portal and router certificate present, `login.html` fetched by every router) and every router with API access has a fresh state with every required object present or foreign; routers without API access (or without state support) need a "Done manually" confirmation; otherwise "Skip". Progress (done / manual / skipped) and the manual confirmations are kept in the browser only (localStorage per site, every access in try/catch, the page works without it): no server storage, since the router state is the source of truth and per-admin progress would go stale against the router. Uninstall is not a step (link to the setup page). Review fixes 2026-11-02 (v0.19.0): the per-CAP rule copies only an enabled rule of the admin; the editor changes only rules whose master, slaves and band are plain names (else not editable); a new rule whose band and master match a disabled rule is reported as existing but disabled, not added; array input to the editor endpoint is a 422; own cap rules of a CAPsMAN package whose mode is deselected are not removed by the Wi-Fi block (the menu may not exist on the router, and a command of a missing menu is unsafe to emit): only a hint is printed, the uninstall of a selected mode removes them. Confirmed by the maintainer 2026-11-02: one router serves one site; the cap-rule keys (`guest-portal:wifi:cap-<mac>`) carry no site id, so the cleanup and uninstall would also remove the cap rules of another site on the same router. Decision 2026-11-02 (v0.19.1, dynamic hotspot entries, approved by the maintainer): RouterOS adds dynamic entries for a hotspot that cannot be removed and are never ours: an A entry in `/ip dns static` for the dns-name of the hotspot profile (to the hotspot address, TTL 5m; seen on the RouterOS 7.24.5 CHR and on a real router) and a dynamic `/ip hotspot walled-garden` copy of every `/ip hotspot walled-garden ip` entry with its comment and port (action allow/deny; seen on the CHR, also for foreign entries and for the own `guest-portal:walled:portal-ip`; the dynamic entry with comment `guest-portal`, dst-port 443 and no dst-host seen on a real router is this copy of an ip entry with the legacy comment, not the server name, assumption from the CHR behaviour); no dynamic entries in `/ip hotspot walled-garden ip` itself on the CHR (`!dynamic` there as a safeguard). Every find/remove of own or legacy comments in `/ip hotspot walled-garden`, `/ip hotspot walled-garden ip` and `/ip dns static` (router script, setup blocks, uninstall, adopt commands) adds `&& !dynamic`; the router state reads `dynamic` for these menus and ignores dynamic rows for own/legacy/foreign/ unmanaged (so they never change line colours). The own static DNS entry of the hotspot name (`guest-portal:dns:hotspot-name`) is dropped: its add failed ("entry already exists") next to the dynamic entry; the DNS block and the uninstall still remove an own entry of earlier versions (key or legacy comment, non-dynamic); the key is no longer in `managedObjects()`. Router state: item `dns:hotspot-name` (by name, no comment key): present when any entry with the hotspot name exists (info "dynamic (hotspot)" for the router's entry), legacy while an own static entry of earlier versions exists, missing without any entry. The portal CNAME and its internal A record stay as they are. Decision 2026-11-02 (Wi-Fi mode switch): the Wi-Fi block of the selected modes does not clean up an earlier selection (a menu of a package that is not selected may not exist on the router, so no command for it is emitted); it prints a hint for every CAPsMAN package whose mode is not selected (also with mode local only). The router state reports own Wi-Fi objects of an earlier selection as "leftover" (not done in the wizard): per package whose CAPsMAN mode is not selected, the own CAP rules and the rules that still list the own cfg-guest (CAPs would keep broadcasting the guest SSID); without mode local the own virtual APs `wifi-guest[-<master>]`; without any mode of the package its own dp-guest, sec-guest and cfg-guest (own = key or legacy comment; objects of others never). For each router with leftovers the setup page (step 2) and the wizard (step Guest Wi-Fi) show a clean-up block with a notice, generated from that router's stored state with the commands of the network uninstall (CAP rules, cfg-guest out of the rules as the approved exception, virtual APs by name plus key, then the objects), only for packages whose menus exist there; run once by the admin, never greyed out. Decision 2026-11-02 (container step hint): the state query also reads `/system device-mode` (`container`); the container step (setup page and wizard) shows a notice per router whose stored state has no container package (both `/container` menus missing) or a device mode without containers; only a hint, the step stays selectable. Decision 2026-11-03 (v0.24.2, walled-garden copies, verified on the RouterOS 7.24.5 CHR): the dynamic `/ip hotspot walled-garden` copy of an ip entry cannot be edited or removed ("can't edit dynamic object"); `/ip hotspot walled-garden ip` has no `dynamic` property at all (REST leaves it out, `get` refuses it, `find where !dynamic` matches every entry), so a reported "legacy" ip entry is the static one with a comment of earlier versions, which the router script and the uninstall already remove (key or legacy comment, `!dynamic`) in both menus before adding the keyed entries; its copy follows. The state reads `dst-address` of the host menu and counts a row there with a dst-address as dynamic also without the flag (the menu refuses dst-address for its own entries); a legacy or differing own ip entry whose copy is in the host menu gets the hint "also listed as a dynamic copy (D) ..., run the router script", so the admin does not try to fix the read-only copy by hand. Decision 2026-11-05 (v0.28.1, adopt command for legacy walled-garden entries, verified on the 7.24.5 and 7.25 CHRs): an admin did exactly that (tried to edit the comment of the copy, and running only the two highlighted lines of the script without the menu line fails with "syntax error" / "bad command name add"), so a legacy entry of `/ip hotspot walled-garden` or `/ip hotspot walled-garden ip` (`MikrotikRos7RouterState::ADOPT_LEGACY`) also gets a one-time adopt command next to the unmanaged ones: `set [find where comment="<legacy comment>" && <expected values, quoted> && !dynamic] comment="<key>"` on the static entry, the copy follows; quoted values because `protocol=tcp` unquoted matches nothing in `find where` on 7.24.5 (`protocol="tcp"` does, on both versions); decision 2026-11-05 (v0.29.1): in `/ip hotspot walled-garden ip` only `comment` and `dst-address`, without `!dynamic` (the menu has no dynamic rows; the full condition matched nothing on a 7.24.5 x86 router, comment + dst-address did); ambiguous (several entries) or odd characters = no command. The scripts still replace the entry themselves; the hint on the card now names both ways. The harness fixture `legacy.rsc` carries such an ip entry. Decision 2026-11-05 (v0.28.2, found by that fixture on the 7.24.5 CHR): RouterOS derives dynamic `/ip firewall filter` and `/ip firewall nat` rules (chains hs-unauth, hs-unauth-to, flag D) from every walled-garden ip entry while a hotspot runs, carrying the entry's comment; a legacy entry ("guest-portal") therefore made the firewall block and the uninstall fail at their `remove [find where ...]` ("can't remove dynamic object"), which is probably what the owner saw. The firewall filter, nat and address-list removes now use `ownedStatic()` (`&& !dynamic`) like the walled-garden and DNS menus. State cards link to the commands of their block (`MikrotikRos7Driver::STATE_COMMANDS`, anchors `block-<key>` on the command blocks): on the setup page within the page (the admin layout opens a closed `<details>` holding the hash target), radius/walled/cert to the router script page of that router, api to its access block; in the wizard to the step holding the block (`WizardController::COMMAND_STEPS`); a state block spanning several command blocks lists all of them in the card. Decision 2026-11-05 (v0.29.0, hotspot pages): after the login a guest who opened the hotspot name again got the RouterOS default `status.html` ("Hi, g<random>", the one-time RADIUS user name) and its logout reached the portal only as an accounting Stop, so the MikroTik driver now generates four pages of the html-directory (optional driver interface `ServesHotspotPages`): `login.html` as before; `status.html` → `<portal>/portal/status?site=<slug>&mac=$(mac-esc)&ip=$(ip)&link-login-only=$(link-login-only-esc)` (§7.1); `logout.html` (after a router-side logout) → `<portal>/portal?site=<slug>&mac=$(mac-esc)&ip=$(ip)&link-login-only=$(link-login-only-esc)` (login page with the "logged out" notice; the login URL is added to the brief's target so the login form works there); `alogin.html` → `$(link-redirect)` at once (the default waits 2 s, opens a status popup with `$(if popup == 'true')` and redirects with JavaScript). Minimal HTML, meta refresh plus link, no JavaScript, no `$(` besides these variables (RouterOS reads every `$(` as a variable or directive); variables taken from the default pages of a RouterOS 7.24.5 CHR (`$(mac)`/`$(mac-esc)` and `$(ip)` on every page, `$(link-redirect)` on alogin.html); other default files stay. Route `/router-login/{nas}/{page}.html`, page login, status, logout or alogin, same authentication (router token + NAS IP), `login_page_fetched_at` set by any of them; a page the driver does not have is 404 after the authentication. Step 5 and its status label are "Hotspot pages" («Hotspot-Seiten»), the fetch block has one `/tool fetch` per page into `<html-directory>/<page>.html`; checked by `tests/routeros` (four files with their targets and variables intact, kept when the hotspot is re-created, foreign directory untouched). |
| Branding | Implemented v0.10.0 (decision 2026-10-29, admin only, docs/branding.md): Settings → Appearance: logo (PNG/JPEG/WebP/SVG, max. 512 KB, max. 4096 px), favicon (PNG/ICO/SVG, max. 100 KB), accent/background/text colour (hex; accent contrast black/white computed by WCAG ratio), custom CSS (max. 64 KB). Per site each item can be overridden (site setting `branding`, key present = override, missing = inherit; site CSS replaces the global CSS). Fallback chain: site override → global upload → built-in default (inline SVG logo in `currentColor` following `--gp-accent`, `public/favicon.svg/.png/.ico`). Guest pages use the site of the portal session (status page: site of the RADIUS token), else global; admin UI, login and signed approval pages always global. Uploads validated by content (`getimagesize` + finfo for raster, XML parser for SVG; extension ignored), no re-encoding (no GD in the image). SVG: UTF-8 only; rejected if it contains DOCTYPE/entities, script/foreignObject/iframe/embed/object and similar, `on*` attributes, `javascript:`/external `href`, `@import` or external `url()`; editor metadata, comments, unknown and animation elements removed; re-serialized. Files stored as `<sha256>.<ext>` in `/data/branding` (in backups), served at `/branding/files/<hash>.<ext>` (guest + admin port, no session) with `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox`, `nosniff`, `Cache-Control: immutable`; unreferenced files older than 5 min are deleted on save (grace for concurrent uploads). Colours + custom CSS served as one stylesheet `/branding/theme/<global or site id>.css?v=<content hash>` (`text/css`, linked after the app CSS, no inline CSS). Custom CSS rules: no `@import`, `url()`/`src()`/`image-set()` only `data:` or same-origin paths, no `expression()`/`behavior`/`-moz-binding`/`javascript:` (checked after removing comments and decoding CSS escapes; strings normalized like a URL parser: no `//`, no http/https/ftp/ws/wss/file scheme); reason: guest pages load nothing from other hosts. `?plain=1` on admin pages skips the theme stylesheet (recovery from broken CSS). Stable API: `gp-*` classes and `--gp-*` variables (Tailwind classes are not). Router `login.html` stays without logo (immediate redirect, nothing visible). Audited `settings.branding` / `site.branding` (changed keys only). Colour scheme (decision 2026-10-29, v0.11.0): setting `scheme` dark (default, also when unset) / light (original look), global + per-site override like the other items; fixed, no `prefers-color-scheme`; admin UI always global; class `gp-theme-dark|light` on `<html>` + `<body>`, scheme defaults of the `--gp-*` variables on `<html>` (custom `:root` CSS and custom colours override them); dark remaps the used Tailwind palette colours; voucher print sheet stays light. Custom background/text colour (v0.11.1): surface, input, border and divider variables derived from `--gp-bg`/`--gp-text` via `color-mix()` in the theme stylesheet. |
| Toolbox | Decision 2026-11-01 (v0.18.0, admin only, own navigation entry): standalone helpers, independent of sites and settings. First tool "Wi-Fi QR code": SSID (max 32 bytes), encryption WPA (WPA/WPA2/WPA3, password 8-63) or none (open), hidden network (`H:true`), password as text (default on), A4/A6 print view (POST, CSRF, throttled, `no-store`); QR payload from `VoucherQr::wifiPayload`. Nothing is stored, logged or audited; the password is never flashed or rendered into the form. v0.18.2: live preview below the form (POST JSON `/admin/toolbox/wifi-qr/preview`, same validation, `no-store`, own throttle, 500 ms debounce). v0.23.1: tool "Guest portal" (`/admin/toolbox/portal`) shows the guest portal as guests see it, clickable, with language, site (branding, first by name), screen (grouped sign-in/status/errors) and a phone (390 px) / desktop width toggle; plain GET form without JS. Decision 2026-11-03 (v0.23.1, toolbox preview): the page only frames the guest texts preview (`/admin/texts/preview`, `App\Guest\GuestPreview`) as its engine and follows its `postMessage` clicks, so there is no second renderer, no flag on the preview and no new framed route (§12). |
| Captive Portal API | RFC 8908 JSON at `/captive/{site}` (guest port, stateless), announced by DHCP option 114 (RFC 8910): `captive` false while the client IP has a live RADIUS session at the site, `user-portal-url` = hotspot login URL. Needs real guest IPs (no source NAT of guests). |

## 10. Federation (multi-instance)

Decisions 2026-10-29 (M7). Implementation status: parts A-I (change log, data key, apply engine,
federation listener with mTLS, pairing and admin page, sync, session hooks/roaming/limited mode,
Telegram forwarding, branding files), J (purge), K (restore resync) and L (integration tests, docs)
implemented, released as v0.13.0 (§14). Setup and operation: docs/federation.md.

### 10.1 Model

- Each instance is fully self-sufficient: own portal, FreeRADIUS, DB. A site keeps working
  when WAN or other instances are down. A single instance serving several sites stays supported
  (federation of one).
- Typical topology: one instance (container) per site, connected by WireGuard as a **star**: the
  hub instance (the first instance) is paired with every site; sites talk via the hub, which
  relays events. Hub down: the sites do not sync with each other (accepted). Each router resolves
  the portal host name by static DNS to its local instance; the same wildcard certificate everywhere.
- **Multi-master**: every instance's admin UI shows and edits the federated state; writes are
  applied locally and replicated. Rejected: designated primary for writes.
- Partition: each instance keeps working with the last known state; catch-up after reconnect.
- **Hub unreachable** (no successful heartbeat for 2 min, **(assumption)**): non-hub instances accept
  only SMS login and site codes for new logins (hooks `Grant::isAllowedWhileOffline()`,
  `SiteCode::isAllowedWhileOffline()`); redeemed codes and known devices keep working. Admin setting
  "offline: SMS only", default on; never applied on the hub. It follows the hub this instance knows.
- **Hub takeover** (decision 2026-10-31, implemented): when the hub is lost for good, an admin of another
  instance makes it the hub (federation page, confirmed by typing the instance name, audited): it sets the
  hub setting itself (the only exception to "only the hub changes the hub") and tombstones the old hub's
  row; like removing a peer it rotates the data key and lists the secrets to renew (decision 2026-10-31;
  the other instances get the new key with the re-pairing answer). The other instances drop these changes until their admin **re-pairs** with the new hub (page
  "Re-pair with a new hub": hub URL, join token of the new hub, its pin; `POST /federation/repair`, the
  new hub checks the instance's registered pin, a removed instance is restored): data stays, the new hub's
  instance rows and hub setting are taken over (trusted, LWW), replication continues from the cursors.
  A returning old hub gets "removed" from its peers, stops syncing (no silent resumption as hub), shows a
  warning and can re-pair with the new hub as a normal instance.

### 10.2 Trust and transport

- Federation API on its own port 9443 (nginx zone `federation`), mTLS with a per-instance keypair and
  self-signed certificate in `/data/federation/`; peers pin the SPKI sha256 (`CURLOPT_PINNEDPUBLICKEY`);
  nginx `ssl_verify_client optional_no_ca` passes the client certificate, Laravel checks its pin against
  `instances.cert_fingerprint`. No shared secret. Implemented (part D): `portal-certs` generates the EC key
  and a 10-year self-signed certificate once (`/data/federation/federation.{crt,key}`, key root:portal 640);
  the pin format is curl's `sha256//<base64>`; port env `FEDERATION_PORT` (default 9443), own URL
  `PORTAL_FEDERATION_URL` (editable later); peer calls verify no CA, pin the key (set on the curl handle,
  Guzzle does not accept the option), present the client certificate, no redirects, short timeouts.
  Without a federation certificate (generation failed) portal-init leaves the federation listener out, so
  guest and admin ports still start. Ed25519
  key pair in `local_settings` (secret encrypted with `APP_KEY`), public key in `instances.signing_public_key`.
- Events are signed per origin (Ed25519, libsodium), since the hub relays events of other origins.
- Pairing: only fresh instances join (before the setup admin exists); the hub is the first instance.
  An admin creates a one-time join token (15 min, stored hashed); the new instance calls
  `POST /federation/join` with token, its fingerprint and Ed25519 public key; it shows the hub's
  fingerprint for confirmation. The hub returns peer list, pins, data key and a snapshot (topologically
  sorted) and announces the new instance to its peers. Implemented (part E): admin page "Federation"
  (admin only, audited): own identity (id, name, federation URL, admin URL, pin, signing key, hub), hub
  setting (`federation_hub`, set to the member on the first pairing), setting "offline: SMS only", join
  token (40 characters, sha256 stored, single use, 15 min, shown once with the member's URL and pin as a
  ready command), instances with last contact, lag, skew, pause state, resume and remove. Joining on the
  new instance (decision 2026-10-31): create a local bootstrap admin with `artisan portal:admin`, sign in,
  page "Join a federation" (admin only, hidden once joined: hub URL, token, hub pin, own name and URL), or
  the CLI `artisan portal:federation:join <url> <token> <pin> [--name --federation-url --admin-url]`.
  **Fresh** means: never joined, at most one user and that one a local admin (the bootstrap admin), audit
  rows only of that admin or of login attempts, no rows in any other replicated table except its own
  instance row, no events of other origins. On success the bootstrap admin, its audit rows, sessions and
  outbox events are deleted locally (no events; the remaining own events are renumbered and signed again,
  nothing was replicated yet), the browser session ends and the login page asks for a hub account. The member registers the instance with the pin of its
  TLS client certificate, answers with the data key ring (adopted as is), a snapshot (rows of all replicated
  tables in parent order, the stored events of the current versions of rows with uniqueness rules, highest
  sequence per origin as cursors) read in the registration transaction. Removing an instance tombstones it
  (pin no longer trusted everywhere), rotates the data key and lists the secrets to renew (channels, OIDC);
  the hub cannot be removed. Transport state per direct peer is local (`federation_peers`).
- Transport: push after commit (queue job, `POST /federation/events`); pull every 30 s
  (`GET /federation/events?origin=&after=&limit=500`); heartbeat `GET /federation/status` (time,
  protocol, cursors). Further endpoints: `GET /federation/snapshot`, `GET /federation/branding/{hash}.{ext}`,
  `POST /federation/sessions/{id}/disconnect`, `POST /federation/approvals/{id}/decide`.
  Implemented (part F): every local event carries an Ed25519 signature over all its fields (payload as
  the exact stored JSON string, which travels and is relayed unchanged); received events are verified
  with the origin's key before the apply engine (unsigned or invalid: origin paused; key unknown: wait;
  an unknown origin may introduce its key only with its own instance row; removed origins rejected).
  Push: queue job after commit of a local write (and on the hub after applying relayed events), sends
  what the peer lacks according to its acknowledged cursors. Pull: `portal:federation:sync` every 30 s
  (heartbeat, pull, push). Star: the hub pulls each instance's own origin and pushes every origin; the
  other instances pull every origin from the hub and push their own. Heartbeat answers time, protocol,
  highest sequence per origin, applied cursors (stored as the peer's acks in `federation_peers`) and the
  data key ring (rotated keys spread with the heartbeats; an unknown current key is adopted). Skew above
  30 s: warning (log, UI), above 5 min or a higher protocol: transport with that peer paused (no push, no
  pull, its requests answered 409) until resumed on the federation page. Hub unreachable = no successful
  heartbeat for `portal.federation.hub_unreachable_after` (120 s).
- Federation data key (implemented part B): replicated secrets use the cast `federated`
  (`fdk1:<key id>:<ciphertext>`, AES-256-GCM): TOTP secrets, channel config, OIDC client secret. The key
  ring lives in `local_settings` (each key encrypted with the local `APP_KEY`), the first key is created by a migration; reading accepts every known
  key, writing uses the current one. Rotation: new key becomes current, old keys stay readable;
  affected fields are re-encrypted as normal events. Removing a peer rotates the key automatically and
  shows a checklist of channel/SMS/OIDC secrets to renew (a removed peer knew them).
- Protocol: integer `FEDERATION_PROTOCOL` in events and heartbeats; unknown columns are ignored,
  missing columns get their default; a higher protocol pauses applying events of that peer + UI warning
  (rolling updates).

### 10.3 Replication

- **Change log** (implemented part A): `federation_events` (origin, seq, table, record_id, version,
  payload = full row, proto, sig), unique (origin, seq). Every write of a replicated row records an event
  in the same DB transaction (model events saved = create/update/restore, deleted = tombstone; a force
  delete is a local purge without event; a save without changes writes nothing; decision 2026-11-02: neither does a save that changes only local columns (`Federation::LOCAL_COLUMNS`), so no new version can win last-write-wins against a remote edit not yet received; its `updated_at` still moves locally). Query-builder writes of replicated tables are not allowed
  without recording the row (`Outbox::record()`); a test guard fails on such writes. Foreign events are
  stored as well (relay, catch-up). `peer_cursors` (origin, last_seq, pause state); ack state per peer
  follows with the transport.
- Records keyed by ULID with `origin_instance_id`, `version`, `updated_at`, `deleted_at` (tombstone).
  `version` is a persistent hybrid logical clock (`<ms>.<counter>.<instance id>`, sortable string, the
  instance id breaks ties); received versions advance it; versions more than
  `portal.federation.max_future_ms` (5 min) ahead are rejected.
- **Apply** (implemented part C): events of an origin are applied raw in seq order (DB upsert, no model
  events, no new version, no re-emit), inside one transaction with deferred foreign keys; a missing
  parent row or a sequence gap stops the batch until the rest arrived (missing parent: paused after
  `max_wait_attempts`/`max_wait_minutes`; dropped if the event is older than the purge horizon); unknown
  table, higher protocol or an event that cannot be applied (savepoint per event) pause the origin; table
  hooks run after commit. SQLite transactions are `IMMEDIATE` (write lock at BEGIN), so HLC state, outbox
  sequence and data key ring are read and written atomically across processes.
- Conflicts: **last-writer-wins per row** by `version`. Grants: field merge, `revoked_at` earliest
  non-null (sticky, there is no un-revoke), `first_login_at` earliest (with it the earlier `expires_at`),
  `extensions_used` maximum, other fields LWW. Duplicate 6-digit codes created concurrently stay valid as
  separate grants; redemption picks the unredeemed one allowed at the site.
- Natural keys: settings have an id derived from the key; `terms_versions` has no unique (locale,
  number). Uniqueness rules are a pure function of the rows' own states (the payload of the event of
  their current version), re-evaluated for every group an applied row leaves or joins, so the result does
  not depend on the event order and needs no extra events; the rule only overlays the loser (version
  kept): `sites.slug`, `users.email`, `users.oidc_subject`: the smallest ULID keeps the value, the others
  get the suffix `-dup-<id tail>` + audit entry + UI warning; MAC whitelist: among the non-deleted
  entries of a MAC the highest version wins, the others are tombstoned; site codes: among the active
  codes of a site the highest version wins, the others are deactivated; devices ("1 code = 1 active
  device"): among the active devices of a grant the newest binding (`bound_at`, then id) wins, the others
  are unbound (and disconnected by their serving instance). If a winner later stops qualifying, the next
  candidate qualifies again.
- Same code redeemed at two sites with different devices during a partition: allowed; after the merge
  the rule above unbinds the older device.
- Deletes: tombstones only; hard delete after all peers acknowledged + retention; events for unknown rows
  older than the purge horizon (retention period) are dropped (no resurrection); the local retention
  purge emits no events. Implemented (part J, `App\Federation\Retention`, daily `portal:purge`): a
  replicated row is purged only when its last change (`version`) is beyond the horizon as well (rows changed
  within the retention period stay, so no pending event can bring a purged row back); a tombstone (all
  replicated tables except `instances`: a removed instance stays known as removed) is hard-deleted beyond the
  horizon once every direct peer acknowledged its event (star: the hub keeps it until every instance has it)
  and no replicated child row referring to it changed within the horizon (those go with it by foreign key
  cascade). The change log is compacted the same way: an event is deleted when it is beyond the horizon,
  acknowledged by every direct peer, no longer the current version of a stored row (conflict rules and
  snapshots read those) and not the highest sequence of its origin (numbering and clock continue from it).
  A direct peer that acknowledges nothing (down longer than the retention period) holds back tombstone and
  log purges until it catches up or is removed. Expired join tokens are deleted after a day.
- Backups/restore: a restore sets `federation_resync_required`; the instance fetches its own events back
  from peers and continues its sequence after the highest one before accepting writes. Implemented (part K,
  `App\Federation\Resync`): detected at start by `backup-manifest.json` in the data folder (renamed to
  `backup-manifest.restored-<time>.json`) or by a heartbeat in which a peer reports own events beyond the
  local sequence (restore without the manifest). Meanwhile every write of a replicated row fails
  (`Outbox::record()`, HTTP 503, e.g. new guest logins and admin changes; sessions and RADIUS keep working).
  Decision 2026-10-31 (v0.13.1): admin login, logout and the admin UI keep working: `Audit::log()` writes no
  row (and no event, a low own sequence would collide with the events being recovered) but a log warning
  with the entry; TOTP replay protection in the local cache (step not stored in the user row), recovery
  codes rejected until the resync is finished (consuming one writes the user row); logout without a new
  remember token.
  The sync run fetches the own events from every direct peer (signature checked with the own key, applied
  like remote events, gaps not skipped) and lifts the block once every direct peer was caught up. A peer
  that stays unreachable keeps the block: `artisan portal:federation:repair skip-resync --force` lifts it
  when that peer is gone for good (its own events would collide with new ones) or recovery fails for good.
  Decision 2026-10-31 (v0.13.1): the skip stores per direct peer the own sequence it reported
  (`federation_resync_skipped`); the own sequence continues above the highest of them (`Outbox::lastSeq()`,
  also for recovered own events), so a heartbeat reporting up to that value does not mark the resync again,
  only a higher one. A peer that had less stops at the gap (no skipping): `reset-cursor` there. A backup older than the
  retention period may not resync (the peers compacted the events in between): join again as a new instance.
  Backups include `/data/federation` (key and certificate), so a restored instance keeps its pin.
- Repair command (decision 2026-10-31): `artisan portal:federation:repair` with `status`, `resume <instance>`,
  `repin <instance> --pin=` (local trust of a regenerated peer certificate, no event; the peer's own instance
  event confirms it), `reset-cursor <instance> [--seq=]` (apply that origin's events again, idempotent; a
  direct peer's acknowledgements are learnt again), `resync`, `skip-resync --force`; audited.
- Clocks: NTP required. Heartbeats compare clocks: warning above 30 s skew, replication with that peer
  paused above 5 min.
- Branding files: settings reference logo/favicon by content hash; an apply hook fetches a missing file
  from the origin, then other peers (sha256, type detection and limits checked). Implemented (part I): apply
  hooks on the setting `branding` and on site settings queue a job; it asks the instance that wrote the
  reference (instance id in the row's version), then its origin, then the other direct peers
  (`GET /federation/branding/<sha256>.<ext>`, served only from local files; a non-hub instance asks the hub,
  which fetched it itself). Checks as for an upload: size limit of a kind referencing it (curl aborts larger
  downloads), type detection, and the bytes must equal their sanitized form (SVG) and the hash of the name.
  Files not available yet are retried by the sync run after 5 min.

| Replicated | Local only |
|---|---|
| Instances (incl. `admin_url`), grants/codes incl. duration/expiry/sites, site codes, devices (MAC bindings), MAC whitelist + its site restriction, guests, terms versions + acceptances, users/roles incl. TOTP (secret with the data key, last step, hashed recovery codes), sites, NAS clients incl. RADIUS secret and certificate token (data key; decision 2026-10-31, for recovery), certificate sources per instance incl. API key (data key), channels, settings (global, incl. branding references), audit log | Session summaries and accounting, NAS status (last contact, downloads, router state: `Federation::LOCAL_COLUMNS`), router API access (`api_*`, APP_KEY; decision 2026-10-31), not shared site Wi-Fi passwords (`sites.wifi_password_local`, APP_KEY; decision 2026-10-31), RADIUS tokens, OTP challenges, approval requests, login attempts, SMS log, local settings (incl. data key ring, certificate pull status), backup settings, Telegram offset, Laravel sessions, branding files |

### 10.4 Roaming and remote actions

- A valid code allowed for the new site works there. A known device (MAC with a running grant that
  allows this site) is logged in automatically at another site via MAC authentication
  (`login-by=https,mac`, like the MAC whitelist) for the remaining time. RADIUS tokens stay local; a
  token is only accepted when the site's `home_instance_id` is the local instance.
- Disconnects are sent only by the instance serving the session (local accounting): apply hooks on
  grants, devices and MAC whitelist (+ site links) call the existing disconnect logic. The admin button
  for a session of another instance calls `/federation/sessions/{id}/disconnect` at its serving instance
  (synchronous). Partitioned site: revocation applies on reconnect; until then Session-Timeout bounds
  access (known limit).
- Implemented (part G): apply hooks on grants, devices, site codes, MAC whitelist and its site links
  (also changes by conflict rules) disconnect the open local sessions whose access ended (same check as
  `portal:enforce-grants`; a deactivated site code does not end running device grants, as locally) and
  renew the MAC whitelist RADIUS cache. Session summaries stay local (decision 2026-10-29): the guest list
  asks the other instances live (`GET /federation/sessions?grant_ids[]=`, a non-hub instance asks the hub
  with `relay=1`, the hub adds its other peers; unreachable instances are named); the disconnect button
  for a foreign session calls `POST /federation/sessions/{id}/disconnect` with the serving instance, the
  hub forwards to its direct peer; unreachable: error message; an instance that failed is skipped by live
  queries for 30 s. A push gives every origin a fair share of a batch and leaves out origins the peer does
  not take, so one stuck origin does not block the others. Sites have a home instance (site form,
  default the creating instance; empty = the instance it is on); one-time credentials are accepted only
  there. Routers (`nas_clients`, ULID keys since 2026-10-31, `legacy_id` keeps the old number for the
  download URLs of deployed router scripts) replicate for recovery, but only the home instance serves
  them: FreeRADIUS clients file, RADIUS requests, router downloads and disconnects. A replacement takes a
  site over on the site page ("Take over this site", audited, clients file written at once); the
  certificates page takes over the certificate source of another instance (each instance pulls only with
  its own record, `certificate_sources`, id derived from instance and target). Roaming: MAC authentication accepts a MAC that is the active device of a running grant allowed at
  the NAS's site when the device logged in (terms acceptance) at another site (assumption: at its own site
  it keeps using the portal, so an admin disconnect there is not undone at once); Session-Timeout = remaining
  time (none without expiry), the accounting session belongs to the grant; after an admin disconnect of
  the device at that site (`session_summaries.kicked_at`) or a guest logout there (`logged_out_at`, v0.29.0) roaming there stays off until the grant changes
  (a portal login still works). Limited mode (§10.1): the portal hides push approval
  and shows a notice; normal codes are rejected ("Limited mode: please use SMS login") unless the device is
  already bound to the code; SMS and site-code grants are allowed (`Grant::isAllowedWhileOffline()`).

### 10.5 Notifications and Telegram ownership

- SMS, Gotify and webhook messages are sent only by the instance where the guest is waiting; approval
  links point to that instance's admin URL (`instances.admin_url`).
- One Telegram bot, polled (`getUpdates`) only by the **Telegram owner** (default the hub), switchable
  manually in the admin UI (list of instances). Callbacks for requests of other instances are forwarded
  to `/federation/approvals/{id}/decide` at the origin instance.
- Implemented (part H): setting `telegram_owner` = instance id, "none" (polling off) or unset; unset, a
  former boolean value or a removed instance mean the hub (this instance without a hub). The settings page
  offers the instances and "none". Every instance still sends and edits the Telegram messages of its own
  requests; the owner checks group and allowlist, then forwards a press for another origin (directly, or
  to the hub, which relays to its direct peer) with the choice and the clicker's name; the origin decides
  (`decided_via` telegram) and edits its messages; the clicker gets "saved", "already decided" or
  "instance unreachable". The decide endpoint accepts a press only from the Telegram owner (the hub relays
  only the owner's presses; the origin also accepts the hub as relay). Approval links (Gotify, webhook) and
  the OIDC redirect URI use `instances.admin_url` of this instance (federation page), else the replicated
  admin URL setting and its fallback (decision 2026-10-31: the setting is shared, the instance URL is not).

### 10.6 Security of the federation

- A compromised peer can read all replicated data including the data key, since 2026-10-31 also the
  RADIUS secrets of all routers and the certificate source API keys (replicated for recovery; removing a
  peer lists them for renewal); event signatures prevent it from forging events of other origins.
- Membership authority (implemented): the trust anchors of an instance row (signing key, certificate pin,
  federation and admin URL) change only by events of that instance; new instance rows (registrations) and
  removals/restores only by the hub (without a configured hub an instance may only introduce its own row,
  and only then may an unknown origin introduce its key); the setting `federation_hub` only by the current
  hub (the UI allows it only there). Disallowed fields keep the local value, a disallowed registration or
  hub change is dropped (logged). An event with a missing or invalid signature is dropped and the peer
  that sent it is paused (not the origin, so a peer cannot block another origin).
- Security authority (decision 2026-11-02): a remote event that creates, promotes or restores an admin,
  changes a setting of `Federation::SECURITY_SETTINGS` (2FA policy, OIDC, admin URL, Telegram owner,
  offline rule, hub) or the hotspot host names/portal URL of a site is applied only from the hub (site
  values also from the site's home instance); otherwise it is dropped, the rest of the batch continues,
  and it is logged and audited (`federation.event_refused`, origin and record). Such changes made on
  another instance stay local to it, channels stay multi-master (§10.1, every instance sends with them),
  and re-pairing with a new hub takes over its security settings like the hub setting.
  Extended 2026-11-03: for an existing active admin also a change of a login column
  (`Federation::ADMIN_LOGIN_COLUMNS`: e-mail, password hash, role, deletion, TOTP secret, 2FA enrolment,
  `oidc_subject`), new recovery codes (removing used ones replicates) or a TOTP step in the future, so a
  compromised instance can neither take over nor demote, delete or lock out the real admins; a conflict
  rule (older id keeps the unique value, §10.3) triggered by a non-hub event may not rename an active
  admin's e-mail or OIDC subject either (the event is dropped). Other users, and an admin's name, are
  unchanged. Events carry the whole row, so after a refused change on an instance its later changes of
  that admin are refused too until the hub writes the row again. At re-pairing the instance applies the
  new hub's stored user events it refused before (latest per user, newer than the local row) through the
  normal apply path, so the uniqueness rules hold.
- Known limits (parts J, K): a compromised direct peer can claim own events of this instance beyond its
  sequence and so start the restore resync, which blocks replicated writes here until it delivers validly
  signed events or the admin lifts the block (`portal:federation:repair skip-resync --force`); it cannot
  forge them. Purges follow the smallest acknowledgement of all direct peers: too high acknowledgements of
  a peer harm only that peer (and on a spoke its hub, i.e. the instance trusted for relaying anyway).

## 11. Data model

Replicated tables (marked R) use ULID keys plus `origin_instance_id`, `version`, `updated_at`,
`deleted_at`. Designed from M1 on so federation fits later.

| Table | Key fields |
|---|---|
| `instances` R | id, name, federation_url, admin_url, cert_fingerprint (pin), signing_public_key (Ed25519); last_seen_at, clock_skew_ms unused (heartbeat state is local: `federation_peers`) |
| `users` R | id, name, email, password, role (admin/manager), totp_secret (`federated`), totp_confirmed_at, totp_last_step, recovery_codes (hashed), oidc_subject (unique, `<iss> <sub>`) |
| `sites` R | id, slug, name, driver (e.g. `mikrotik_ros7`), home_instance_id, settings (json); local not replicated: wifi_password_local (`encrypted`, APP_KEY) |
| `nas_clients` R | id (ULID), legacy_id, site_id, name, nas_ip (unique per serving instance), nas_identifier, radius_secret (`federated`), coa_port, cert_token (`federated`); local status not replicated: last_seen_at (RADIUS contact, max. 1 write/min), cert_fetched_at, login_page_fetched_at, router_state, router_state_at, router_state_error; local router API access not replicated: api_host, api_type, api_port, api_user/api_password (`LocalEncrypted`, APP_KEY), api_pin |
| `certificate_sources` R | id (from instance + target), instance_id, target (portal/router), type, url, name, ca_pem, api_key (`federated`) |
| `guests` R | id, first_name, last_name, phone_e164 (nullable), locale, social_provider, social_subject, email (nullable; social login, v0.22.0; code login, v0.24.1) |
| `grants` R | id, type (code/sms/approval/site_code/social/account), site_code_id, code (nullable), label, note, duration_s, expiry_mode, valid_from, redeem_until, expires_at, first_login_at, revoked_at, max_extensions, extensions_used, rate_limit, created_by |
| `site_codes` R | id, site_id, code, duration_s, valid_until, max_devices, active, created_by, deactivated_at, deactivated_by (`grants.site_code_id` links device grants) |
| `grant_site` R | grant_id, site_id (none = all sites) |
| `devices` R | id, grant_id, guest_id, mac, bound_at, unbound_at |
| `session_summaries` | id, grant_id (null for whitelist sessions; roaming sessions carry their grant), mac_whitelist_id, site_id, serving_instance_id, mac, ip, started_at, stopped_at; local (M7 decision, replication columns kept; other instances query them live, §10.4) |
| `mac_whitelist` R | id, mac (AA:BB:CC:DD:EE:FF, unique among non-deleted), comment, expires_at, active, created_by |
| `mac_whitelist_site` R | mac_whitelist_id, site_id (none = all sites) |
| `radius_tokens` | id, grant_id, guest_id, device_id, username, password_hash, mac, site_id, expires_at, used_at |
| `radius_accounting` | id, session_summary_id, nas_client_id, acct_session_id, username, last_update_at, terminate_cause, bytes_in, bytes_out, disconnect_sent_at |
| `otp_challenges` | id, guest_id, site_id, mac, phone_e164, code_hash, attempts, sends, last_sent_at, expires_at, verified_at, grant_id; local, with replication columns |
| `sms_messages` | id, otp_challenge_id, site_id, channel_id, mac, phone_e164, status, provider_id, error (local: cost caps, delivery result) |
| `approval_requests` | id, guest_id, site_id, mac, status, decided_by, decided_via, decided_at, duration_s, grant_id, expires_at, meta (json: channel message ids); local, but with replication columns (origin needed to route decisions, §10.5) |
| `terms_versions` R | id, locale, number (per locale, not unique since M7; `version` is the replication column), body (plain text), published_at |
| `terms_acceptances` R | id, guest_id, terms_version_id, grant_id, accepted_at, site_id, mac |
| `channels` R | id, kind (notification/sms), driver, name, config (`federated` json; SMS: reserved keys `_sms_role`, `_sms_order`, §7.2 B), enabled |
| `settings` R / `local_settings` | id (settings: derived from key), key, value (json); OIDC client secret `federated` |
| `login_attempts` | id, mac, ip, site_id, method, success, created_at |
| `audit_logs` R | id, user_id, actor (label), action, subject_type, subject_id, data (json), ip, created_at |
| `federation_events` / `peer_cursors` | change log, local and foreign (origin, seq, table, record_id, version, payload, proto, sig, created_at; unique origin+seq) / last_seq + pause state per origin |
| `federation_peers` / `federation_join_tokens` | local: heartbeat state per direct peer (last_seen_at, clock_skew_ms, protocol, origins, acks, pause) / join tokens (sha256, expires_at, used_at) |
| Laravel defaults | `jobs`, `failed_jobs`, `cache`, `sessions`, `migrations` |

Decision 2026-11-03 (v0.22.0): instances older than 0.22.0 drop the new guest columns of incoming events and
cannot read grants of type `social`, so every instance of a federation must run 0.22.0 or later before social
login is switched on.

Purge job removes guests, devices, summaries, accounting, attempts, acceptances, OTP challenges,
sent SMS records and audit entries older than the retention period (active grants kept; replicated rows
only when also unchanged for the retention period); tombstones (e.g. deleted MAC whitelist entries/site
links) and change log events are purged after all direct peers have acknowledged them (§10.3).

## 12. Security

- **Guest isolation:** guest VLAN may reach only the guest port (TCP 443) of its instance and DNS
  on the router; no other LAN/VLAN, router management, admin or federation port. AP client
  isolation recommended.
- **Ports:** admin port only from VPN/admin networks; federation port only between instances;
  RADIUS UDP 1812/1813 to the instance and 3799 to routers only between routers and their home
  instance. Strong per-router secrets; Message-Authenticator required (Blast-RADIUS): RouterOS >= 7.15
  rejects Accept/Reject without it (`require-message-auth`); FreeRADIUS 3.0.27 adds it to every reply,
  the post-auth config adds it explicitly as well (checked with radclient). Generated scripts set
  `/radius incoming port` explicitly (RouterOS default 1700).
- **Secrets:** `APP_KEY`, internal API token, DB credentials in an env file on `/data` (generated
  on first start), never in RouterOS envs or the image. Secrets in DB via Laravel `encrypted`
  casts (local key or federation data key).
- **Admin:** separate port, TOTP, login throttling, roles, audit log; optional OIDC login (§9): the
  identity provider decides who gets which role, so its group administration is part of the admin
  security; a local admin with password stays as emergency access.
- **Guest login lockout (M2):** limits per MAC, per IP and global (env `PORTAL_LOGIN_*`). The MAC
  comes from the redirect URL and can be spoofed: a guest on the same network could lock out another
  MAC, and an attacker can rotate MACs (then the per-IP and global limits apply). Accepted trade-off
  for a small user base; limits stay configurable.
- **MAC whitelist (v0.8.0):** a MAC can be spoofed; a device on the guest network that copies a
  whitelisted MAC gets the same access (only one of both works reliably). Only trusted devices, a
  comment is required, optional expiry; the User-Name = `Calling-Station-Id` check prevents using a
  whitelisted MAC as user name from another device.
- **Login handoff (M2):** one-time credentials are posted only to `link-login-only` URLs with
  `https` and a host listed in the site's hotspot host names; the post-login target (`dst`) is the
  portal status page (M3); the portal never redirects to `link-orig`.
- **NAS addresses:** IPv4 only for now; IPv6 NAS addresses later.
- **Proxies (decision 2026-11-02):** `TRUSTED_PROXIES` takes only specific proxy addresses or networks;
  a value that trusts every client (`*`, a `/0` network) is ignored and logged as an error at container
  start, since X-Forwarded-For would then choose the IP of every per-IP limit. The source-IP check of
  router downloads no longer depends on it: always on, only `PORTAL_ROUTER_IP_CHECK=false` switches it
  off (warning at start; the former `PORTAL_ROUTER_CERT_CHECK_IP` is no longer read). A deployment whose
  routers reach the guest port through the same proxy as the admin UI (discouraged) must set it.
- **Outbound requests (decision 2026-11-02):** approval channels, SMS gateways, certificate pull, OIDC and the
  guest social login (token, userinfo and JWKS requests, provider checks) follow no redirects (a redirect is an error) and use short timeouts
  (SMS providers 15 s; hosts as of v0.26.0: api.twilio.com, gateway.seven.io, rest.clicksend.com,
  platform.clickatell.com, api.smsapi.com / api.smsapi.pl, api.46elks.com, se-1.cellsynt.net, api.octopush.com,
  api.smspartner.fr, api2.smsplanet.pl, promosms.com, api2.serwersms.pl, smsc.kz, http-api-lts.smsmanager.cz,
  rest.gtx-messaging.net, json.aspsms.com, rest.ecall.ch, smsapi.free-mobile.fr, plus the admin-set SMSEagle
  and SMS webhook URLs; API URL fields override the default endpoint where offered); private address ranges are not
  blocked, since Gotify and similar services often run in the operator's LAN (only admins set these URLs).
  Account login (v0.24.0, §7.2 E): LDAP connections only to the site's directory, timeout per site (default
  5 s), LDAPS by default with certificate and host name verified (switching it off or `none` warns), no
  referrals.
  UniFi controller (v0.27.0, experimental, §5.1): HTTPS to the site's configured controller URL only (no path,
  no redirects), 10 s timeout (5 s connect), one retry after 2 s on HTTP 429, certificate verified against the
  system CAs or pinned to the controller's public key (switching both off warns); API key and password never
  in logs, audit or error messages.
- **Federation:** mTLS with pinned certs, one-time join tokens, idempotent signed events, clock
  skew checks.
- **Web/privacy:** CSRF, strict CSP, HSTS, signed URLs, HMAC webhooks, bundled assets; minimal data, retention + purge.
  Status 2026-11-02 (v0.21.0): nginx sends a CSP per listener (docker/nginx/portal-headers.conf, maps in
  portal.conf.template). Guest (443): `default-src 'self'; script-src 'self' 'unsafe-inline'; style-src
  'self'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri
  'self'; form-action 'self' https: http:; frame-ancestors 'none'`. Admin (8443): `default-src 'self';
  script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;
  font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self';
  frame-ancestors 'none'`. Both send `Strict-Transport-Security: max-age=31536000` (no includeSubDomains,
  no preload); the internal and federation listeners send neither. A CSP or nosniff header set by the app
  (uploaded branding files carry their own strict CSP) is not duplicated. Guest `form-action` allows
  `https:` because the handoff posts to the router's `link-login-only` host, and `http:` since v0.28.0 for generic
  RADIUS sites with an explicit HTTP login URL (OPNsense logon API). Known loosenings, accepted
  for now: `'unsafe-inline'` guest scripts (handoff auto-submit, WebOTP, "sign in again"), and
  `'unsafe-inline'` scripts and styles plus `'unsafe-eval'` on the admin listener for Livewire/Alpine
  (`new Function`) and inline `onsubmit`/`onclick` handlers; nonces or hashes, handlers without inline
  code and Livewire's CSP-safe build would allow dropping them later. Branding keeps the guest pages free of
  inline CSS (theme CSS as a separate same-origin stylesheet).
  Decision 2026-11-03 (v0.23.0): the guest text preview (§9) is the only page that may be framed, by the
  admin page itself: nginx maps `$portal_zone:$request_uri` (original URI, unaffected by the internal
  redirect to index.php) so that `/admin/texts/preview` on the admin listener gets `frame-ancestors 'self'`
  and `X-Frame-Options: SAMEORIGIN`; every other response keeps `'none'` / `DENY`. Done in nginx rather
  than by an app header, because the `always` headers of portal-headers.conf would override it.
- **Supply chain:** pinned base image digest updated by Renovate/Dependabot **(proposal)**,
  weekly rebuild, lockfiles.

## 13. Risks and limits

| Risk | Impact | Mitigation |
|---|---|---|
| Single instance serving remote sites | Remote sites lose new logins when it is down. | Instance per site + federation. |
| Partitioned instance | Revocations/new grants from elsewhere arrive late. | Session-Timeout bounds access; catch-up on reconnect; lag shown in admin UI. |
| Clock drift | Wrong expiry, wrong LWW order. | NTP required, HLC, skew checks. |
| Replication bugs / conflicts | Inconsistent state. | Idempotent events, per-record versions, sticky revocation, periodic checksum compare **(proposal)**. |
| RouterOS container has no security guarantee | Weaker isolation. | Prefer Docker host for exposed setups; no secrets in RouterOS envs. |
| Disconnect-Request reliability | Revoke not immediate. | Test in M2; Session-Timeout as upper bound; retry + log. |
| MAC randomization | Device binding/roaming breaks. | Rebind with code; same SSID name across sites helps **(to verify per OS)**. |
| Captive browsers (OS mini browsers) | JS/cookie limits. | Light pages; test polling in M3. |
| Social login in captive browsers | Mini browsers may block provider pages or pop-ups; Google refuses embedded web views (sign-in fails there). | Social page tells the guest how to get back in the normal browser (close the mini browser, open any website: the hotspot redirects again), without printing a URL (it would carry the MAC and is not typeable); other methods stay available **(to verify on iOS/Android)**. |
| Social login walled garden | Providers change the hosts of their sign-in pages; a missing host leaves the page blank before login. | Host list in one place (`WalledGarden`), documented; re-run the router script after an update. |
| SQLite concurrency | Write locks. | WAL, short transactions; MariaDB option. |
| SMS cost abuse | Cost; an exhausted cap blocks SMS login for all guests of the site/instance. | Country allowlist, rate limits, caps per number/device/site/instance; cap hits logged; code and approval login unaffected. |
| Telegram owner down | No Telegram approvals. | Manual failover; other channels and admin UI still work. |

## 14. Milestones

Build order: single instance first (M1–M6); federation later (M7). Data model is
federation-ready from M1.

Status: M1 done; M2 implemented (v0.2.0), open: Disconnect-Request and login handoff tested on a
real RouterOS router. Deferred from M2: voucher QR, audit log table (M6; M2 logs grant actions to
the application log). M3 implemented (v0.3.0), open: captive browser tests on real devices, router
certificate script on RouterOS; deferred to M6: certificate API pull, guest list with accepted terms.
M4 implemented (v0.4.0), open: tests with a real Telegram group, Gotify server and captive browsers;
deferred: Telegram webhook mode, channel test button (§9, done in M6), forwarding decisions between instances (M7).
M5 implemented (v0.5.0), open: Clickatell request format and delivery with a real account, SMS in
captive browsers; test button for SMS providers implemented (notification channels: M6).
v0.6.0: setup assistant (§9), open: generated commands tested on real RouterOS (CAPsMAN v1/v2, local
radio, DHCP option 114, `/tool fetch` of `login.html`), captive API with real devices.
M6: part A implemented (roles applied to all admin routes, user management, TOTP 2FA,
audit log table replacing the M2 log-only grant audit); part B implemented (backups, certificate pull
from CertWarden/URL, test button for notification channels); part C implemented (guests & sessions
list, bulk codes with A4 voucher sheet, Wi-Fi join QR, CSV export); released as v0.7.0. Part D: OIDC
login for the admin UI (§9), implemented v0.9.0; open: tested with a real Authentik (`amr`/`acr`,
`email_verified`, groups mapping). Part E: MAC whitelist (§5, §9), v0.8.0; open: MAC authentication
(User-Name format, `login-by=mac` before the certificate, reject handling) tested on real RouterOS. Open: first-start web setup, CertWarden pull, backups, voucher QR
scan (iOS/Android) and Disconnect from the list tested on a real setup. Grants have no delete
(revoke only). v0.10.0: branding (§9); v0.11.0: dark/light colour scheme; v0.12.0: site codes (§8); open: captive browsers with custom logos/CSS, file replication (M7).
M7 implemented: parts A (change log, persistent HLC), B (federation data key, cast `federated`), C
(apply engine with conflict rules), D (federation listener, mTLS pin, peer client), E (pairing, federation
page), F (signed events, push/pull/relay, heartbeat, skew and protocol pause), G (apply hooks, remote
sessions and disconnect, home instance, roaming, limited mode), H (Telegram owner and forwarding), I
(branding files), J (tombstone purge, log compaction), K (restore resync, repair command) and L
(integration tests with three simulated instances: partition and rejoin, double redemption, relay, clock
skew, newer schema and protocol, hub takeover, restore; docs/federation.md) implemented; released as
v0.13.0, checked with three containers on one Docker host (pairing, relay, offline SMS-only, catch-up,
restore resync, branding files, hub takeover with re-pairing); v0.13.1: admin login during the restore
resync, skip-resync not undone by heartbeats, branding download size limit without Content-Length. Open: test across real sites (WireGuard, WAN outages), Telegram forwarding with a real
group.
After M7 (v0.14.0-v0.21.0, no new milestone; decisions in §7.2, §8 and the §9 rows "Setup assistant" and
"Toolbox"): read-only router state query per router (REST or API-SSL, access local to the instance) with
commands derived from it and coloured by it (v0.14.0); optional guest Wi-Fi password, shared or local to
the instance (v0.14.1); guarded Wi-Fi block with placeholder inputs (v0.15.0); guest Wi-Fi on several
masters (v0.16.0); detection of unmanaged guest objects, `cfg-guest` removed from CAPsMAN rules on
uninstall (v0.17.0); toolbox with the Wi-Fi QR sheet (v0.18.0); combinable Wi-Fi modes, guest SSID per CAP,
provisioning rule editor and the guided setup wizard (v0.19.0); dynamic hotspot entries ignored (v0.19.1);
SMS code in the domain-bound one-time code format, SMS text editable per locale (v0.19.2-v0.20.1); new
default logo (v0.20.2); site code notice without the sign-in QR (v0.20.3); security review fixes (v0.21.0:
federation security authority §10.6, proxy settings and outbound requests §12, clients file validation,
SMS host allowlist §7.2, per-user TOTP limit and OIDC callback bounce §9; container hardening with CSP and
HSTS per listener §12). Generated scripts verified on a
RouterOS 7.24.5 CHR (`tests/routeros`); open: Wi-Fi modes, per-CAP rules and the wizard on real routers,
CHR run on RouterOS 7.25.
M8 in progress (status 2026-11-02): anonymity and secrets scan of the tree and history done; install docs
exist (docs/docker-host.md, docs/routeros-container.md, docs/federation.md); CONTRIBUTING.md and SECURITY.md
added. Open: screenshots with dummy data, armv7 decision, first release tag (until then the docs pull
`:edge`), image package and repository public, `v1.0.0`.

| # | Content | Acceptance criteria |
|---|---|---|
| M1 | Skeleton, image, CI | Laravel + s6 services (nginx, php-fpm, FreeRADIUS, queue, scheduler) in one Alpine image; ULID/version/tombstone columns in base schema; `/data` persistence; env file generated on first start; separate guest/admin ports; GHCR amd64/arm64 build with tagging scheme incl. weekly rebuild; runs on Docker host and RouterOS test router; memory measured. |
| M2 | RADIUS + codes | Sites/NAS management; clients file generation; rlm_rest authorize/accounting; one-time tokens; code grants fully editable; correct Session-Timeout; Disconnect-Request tested on RouterOS; MAC rebind; rate limits/lockout; feature tests. |
| M3 | Portal UI + i18n | Redirect flow incl. `login-by=https` with router cert; name + versioned terms mandatory; DE/IT/FR/EN auto-detect + switcher; tested in iOS/Android/Windows/macOS captive browsers. |
| M4 | Notifications / approval | Driver interface; Telegram group with chat id + user allowlist check (long polling, optional webhook), Gotify, webhook with HMAC + signed links; waiting page auto-login; duration chosen by approver, time from approval. |
| M5 | SMS | Driver interface; Clickatell; E.164 + country allowlist; OTP 10 min / 3 tries; configurable duration (default 1 d); caps. |
| M6 | Admin extras | Roles admin/manager; vouchers with QR, bulk codes, sessions/guests, audit log, retention purge, backups, RouterOS generator, TOTP, TLS sources with reload, router cert download. |
| M7 | Federation | Join token pairing with mTLS; outbox replication with catch-up; LWW + sticky revocation; roaming login at another site; remote disconnect via serving instance; shared config; Telegram owner; clock skew checks; tests with 3 instances incl. partition and rejoin. |
| M8 | Open-source readiness | CONTRIBUTING, SECURITY policy, install docs (Docker, RouterOS, federation), screenshots with dummy data, anonymity/secrets scan, armv7 decision, `v1.0.0`, repository public. |

## 15. Open questions

- (Resolved 2026-10-31: session summaries stay local; the admin view fetches sessions of other
  instances live from the serving instance, §10.4.)
