# Concept

Captive portal ("guest login") for MikroTik RouterOS 7 hotspots, multi-site, open-source-ready.
Developed privately first, published later. Facts not yet checked against vendor documentation
are marked **(to verify)**; proposals not yet confirmed by the maintainer are marked **(proposal)**.

## 1. Goals and scope

- Guests on a hotspot network get internet access only after logging in through the portal.
- Login methods: admin-issued code, SMS one-time password (OTP), push approval by an admin.
- Every login requires first name, last name and acceptance of the current terms of use.
- Multi-site: one instance can serve several sites, or each site runs its own self-sufficient
  instance; instances can join a **federation** so grants are valid everywhere (§10).
- One container image, configurable via environment, volumes and a web setup; nothing
  site-specific is baked in.
- Small user base: a handful of admins/managers (household / small business).

Out of scope: payment, social login, user self-registration, billing.
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
| Scheduler | `php artisan schedule:work` as s6 service | No cron daemon. |
| Database | SQLite (WAL) on volume `/data` | Zero ops. Optional MariaDB/MySQL via `DB_CONNECTION` env for larger installs. |

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
  `/radius incoming` port 3799. After an extension the current session ends at the old
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

| Responsibility | MikroTik RouterOS 7 driver |
|---|---|
| Parse captive-portal redirect parameters | `login.html` query: `link-login-only`, `mac`, `ip`, `link-orig`, site id |
| Post-portal login handoff | Auto-submit one-time credentials to `link-login-only` |
| Vendor RADIUS attributes | e.g. `Mikrotik-Rate-Limit` |
| Disconnect capability | RFC 5176 via `/radius incoming` |
| Config generator | RouterOS script + `login.html` (§9) |

Only the MikroTik RouterOS 7 driver ships. Other drivers (e.g. UniFi, Omada, OPNsense,
CoovaChilli) are future/community work.

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
  or `url` (generic URL returning a PEM bundle, optional bearer token). API key encrypted (APP_KEY),
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
  (fetch with `src-address`; check off by default behind `TRUSTED_PROXIES`, env
  `PORTAL_ROUTER_CERT_CHECK_IP`); denied requests are logged. Key files (decided M6): all keys in
  `/data/certs` (`portal.key`, `router.key`, `selfsigned.key`, operator-provided or pulled) are set to
  `root:portal 640`, certificates `root:portal 644`, so the app can serve the router key, compare
  pulled files and include them in backups; legacy keys in `/certs` stay 600. The generated script is idempotent (removes its own entries named or
  commented `guest-portal` first, incl. hotspot profile `guest-portal`). The script (scheduler at startup + every `PORTAL_ROUTER_CERT_INTERVAL`,
  default 1d) compares the SHA-256 fingerprint and, when changed, imports the files and sets the
  hotspot profile `ssl-certificate` **(RouterOS script syntax to verify)**.

## 7. Guest login

### 7.1 Common rules

- Entry: `login.html` redirect → `GET /portal?site=<id>&mac=..&ip=..&link-login-only=..&link-orig=..`.
  URL parameters are untrusted UX hints; the binding MAC is the one RADIUS reports.
- Required for all methods: first name, last name, acceptance of current terms version.
- Languages DE, IT, FR, EN (Laravel lang files); auto-detect via `Accept-Language`, switcher.
- After success: one-time RADIUS credentials → auto-submit to router → status page (implemented M3:
  router `dst` = signed portal URL valid until grant expiry + 1 h, no cookie needed; remaining time, waiting page until RADIUS
  accepted the token). `link-orig` is not used. Friendly error pages: no hotspot redirect,
  misconfigured hotspot host / unknown site, expired, revoked, lockout, router login not completed.
- Guest pages are plain server-rendered HTML (no Livewire); only the handoff auto-submit uses inline
  JS, with a button as fallback.

### 7.2 Sequences

**A. Code (6 digits, admin/manager-issued)**
1. Guest enters name, accepts terms (current version of the guest's locale, fallback default locale;
   the submitted version must still be current), enters code. The acceptance records guest, terms
   version, grant, site, MAC and time. Without any published terms a generic statement is accepted
   (version null).
2. Instance checks rate limits, code status, validity window, site restriction.
3. If the code is bound to another MAC with an active session: Disconnect-Request for the old
   session (via its serving instance, §10.4), rebind code to the new MAC.
