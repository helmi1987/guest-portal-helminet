# Concept

Captive portal ("guest login") for MikroTik RouterOS 7 hotspots, multi-site, open-source-ready.
Developed privately first, published later. Facts not yet checked against vendor documentation
are marked **(to verify)**; proposals not yet confirmed by the maintainer are marked **(proposal)**.

## 1. Goals and scope

- Guests on a hotspot network get internet access only after logging in through the portal.
- Login methods: admin-issued code, SMS one-time password (OTP), push approval by an admin.
- Every login requires first name, last name and acceptance of the current terms of use.
- One central installation serves many sites (routers); routers enforce access via RADIUS.
- One container image, configurable via environment, volumes and a web setup; nothing
  site-specific is baked in.
- Small user base: a handful of admins (household / small business).

Out of scope: payment, social login, user self-registration, bandwidth accounting/billing.

## 2. Architecture overview

```
 Guest device ──Wi-Fi/VLAN──> RouterOS hotspot (site A, B, ...)
     │                              │  RADIUS 1812/1813 UDP  ──────────┐
     │  HTTPS 443 (walled garden)   │  <── Disconnect 3799 UDP ───┐    │
     └──────────────────────────────┼─────────────────────────────┼──> Container
                                    │                             │    ├─ nginx (TLS, :443)
 Admin ──VPN / reverse proxy──> admin UI                          │    ├─ php-fpm (Laravel)
                                                                  │    ├─ FreeRADIUS (rlm_rest)
                                                                  └────├─ queue worker
                                                                       ├─ scheduler
                                                                       └─ Telegram poller (optional)
```

### 2.1 Per site (router side)

- Guest SSID/VLAN (CAPsMAN v1/v2 or any AP) on an interface with a RouterOS Hotspot,
  `use-radius=yes`.
- Custom `login.html` immediately redirects to the portal, passing `$(link-login-only)`, `$(mac)`,
  `$(ip)`, `$(link-orig)` and a site identifier (query string).
- Walled garden: only the portal host (HTTPS).
- `login-by=https` with a per-router certificate so the portal (HTTPS) can post to the router
  without mixed-content warnings. `mac-cookie` optional; validity is always governed by RADIUS.
- `/radius incoming` enabled for Disconnect-Request (port 3799).
- Remote sites reach the central container via site-to-site VPN or routed network (not part of
  this project).

### 2.2 Container (central side)

One Alpine Linux based image with:

| Component | Choice | Reason |
|---|---|---|
| Web server | **nginx + php-fpm** (Alpine packages) | Available for amd64, arm64 and armv7 from Alpine repos; small, mature; FrankenPHP is attractive (single binary, built-in TLS) but its static builds / musl performance and armv7 support are less certain **(to verify)**. Re-evaluate FrankenPHP if armv7 is dropped. |
| App | Laravel (full stack), Livewire 3, Tailwind | Single codebase for portal, admin, internal RADIUS API, jobs. |
| RADIUS | FreeRADIUS 3 + `rlm_rest` | Standard, proven; all decision logic delegated to Laravel via internal REST API. |
| Supervisor | **s6-overlay** | Proper PID 1, signal handling, zombie reaping, service dependencies, tiny, no Python runtime (supervisord would add Python). Builds exist for armhf/aarch64/x86_64 **(to verify armv7)**. |
| Queue | Laravel queue, `database` driver | No Redis needed. |
| Scheduler | `php artisan schedule:work` as s6 service | No cron daemon needed. |
| Database | SQLite (WAL) on volume `/data` | Zero ops. Optional MariaDB/MySQL via `DB_CONNECTION` env for larger installs. |
| Cache / rate limiter | `database` (or `file`) driver | Same reason as queue. |

Internal API: nginx exposes a second server block on `127.0.0.1` only (e.g. port 8080) for
FreeRADIUS → Laravel calls, authenticated by a random shared token generated at first start.

FreeRADIUS clients (routers + secrets) are managed in the admin UI. Laravel renders the clients
file into `/data` and restarts FreeRADIUS via s6 on change **(proposal)**; alternative: FreeRADIUS
dynamic clients.

### 2.3 Runtime targets

