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
  (`/system/device-mode/update container=yes`, needs physical confirmation) **(to verify per version)**.
- Networking: `/interface/veth` with static address + gateway, port of a dedicated bridge
  (e.g. `containers`) with an IP on the router; firewall/NAT as needed. No NAT between routers and
  the instance for RADIUS (source IP identifies the NAS).
- Mounts: persistent `/data` (SQLite, generated configs, env file), optional `/certs`, `/backup`.
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
- Certificate sources (`TLS_SOURCE`): `file` (mounted `fullchain.pem` + `privkey.pem` in `/certs`)
  or `api` (periodic pull from a generic certificate API: URL + bearer token, PEM response).
  A watcher reloads nginx on change; self-signed fallback so setup works.
- No built-in ACME client in v1 (recommendation): keeps the image small; any external ACME client
  can write the files or serve the API. Revisit on demand.
- Router certificates for `login-by=https` (decided): one wildcard certificate (e.g.
  `*.guest.example.com`) obtained the same way; routers use names like `hotspot-a.guest.example.com`.
  The instance offers the cert to its routers via a token-protected download; the generated
  RouterOS script includes a scheduler entry that fetches and imports it periodically (decided).

## 7. Guest login

### 7.1 Common rules

- Entry: `login.html` redirect → `GET /portal?site=<id>&mac=..&ip=..&link-login-only=..&link-orig=..`.
  URL parameters are untrusted UX hints; the binding MAC is the one RADIUS reports.
- Required for all methods: first name, last name, acceptance of current terms version.
- Languages DE, IT, FR, EN (Laravel lang files); auto-detect via `Accept-Language`, switcher.
- After success: one-time RADIUS credentials → auto-submit to router → `link-orig` or success page.

### 7.2 Sequences

**A. Code (6 digits, admin/manager-issued)**
1. Guest enters name, accepts terms, enters code. (M2: terms checkbox only; versioned terms in M3.)
2. Instance checks rate limits, code status, validity window, site restriction.
3. If the code is bound to another MAC with an active session: Disconnect-Request for the old
   session (via its serving instance, §10.4), rebind code to the new MAC.
4. On first redemption with `expiry_mode=first_login`: `expires_at = now + duration`.
5. Issue one-time RADIUS token → auto-submit → Access-Request → Accept with Session-Timeout.

**B. SMS OTP (Clickatell, driver interface)**
1. Guest enters name, phone number, accepts terms.
2. Number normalised to E.164 and checked against the country allowlist (default CH, DE, AT, IT,
   FR; configurable). Rate limit per phone/MAC/IP; OTP created (10 min, max 3 tries); SMS queued.
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
     Updates via long polling `getUpdates` (optional webhook mode).
   - Gotify / generic webhook: signed, time-limited approve/deny links to a confirmation page
     (duration picker) on the admin port.
4. Guest waiting page polls status (Livewire `wire:poll`); on approval a grant is created,
   time running from approval.
5. Step 5 of A, triggered automatically from the waiting page.

### 7.3 Drivers

| Interface | Drivers (initial) | Notes |
|---|---|---|
| `SmsDriver` | Clickatell REST | Outbound only; more providers later. |
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
- Brute force: rate limit per MAC and IP, global rate limit, lockout after N failures
  (configurable, e.g. 5 per 15 min per MAC), OTP 10 min / 3 tries. Trivial codes excluded.

## 9. Admin

- Admin UI on its **own port** (default 8443), guest portal on its own port (443): firewall rules
  decide reachability. Access via VPN or reverse proxy; behind a proxy set `TRUSTED_PROXIES`.
- 2FA (TOTP) strongly recommended, enforceable by setting.
- First-start web setup: first admin (one-time setup token in container log), URLs, TLS source,
  "create federation" or "join federation".
  Until it exists (and later as fallback), `artisan portal:admin` creates an admin or manager
  from the container shell.

Roles:

| Role | UI label (DE) | Permissions |
|---|---|---|
| `admin` | Admin | Everything. |
| `manager` | Verwaltung | Create/print/extend/shorten/revoke codes and grants, approve requests, view sessions and guests. No settings, sites, channels, users, federation. |

Features:

| Area | Content |
|---|---|
| Sites | Name, identifier, NAS driver, home instance, routers (NAS IP, NAS-Identifier, RADIUS secret per router, CoA port), enabled login methods. |
| Codes | Create single/bulk, print vouchers (QR with portal URL + code), edit, revoke. |
| Sessions | Active sessions federation-wide (site, MAC, IP, start, remaining), revoke. |
| Guests | Guest list, accepted terms version, grant history. |
| Approvals | Pending requests (approve/deny in UI as well). |
| Channels | Notification channels and SMS provider, test button. |
| Terms | Text per language, versioned; new version requires re-acceptance on next login. |
| Retention | Retention period (default 1 year); nightly purge job. |
| Backup | Scheduled SQLite backup (`VACUUM INTO`) to `/backup` (e.g. NAS share), keep N copies; manual download. Per instance. |
| Audit log | Admin/manager actions, logins, revocations, setting changes. |
| Federation | Peers, status, lag, join token, Telegram owner. |
| RouterOS generator | Per site: copy-paste script (hotspot, RADIUS client, `/radius incoming`, walled garden, cert fetch), `login.html` download and upload instructions. |

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
- Clocks: NTP required on all instances (wall-clock expiry). Heartbeats compare clocks; above a
  configurable skew (e.g. 30 s) the admin UI warns, above a hard limit replication pauses.