4. On first redemption with `expiry_mode=first_login`: `expires_at = now + duration`.
5. Issue one-time RADIUS token → auto-submit → Access-Request → Accept with Session-Timeout.

**B. SMS OTP (Clickatell, driver interface)**
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
   supported), first enabled SMS provider by name.
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

### 7.3 Drivers

| Interface | Drivers (initial) | Notes |
|---|---|---|
| `SmsDriver` | Clickatell REST, generic SMS webhook | Outbound only; more providers later. Clickatell: API key in `Authorization` header, endpoint configurable; request/response format **(to verify)** with a real account. SMS webhook: JSON `{event, to, text}` signed like the notification webhook. |
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
  unrestricted) have exactly one distinct SSID; otherwise the card shows the code only. No portal URL /
  `?code=` pre-fill in the QR: a URL opened before joining the guest network cannot reach the portal,
  after joining the hotspot redirect replaces it (the original URL only survives in `link-orig`, which
  is unused by design), so the guest types the printed code. CSV export (admin only; code, label,
  status, duration, expiry mode, validity, sites, created; formula prefixes neutralised; audited
  `grant.exported` with count).
- Brute force: rate limit per MAC and IP, global rate limit, lockout after N failures
  (configurable, e.g. 5 per 15 min per MAC), OTP 10 min / 3 tries. Trivial codes excluded.

## 9. Admin

- Admin UI on its **own port** (default 8443), guest portal on its own port (443): firewall rules
  decide reachability. Access via VPN or reverse proxy; behind a proxy set `TRUSTED_PROXIES`.
- 2FA (TOTP) strongly recommended, enforceable by setting (implemented M6: enrolment in the own
  profile with QR code, RFC 6238 ±1 step, each step accepted once, 8 hashed single-use recovery codes,
  disable with password + code; login asks for the code after the password (5 failures: start over
  with the password; per-IP limit), no "remember me"; password or 2FA changes and deletion end the
  user's other sessions and rotate the remember token; failed-login audit rows capped per hour; setting
  `require_2fa` applies to all admin UI users (both roles) and sends users without 2FA to their profile;
  `artisan portal:admin --reset-2fa <email>` and the user page reset it).
- First-start web setup: first admin (one-time setup token in container log), URLs, TLS source,
  "create federation" or "join federation".
  Until it exists (and later as fallback), `artisan portal:admin` creates an admin or manager
  from the container shell.

Roles:

| Role | UI label (DE) | Permissions |
|---|---|---|
| `admin` | Admin | Everything. |
| `manager` | Verwaltung | Create/print/extend/shorten/revoke codes and grants, approve requests, view sessions and guests (phone numbers masked), MAC whitelist. No settings, sites, setup, terms, channels, users, audit log, federation. |

User management (admin only, M6): create/edit/delete users, password min. 12 characters; nobody can
delete or demote themselves, the last admin cannot be removed.

Features:

| Area | Content |
|---|---|
| Sites | Name, identifier, NAS driver, home instance, routers (NAS IP, NAS-Identifier, RADIUS secret per router, CoA port), enabled login methods. |
| Codes | Create single/bulk (1-100), print vouchers (A4 sheet, Wi-Fi join QR + code, §8), CSV export (admin only), edit, revoke. |
| Guests & sessions | Implemented M6 (admin + manager), one page: guests newest first (each login creates a guest row) with name, phone (masked for managers), login method (grant type; without grant: approval request or SMS challenge), site and accepted terms version + time of the latest acceptance, created; its grant (code/status/remaining, extend and revoke via the grant routes) and devices (MAC, online = open session summary of grant + MAC, IP, start/end, traffic from local accounting `bytes_in`/`bytes_out`). An open session without accounting update for `PORTAL_RADIUS_STALE_AFTER` (default 3 x interim interval = 900 s) is shown as "stale" (Stop probably lost; display only, data unchanged). Disconnect: Disconnect-Request job for an open session served by this instance (sessions of other instances: M7); the grant stays valid, audited `grant.disconnected`. Filters: site, method, running grants only, name (LIKE wildcards literal) or exact code; invalid filter values are ignored; 25 per page. Extend/shorten takes whole hours; a revoked grant cannot be extended (422). No new fields. |
| MAC whitelist | Implemented v0.8.0 (admin + manager, §5): list with filters (MAC/comment search, site, status active/inactive/expired), online state, create/edit/delete, activate/deactivate; guests list: per device "allow this device permanently" (link carries the device id only; the form is prefilled server-side with MAC, guest name as comment, site of the latest session or acceptance) or a "Whitelist" badge. Whitelist sessions are shown on the whitelist page, not in the guest list (no guest). Audited `whitelist.created/updated/activated/deactivated/deleted` (no comment text). |
| Approvals | Pending requests (approve/deny in UI as well). |
| Channels | Notification channels and SMS provider, test button (SMS: test SMS to a number; notification channels: test message, implemented M6; errors shown as HTTP status only, audited). |
| Terms | Text per language, versioned; new version requires re-acceptance on next login. |
| Retention | Retention period (default 1 year); nightly purge job. |
| Backup | Implemented M6, per instance: daily `portal:backup` (02:45) to `/data/backups` (may be a bind mount to a NAS share): one `portal-backup-<UTC timestamp>.tar.gz` (mode 0600) with SQLite snapshot (`VACUUM INTO` on an own connection, WAL-safe), `.env` (APP_KEY) and `certs/`; entries mirror `/data`; files unreadable for the app user, symlinks and names outside `[A-Za-z0-9._-]{1,94}` or with `..` are skipped and listed in `backup-manifest.json`. Keep N (local setting, default 14; sorted by timestamp + counter). Admin page (admin only): list, create now, download, delete, retention, warning if the folder is not 0700 / not owned by the app user; create/download/delete and failures (incl. scheduled) audited. Restore by CLI (stop container, extract into `/data`), no `portal:restore` (needs stopped services). SQLite only. |
| Audit log | Admin/manager actions, logins, revocations, setting changes (implemented M6): code create (bulk: one entry with count)/edit/extend/expiry/revoke/disconnect/CSV export, approval decisions (admin UI, Telegram, signed link; actor label of the channel user), MAC whitelist changes, settings, sites, routers, setup inputs, terms, channels (incl. tests), users, 2FA enable/disable/reset, login success/failure, logout, backups (create/download/delete), certificate sources, pulls and updates. `data` never holds secrets (keys like password/secret/token are stripped) nor phone numbers. Admin page with filters (area, user, date, object) and pagination; purged with the retention period. |
| Federation | Peers, status, lag, join token, Telegram owner. |
| RouterOS generator | Per site: copy-paste script (hotspot, RADIUS client, `/radius incoming`, walled garden, cert fetch), `login.html` download and upload instructions. |
| Setup assistant | Per site (implemented v0.6.0): guided steps with copy buttons, commands generated by the NAS driver from saved inputs (sanitized): 1 RouterOS container (optional, inputs local to the instance; Docker host as alternative), 2 guest network (VLAN, bridge, subnet, gateway, DHCP pool, SSID, Wi-Fi mode CAPsMAN v1 / wifi CAPsMAN / local radio; stored in site setting `setup`, also fill the router script), DHCP option 114, firewall isolation, 3 hotspot + DNS names (no captive check hosts in the walled garden), 4 router script, 5 `login.html` via `/tool fetch` (route `/router-login/{nas}/login.html`, router token + NAS IP like the certificate download), 6 status per router (last RADIUS contact, certificate and `login.html` fetched), 7 diagnostics. |
| Captive Portal API | RFC 8908 JSON at `/captive/{site}` (guest port, stateless), announced by DHCP option 114 (RFC 8910): `captive` false while the client IP has a live RADIUS session at the site, `user-portal-url` = hotspot login URL. Needs real guest IPs (no source NAT of guests). |

## 10. Federation (multi-instance)

### 10.1 Model

- Each instance is fully self-sufficient: own portal, FreeRADIUS, DB. A site keeps working
  when WAN or other instances are down. A single instance serving several sites stays supported
  (federation of one).
- Recommended: **multi-master** replication. Every instance's admin UI shows and edits the
  federated state; writes are applied locally and replicated. Reasons: no single point of
  failure for admin writes, a cut-off site can still issue codes, data volume and write rate are
  tiny, conflicts are rare with few admins. Rejected alternative: designated primary for writes
  (simpler conflicts, but site admin work stops when the primary is unreachable).