| Target | Notes |
|---|---|
| Regular Docker host (e.g. Unraid) | `docker run` / compose; healthcheck available; ports mapped. |
| RouterOS container, amd64 (CHR/x86) | Supported. |
| RouterOS container, arm64 | Supported. |
| RouterOS container, armv7 | Alpine ships PHP and FreeRADIUS for armv7 **(to verify packages incl. `freeradius-rest`)**. Main constraint is RAM/storage of armv7 devices, not software. Built only if CI build time and smoke tests are acceptable; otherwise documented as unsupported. |

Estimated memory (assumption, to measure in M1): nginx ~5 MB, php-fpm 2–4 workers à 25–40 MB,
FreeRADIUS ~15 MB, queue/scheduler/poller ~30–40 MB each → roughly 150–250 MB. Requires external
storage (USB/NVMe) on most RouterOS devices.

## 3. RouterOS container specifics

- Container package installed and device mode allows containers
  (`/system/device-mode/update container=yes`, needs physical confirmation) **(to verify per version)**.
- Networking: `/interface/veth` with static address + gateway, added as a port to a dedicated
  bridge (e.g. `containers`) with an IP on the router; firewall/NAT as needed. Container sees
  RADIUS packets with the routers' real source IPs only if no NAT is applied between them.
- Mounts: persistent `/data` (SQLite, generated configs, env file), optional `/certs`,
  optional `/backup`. Root dir on external disk.
- Env: only non-secret bootstrap values via `/container/envs` (they are visible in config
  exports). Secrets go into an env file on the `/data` volume (see §8).
- `start-on-boot=yes`, `logging=yes`; memory limit (`memory-high`/`memory-max` property name
  depends on RouterOS version **(to verify)**).
- Private GHCR: `/container/config set registry-url=https://ghcr.io username=<user>
  password=<read-only PAT>`; once public, no login needed.
- No healthcheck and no compose on RouterOS: the image must self-heal (s6 restarts services) and
  must not rely on orchestration features. Container restart = update path (pull new tag).

## 4. Build and distribution

- GitHub Actions, Docker Buildx + QEMU, push to GHCR.
- Platforms: `linux/amd64`, `linux/arm64`; `linux/arm/v7` evaluated in M1.
- Front-end assets (Tailwind/Vite) and Composer install run in a builder stage on
  `$BUILDPLATFORM` (platform-independent output) to keep emulated builds short.
- Triggers and tags:

| Trigger | Tags |
|---|---|
| Push to `main` | `edge`, `sha-<short>` |
| Git tag `vX.Y.Z` | `X.Y.Z`, `X.Y`, `X`, `latest` |
| Weekly, Monday (cron) | rebuild `edge` from `main` and `latest` from the newest release tag with `--pull` (base image security updates) **(proposal)** |

- Image labels (OCI), SBOM/provenance via buildx; vulnerability scan (e.g. Trivy) as
  non-blocking report **(proposal)**.

## 5. RADIUS design

- Access-Request (PAP over hotspot) → FreeRADIUS → `rlm_rest` → `POST /internal/radius/authorize`.
- Credentials are **one-time portal tokens**: after a successful portal login, Laravel issues a
  random username/password pair valid for ~60 s and single use; the portal page auto-submits it
  to `link-login-only`. Codes, OTPs or phone numbers never go to the router.
- Laravel checks: token valid, NAS known (source IP + NAS-Identifier) and site allowed for the
  grant, `Calling-Station-Id` (MAC reported by the router) matches the token's MAC.
- Access-Accept: `Session-Timeout` = remaining wall-clock seconds of the grant; optional
  `Mikrotik-Rate-Limit` per site **(proposal)**; `Acct-Interim-Interval`.
- Accounting (Start/Interim/Stop) → `POST /internal/radius/accounting`, stored per session.
- Revoke / shorten / rebind: Laravel queues a job that sends Disconnect-Request (e.g. via
  `radclient` from the FreeRADIUS package) to the router's `/radius incoming` port 3799.
  Extension does not need a disconnect if the router re-authenticates; otherwise the guest
  re-logs via mac-cookie or portal **(to verify behaviour of Session-Timeout vs. extension)**.
- `mac-cookie` re-login also results in a RADIUS request, so the grant is re-checked
  **(to verify)**.

## 6. TLS

- Portal served over HTTPS with a publicly trusted certificate for a public DNS name that resolves
  to an internal IP (e.g. `portal.example.com` → `10.0.0.10`), issued via DNS-01.