| Replicated | Local only |
|---|---|
| Grants/codes, site restrictions, revocations, device (MAC) bindings, guests, terms versions + acceptances, users/roles, sites (catalog), shared config (Telegram, Clickatell, Gotify, webhook, languages, retention, SMS duration, country allowlist), audit log | RADIUS tokens, OTP challenges, approval requests, login attempts/rate limits, NAS clients + secrets, raw accounting, TLS/ports/backup settings, local env |
| Session summaries (grant, MAC, site, serving instance, start, stop) | Accounting details (interim updates, counters) |

### 10.4 Roaming and remote actions

- Guest with a valid grant arrives at site B: the hotspot redirects to B's portal; B finds an
  active grant bound to the reported MAC and allowed at site B and immediately issues a one-time
  token (no guest input, "welcome back" page with auto-submit). RADIUS at B verifies
  `Calling-Station-Id` against the replicated binding. Works offline from other peers as long as
  the grant was replicated before.
- No MAC-only login (`login-by=mac`): roaming guests always pass briefly through the portal (decided).
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
| `users` R | id, name, email, password, role (admin/manager), totp_secret (enc) |
| `sites` R | id, slug, name, driver (e.g. `mikrotik_ros7`), home_instance_id, settings (json) |
| `nas_clients` | id, site_id, name, nas_ip, nas_identifier, radius_secret (enc), coa_port |
| `guests` R | id, first_name, last_name, phone_e164 (nullable), locale |
| `grants` R | id, type (code/sms/approval), code (nullable), label, note, duration_s, expiry_mode, valid_from, redeem_until, expires_at, first_login_at, revoked_at, max_extensions, extensions_used, rate_limit, created_by |
| `grant_site` R | grant_id, site_id (none = all sites) |
| `devices` R | id, grant_id, guest_id, mac, bound_at, unbound_at |
| `session_summaries` R | id, grant_id, site_id, serving_instance_id, mac, ip, started_at, stopped_at |
| `radius_tokens` | id, grant_id, guest_id, device_id, username, password_hash, mac, site_id, expires_at, used_at |
| `radius_accounting` | id, session_summary_id, nas_client_id, acct_session_id, username, last_update_at, terminate_cause, bytes_in, bytes_out, disconnect_sent_at |
| `otp_challenges` | id, guest_id, phone_e164, code_hash, attempts, expires_at, verified_at |
| `approval_requests` | id, guest_id, site_id, mac, status, decided_by, decided_via, duration_s, expires_at |
| `terms_versions` R | id, locale, version, body, published_at |
| `terms_acceptances` R | id, guest_id, terms_version_id, accepted_at, site_id, mac |
| `channels` R | id, kind (notification/sms), driver, name, config (enc json), enabled |
| `settings` R / `local_settings` | key, value (json) |
| `login_attempts` | id, mac, ip, site_id, method, success, created_at |
| `audit_logs` R | id, user_id, action, subject_type, subject_id, data (json), ip, created_at |
| `federation_events` / `peer_cursors` | outbox (origin, seq, table, record_id, version, payload) / cursor per origin |
| Laravel defaults | `jobs`, `failed_jobs`, `cache`, `sessions`, `migrations` |

Purge job removes guests, devices, summaries, accounting, attempts, acceptances and audit entries
older than the retention period (active grants kept); tombstones are purged after all peers have
acknowledged them.

## 12. Security

- **Guest isolation:** guest VLAN may reach only the guest port (TCP 443) of its instance and DNS
  on the router; no other LAN/VLAN, router management, admin or federation port. AP client
  isolation recommended.
- **Ports:** admin port only from VPN/admin networks; federation port only between instances;
  RADIUS UDP 1812/1813 to the instance and 3799 to routers only between routers and their home
  instance. Strong per-router secrets; Message-Authenticator required (Blast-RADIUS) **(to verify
  RouterOS support)**.
- **Secrets:** `APP_KEY`, internal API token, DB credentials in an env file on `/data` (generated
  on first start), never in RouterOS envs or the image. Secrets in DB via Laravel `encrypted`
  casts (local key or federation data key).
- **Admin:** separate port, TOTP, login throttling, roles, audit log.
- **Guest login lockout (M2):** limits per MAC, per IP and global (env `PORTAL_LOGIN_*`). The MAC
  comes from the redirect URL and can be spoofed: a guest on the same network could lock out another
  MAC, and an attacker can rotate MACs (then the per-IP and global limits apply). Accepted trade-off
  for a small user base; limits stay configurable.
- **Login handoff (M2):** one-time credentials are posted only to `link-login-only` URLs with
  `https` and a host listed in the site's hotspot host names; `link-orig` is only passed to the router
  as post-login target (http(s), no embedded credentials), the portal never redirects to it.
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
| SMS cost abuse | Cost. | Country allowlist, rate limits, daily cap. |
| Telegram owner down | No Telegram approvals. | Manual failover; other channels and admin UI still work. |

## 14. Milestones

Build order: single instance first (M1–M6); federation later (M7). Data model is
federation-ready from M1.

Status: M1 done; M2 implemented (v0.2.0), open: Disconnect-Request and login handoff tested on a
real RouterOS router. Deferred from M2: voucher QR, audit log table (M6; M2 logs grant actions to
the application log).

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