- "One admin UI": logically one; any instance (or one behind a reverse proxy) can be used.

### 10.2 Trust and transport

- Federation API on its own port (default 9443), mTLS with per-instance self-signed certificates;
  peers pin each other's certificate fingerprint.
- Pairing: an admin creates a one-time join token (short-lived, single use) on an existing
  instance; the new instance presents token + its certificate; the existing instance returns peer
  list, pinned certs, federation data key and a snapshot. The join is then announced to all peers.
- Federation data key: shared key used for `encrypted` casts of replicated fields (channel
  secrets, TOTP secrets), distinct from the local `APP_KEY`. Removing a peer = tombstone its
  instance record (pin revoked everywhere) and rotate the data key **(proposal)**.
- Full mesh (few instances): push events to all peers immediately; pull catch-up on reconnect.

### 10.3 Replication

- Each instance appends changes to an **outbox event log** with a per-origin sequence number.
  Peers keep a cursor per origin (`GET /federation/events?origin=..&after=..`); events are
  idempotent, so retransmissions are harmless and offline instances catch up later.
- Records keyed by ULID; each replicated row has `origin_instance_id`, `version` (hybrid logical
  clock timestamp + instance id as tie-break), `updated_at`, `deleted_at` (tombstone).
- Conflicts: last-writer-wins per record by `version`; exception: **revocation is sticky**
  (a revoked grant stays revoked unless an explicit later un-revoke). Duplicate 6-digit codes
  created concurrently on partitioned instances: both stay valid as separate grants; redemption
  picks the unredeemed one allowed at the site; admin UI shows a conflict marker (decided; no
  code-range partitioning).
- MAC whitelist (M7, to implement): entries for the same MAC created on partitioned instances must be
  resolved when applying events: the newer version wins, the other is tombstoned (unique MAC among
  non-deleted rows). Until then lookups take the newest version.
- Clocks: NTP required on all instances (wall-clock expiry). Heartbeats compare clocks; above a
  configurable skew (e.g. 30 s) the admin UI warns, above a hard limit replication pauses.

| Replicated | Local only |
|---|---|
| Grants/codes, site restrictions, revocations, device (MAC) bindings, MAC whitelist + its site restriction, guests, terms versions + acceptances, users/roles, sites (catalog), shared config (Telegram, Clickatell, Gotify, webhook, languages, retention, SMS duration, country allowlist), audit log | RADIUS tokens, OTP challenges, approval requests, login attempts/rate limits, NAS clients + secrets, raw accounting, TLS/ports/backup settings, local env |
| Session summaries (grant, MAC, site, serving instance, start, stop) | Accounting details (interim updates, counters) |

### 10.4 Roaming and remote actions

- Guest with a valid grant arrives at site B: the hotspot redirects to B's portal; B finds an
  active grant bound to the reported MAC and allowed at site B and immediately issues a one-time
  token (no guest input, "welcome back" page with auto-submit). RADIUS at B verifies
  `Calling-Station-Id` against the replicated binding. Works offline from other peers as long as
  the grant was replicated before.
- No MAC-only login for grants: roaming guests always pass briefly through the portal (decided).
  `login-by=mac` serves only the admin-managed MAC whitelist (§5).
- Disconnects are sent only by the instance serving the session (known from session summaries).
  A revoke/rebind on another instance emits an event; the serving instance disconnects on
  receipt. Partitioned site: revocation applies on reconnect; until then Session-Timeout bounds
  access (known limit).

### 10.5 Notifications and Telegram ownership

- SMS, Gotify and webhook messages are sent only by the instance where the guest is waiting.
- Telegram `getUpdates` must run on exactly one instance: the **Telegram owner**, a replicated
  setting chosen by an admin (manual switch in the admin UI, decided). Any instance can
  send the group message (`sendMessage`); callback data carries request id + origin instance; the
  owner verifies chat/user and forwards the decision as an event to the origin instance.
- Signed approve/deny links (Gotify/webhook) are verifiable by any instance; decision forwarded to origin.

## 11. Data model

Replicated tables (marked R) use ULID keys plus `origin_instance_id`, `version`, `updated_at`,
`deleted_at`. Designed from M1 on so federation fits later.