- Certificate sources (configurable, `TLS_SOURCE`):
  1. `file`: mounted `fullchain.pem` + `privkey.pem` in `/certs`.
  2. `api`: periodic pull from a generic certificate API (URL + bearer token, PEM response).
  3. `acme`: built-in ACME client with DNS-01 provider plugin (e.g. lego) **(proposal)**.
- A watcher reloads nginx when the certificate changes. Fallback: self-signed cert so setup works.
- Each router needs its own certificate for `login-by=https` (router hostname, e.g.
  `hotspot-a.example.com`). The generator documents import; automating it is an open question.

## 7. Guest login

### 7.1 Common rules

- Entry: `login.html` redirect → `GET /portal?site=<id>&mac=..&ip=..&link-login-only=..&link-orig=..`.
  URL parameters are untrusted hints for UX; the binding MAC is the one RADIUS reports.
- Required for all methods: first name, last name, acceptance of current terms version.
- Languages DE, IT, FR, EN (Laravel lang files); auto-detect via `Accept-Language`, switcher.
- After success: one-time RADIUS credentials → auto-submit form to router → redirect to
  `link-orig` or a success page.

### 7.2 Sequences

**A. Code (6 digits, admin-issued)**
1. Guest enters name, accepts terms, enters code.
2. Laravel checks rate limits, code status, validity window, site restriction.
3. If the code is bound to another MAC with an active session: send Disconnect-Request for the
   old session, rebind code to new MAC.
4. On first redemption with `expiry_mode=first_login`: set `expires_at = now + duration`.
5. Issue one-time RADIUS token → auto-submit → router Access-Request → Accept with Session-Timeout.

**B. SMS OTP (Clickatell, driver interface)**
1. Guest enters name, phone number, accepts terms.
2. Laravel rate-limits per phone/MAC/IP, creates OTP (valid 10 min, max 3 tries), queues SMS.
3. Guest enters OTP; on success a grant is created (duration from site default).
4. Steps 5 of A.

**C. Push approval (Telegram / Gotify / webhook, driver interface)**
1. Guest enters name (optional message), accepts terms, requests access.
2. Laravel creates `approval_request` (status pending, expires e.g. after 15 min) and notifies all
   enabled channels.
3. Admin approves/denies and chooses duration:
   - Telegram: inline buttons (durations + deny); bot receives callbacks via long polling
     `getUpdates` (optional webhook mode).
   - Gotify / generic webhook: message contains signed, time-limited approve/deny links that open
     a small confirmation page (duration picker).
4. Guest waiting page polls status (Livewire `wire:poll`); on approval a grant is created.
5. Step 5 of A, triggered automatically from the waiting page.

### 7.3 Drivers

| Interface | Drivers (initial) | Notes |
|---|---|---|
| `SmsDriver` | Clickatell REST | Outbound only; more providers later. |
| `NotificationChannel` | Telegram, Gotify, generic webhook | Webhook: JSON body + `X-Signature` HMAC-SHA256, timestamp header against replay. |

## 8. Codes / access grants

All login methods produce a **grant**; a code is a grant created in advance by an admin.

| Field | Notes |
|---|---|
| Duration | Presets 8 h, 1 d, 1 w; custom (special code) any duration. |
| Expiry mode | `first_login` (default) or `creation`, per grant. Wall-clock. |
| Validity window | `valid_from`, `redeem_until`. |
| Sites | All or selected sites. |
| Label / note | Free text. |
| Extensions | Optional max number of guest-visible extensions **(semantics to define)**. |

- All fields stay editable after redemption: extend, shorten, revoke, change expiry. Shortening /
  revoking triggers Disconnect-Request if the remaining time is ≤ 0.
- 1 grant = 1 active device (MAC). New MAC with same code → old session disconnected, rebind.
  No IP binding.
- Brute force: rate limit per MAC and per IP, global rate limit, lockout after N failures
  (configurable, e.g. 5 per 15 min per MAC), OTP 10 min / 3 tries. Codes avoid ambiguous patterns
  (e.g. `000000`).

## 9. Admin

- Few admin accounts, single role `admin`. 2FA (TOTP) strongly recommended, enforceable by setting.
- Access only via VPN or reverse proxy. Admin routes restricted by `ADMIN_ALLOWED_CIDRS`
  and/or served on a separate listener port **(proposal)**. Behind a proxy set `TRUSTED_PROXIES`
  so client IPs are correct for rate limits and audit log.