| Table | Key fields |
|---|---|
| `instances` R | id, name, federation_url, cert_fingerprint, last_seen_at, clock_skew_ms |
| `users` R | id, name, email, password, role (admin/manager), totp_secret (enc), totp_confirmed_at, totp_last_step, recovery_codes (hashed) |
| `sites` R | id, slug, name, driver (e.g. `mikrotik_ros7`), home_instance_id, settings (json) |
| `nas_clients` | id, site_id, name, nas_ip, nas_identifier, radius_secret (enc), coa_port, cert_token (enc), last_seen_at (RADIUS contact, max. 1 write/min), cert_fetched_at, login_page_fetched_at |
| `guests` R | id, first_name, last_name, phone_e164 (nullable), locale |
| `grants` R | id, type (code/sms/approval), code (nullable), label, note, duration_s, expiry_mode, valid_from, redeem_until, expires_at, first_login_at, revoked_at, max_extensions, extensions_used, rate_limit, created_by |
| `grant_site` R | grant_id, site_id (none = all sites) |
| `devices` R | id, grant_id, guest_id, mac, bound_at, unbound_at |
| `session_summaries` R | id, grant_id (null for whitelist sessions), mac_whitelist_id, site_id, serving_instance_id, mac, ip, started_at, stopped_at |
| `mac_whitelist` R | id, mac (AA:BB:CC:DD:EE:FF, unique among non-deleted), comment, expires_at, active, created_by |
| `mac_whitelist_site` R | mac_whitelist_id, site_id (none = all sites) |
| `radius_tokens` | id, grant_id, guest_id, device_id, username, password_hash, mac, site_id, expires_at, used_at |
| `radius_accounting` | id, session_summary_id, nas_client_id, acct_session_id, username, last_update_at, terminate_cause, bytes_in, bytes_out, disconnect_sent_at |
| `otp_challenges` | id, guest_id, site_id, mac, phone_e164, code_hash, attempts, sends, last_sent_at, expires_at, verified_at, grant_id; local, with replication columns |
| `sms_messages` | id, otp_challenge_id, site_id, channel_id, mac, phone_e164, status, provider_id, error (local: cost caps, delivery result) |
| `approval_requests` | id, guest_id, site_id, mac, status, decided_by, decided_via, decided_at, duration_s, grant_id, expires_at, meta (json: channel message ids); local, but with replication columns (origin needed to route decisions, §10.5) |
| `terms_versions` R | id, locale, number (per locale; `version` is the replication column), body (plain text), published_at |
| `terms_acceptances` R | id, guest_id, terms_version_id, grant_id, accepted_at, site_id, mac |
| `channels` R | id, kind (notification/sms), driver, name, config (enc json), enabled |
| `settings` R / `local_settings` | key, value (json) |
| `login_attempts` | id, mac, ip, site_id, method, success, created_at |
| `audit_logs` R | id, user_id, actor (label), action, subject_type, subject_id, data (json), ip, created_at |
| `federation_events` / `peer_cursors` | outbox (origin, seq, table, record_id, version, payload) / cursor per origin |
| Laravel defaults | `jobs`, `failed_jobs`, `cache`, `sessions`, `migrations` |

Purge job removes guests, devices, summaries, accounting, attempts, acceptances, OTP challenges,
sent SMS records, audit entries and deleted MAC whitelist entries/site links older than the retention period (active grants kept); tombstones
are purged after all peers have acknowledged them.

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
- **Admin:** separate port, TOTP, login throttling, roles, audit log.
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
- **Federation:** mTLS with pinned certs, one-time join tokens, idempotent signed events, clock
  skew checks.
- **Web/privacy:** CSRF, strict CSP, HSTS, signed URLs, HMAC webhooks, bundled assets; minimal data, retention + purge.
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
login for the admin UI (v0.7.x). Part E: MAC whitelist (§5, §9), v0.8.0; open: MAC authentication
(User-Name format, `login-by=mac` before the certificate, reject handling) tested on real RouterOS. Open: first-start web setup, CertWarden pull, backups, voucher QR
scan (iOS/Android) and Disconnect from the list tested on a real setup. Grants have no delete
(revoke only).

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

None at this time.