- First-start web setup: create first admin (one-time setup token printed to container log),
  app URL, TLS source.

Features:

| Area | Content |
|---|---|
| Sites | Name, site identifier, routers (NAS IP, NAS-Identifier, RADIUS secret per router, CoA port), default SMS grant duration, enabled login methods. |
| Codes | Create single/bulk, print vouchers (QR with portal URL + code), edit, revoke. |
| Sessions | Active sessions (site, MAC, IP, start, remaining), revoke. |
| Guests | Guest list, terms version accepted, grants history. |
| Approvals | Pending requests (approve/deny in UI as well). |
| Channels | Settings per notification channel and SMS provider, test button. |
| Terms | Text per language, versioned; publishing a new version requires re-acceptance on next login. |
| Retention | Retention period (default 1 year); nightly purge job. |
| Backup | Scheduled SQLite backup (`VACUUM INTO`/online backup) to `/backup` (e.g. NAS share), keep N copies; manual download. |
| Audit log | Admin actions, logins, revocations, setting changes. |
| RouterOS generator | Per site: copy-paste script for hotspot, RADIUS client, `/radius incoming`, walled garden, plus `login.html` download and upload instructions. |

## 10. Data model

| Table | Key fields |
|---|---|
| `users` | id, name, email, password, totp_secret (encrypted), last_login_at |
| `sites` | id, slug (site identifier), name, settings (json), sms_grant_duration |
| `nas_clients` | id, site_id, nas_ip, nas_identifier, radius_secret (encrypted), coa_port |
| `guests` | id, first_name, last_name, phone (nullable), locale, created_at |
| `grants` | id, type (code/sms/approval), code (nullable, unique), label, note, duration_s, expiry_mode, valid_from, redeem_until, expires_at, first_login_at, revoked_at, max_extensions, extensions_used, created_by |
| `grant_site` | grant_id, site_id (empty = all sites) |
| `devices` | id, grant_id, guest_id, mac, bound_at, unbound_at |
| `radius_tokens` | id, grant_id, device_id, username, password_hash, mac, site_id, expires_at, used_at |
| `radius_sessions` | id, grant_id, nas_client_id, acct_session_id, mac, ip, started_at, last_update_at, stopped_at, terminate_cause, bytes_in, bytes_out |
| `otp_challenges` | id, guest_id, phone, code_hash, attempts, expires_at, verified_at |
| `approval_requests` | id, guest_id, site_id, mac, status, decided_by, decided_via, duration_s, expires_at |
| `terms_versions` | id, locale, version, body, published_at |
| `terms_acceptances` | id, guest_id, terms_version_id, accepted_at, ip, mac |
| `notification_channels` | id, driver, name, config (encrypted json), enabled |
| `sms_providers` | id, driver, config (encrypted json), enabled |
| `login_attempts` | id, mac, ip, site_id, method, success, created_at |
| `audit_logs` | id, user_id (nullable), action, subject_type, subject_id, data (json), ip, created_at |
| `settings` | key, value (json) |
| Laravel defaults | `jobs`, `failed_jobs`, `cache`, `sessions`, `migrations` |

Purge job removes guests, devices, sessions, attempts, acceptances and audit entries older than
the retention period (grants still active are kept).

## 11. Security

- **Guest isolation:** guest VLAN may reach only the portal on TCP 443 (walled garden + firewall)
  and DNS on the router; no access to other LAN/VLANs, router management, or the container's
  other ports. Client isolation on the AP recommended.
- **RADIUS:** UDP 1812/1813 to the container and 3799 to routers allowed only between router IPs
  and the container (firewall on both sides). Strong per-router secrets. Message-Authenticator
  required (Blast-RADIUS mitigation) **(to verify RouterOS support)**.
- **Secrets:** `APP_KEY`, internal API token, DB credentials in an env file on the `/data` volume
  (generated on first start), not in RouterOS container envs or the image. API tokens/passwords
  stored with Laravel `encrypted` casts. Losing `APP_KEY` = re-entering channel secrets.
- **Admin:** VPN/reverse proxy only, IP allowlist, TOTP, login throttling, audit log.
- **Web:** CSRF, strict CSP, HSTS, signed URLs for approve/deny links, HMAC-signed outbound
  webhooks, no third-party assets (all bundled; guests have no internet before login anyway).
- **Privacy:** minimal data (name, phone for SMS only, MAC), configurable retention, purge job.
- **Supply chain:** pinned base image digest updated by Renovate/Dependabot **(proposal)**,
  weekly rebuild, Composer/npm lockfiles.

## 12. Risks and limits

| Risk | Impact | Mitigation |
|---|---|---|
| Central site is a single point of failure | All sites lose new guest logins if container/VPN is down; existing sessions continue until timeout. | Document; optional fallback per site (e.g. hotspot local user) **(open)**; backups for fast restore. |
| RouterOS container has no security guarantee | Container features are less isolated / audited than a regular host. | Prefer Docker host for exposed setups; minimal privileges; no secrets in RouterOS envs. |
| Disconnect-Request reliability | Revoke might not take effect immediately. | Test in M2; Session-Timeout as hard upper bound; retry + log failures. |
| MAC randomization | New MAC per network/day breaks device binding and mac-cookie. | 1 code = 1 device with rebind; guests can re-enter code. |
| Captive portal detection (OS mini browsers) | Cookies/JS limitations, auto-close after login. | Keep pages light, Livewire polling tested in CNA browsers **(to verify)**. |
| Small device resources (armv7) | OOM, slow PHP. | Memory limits, few workers; mark armv7 experimental. |
| SQLite concurrency | Write locks under load. | WAL, short transactions, busy timeout; MariaDB option. |
| SMS cost abuse | Cost. | Per-phone/site rate limits, daily cap, country allowlist. |

## 13. Milestones

| # | Content | Acceptance criteria |
|---|---|---|
| M1 | Skeleton, image, CI | Laravel app + s6 services (nginx, php-fpm, FreeRADIUS, queue, scheduler) start in one Alpine image; `/data` persistence; env file generated on first start; GHCR build for amd64/arm64 on push and weekly; armv7 feasibility decided; image runs on Docker host and on a RouterOS test router; memory measured. |
| M2 | RADIUS + codes | Sites/NAS management; clients file generation; rlm_rest authorize/accounting; one-time tokens; code grants with all fields editable; Session-Timeout correct; Disconnect-Request tested on RouterOS; MAC rebind works; rate limits/lockout; feature tests. |
| M3 | Portal UI + i18n | Redirect flow from `login.html` incl. `login-by=https`; name + terms (versioned) mandatory; DE/IT/FR/EN with auto-detect + switcher; works in iOS/Android/Windows/macOS captive browsers. |
| M4 | Notifications / approval | Driver interface; Telegram (long polling + inline buttons, optional webhook), Gotify, generic webhook with HMAC + signed links; waiting page auto-logs in; duration chosen by admin. |
| M5 | SMS | Driver interface; Clickatell driver; OTP 10 min / 3 tries; rate limits and caps. |
| M6 | Admin extras | Voucher print with QR, bulk codes, sessions/guests views, audit log, retention purge, scheduled + manual backup, RouterOS config generator per site, TOTP 2FA, TLS sources incl. reload. |
| M7 | Open-source readiness | License, CONTRIBUTING, SECURITY policy, install docs (Docker + RouterOS), screenshots with dummy data, full anonymity/secrets scan, semver release `v1.0.0`, repository public. |

## 14. Open questions

- License: TBD (proposal will follow separately).
- armv7: support, experimental, or drop (decide after M1 measurement).
- FrankenPHP vs nginx+php-fpm: confirm nginx+php-fpm.
- FreeRADIUS clients: generated file + restart vs dynamic clients.
- Admin separation: separate port vs path + CIDR allowlist.
- Grant duration for SMS logins (per site default? choices for the guest?).
- "Number of extensions": who extends (guest self-service or admin only) and by how much.
- Expiry for approval grants: always from approval time?
- Router certificates for `login-by=https`: manual import, or automated distribution from the container.
- Built-in ACME client (which one, which DNS providers) or only file/API sources.
- Fallback behaviour per site when the central container is unreachable.
- Weekly rebuild scope: `edge` only or also latest release tag.
- Admin approval in Telegram: which chats/users may approve (allowlist of chat IDs).
- Bandwidth limits per site/grant (`Mikrotik-Rate-Limit`): needed?
- Phone number handling: country allowlist, display/storage format, retention separately from names?
