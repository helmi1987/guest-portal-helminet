# MikroTik Guest Portal

A self-hosted captive portal ("guest login") for MikroTik RouterOS 7 hotspots across multiple sites.
Guests authenticate with an admin-issued code, an SMS one-time password or an admin push approval;
the router enforces access via RADIUS. Laravel web app and FreeRADIUS ship together in a single
multi-arch container image that runs either as a RouterOS container or on any regular Docker host.
One instance can serve several sites, or each site runs its own instance and instances form a
federation so access grants are valid at every site.

Built and tested for MikroTik RouterOS 7. The RADIUS core is vendor-neutral; other captive portals
can be supported via drivers (contributions welcome).

**Status:** early development (M7 federation in progress; v0.12.0: site codes; v0.11.1: translated validation messages; v0.11.0: dark/light colour scheme; v0.10.0: branding; admin extras, M6 parts A-E). Code login, SMS one-time password
(Clickatell or generic SMS webhook) and push approval (Telegram group, Gotify, generic webhook,
admin UI) via RADIUS with versioned terms of use, DE/IT/FR/EN, HTTPS router login with automatic
router certificate, setup assistant; admin extras (M6): users with roles admin/manager, TOTP two-factor
login, audit log, backups, certificate pull from CertWarden or a URL, test buttons, guests & sessions list,
bulk codes with printable A4 voucher sheet (Wi-Fi join QR) and CSV export, MAC whitelist (trusted devices
online without the portal via RouterOS MAC authentication), OIDC login for the admin UI (e.g.
Authentik), branding (logo, favicon, colours, custom CSS, per-site overrides); federation follows
(see CONCEPT.md §14).

See [CONCEPT.md](CONCEPT.md) for architecture, data model, security and milestones.

## Quick start

```sh
docker run -d --name guest-portal --restart unless-stopped \
  -p 443:443 -p 8443:8443 -p 1812:1812/udp -p 1813:1813/udp \
  -v "$PWD/data:/data" \
  -e APP_URL=https://portal.example.com \
  ghcr.io/helmi1987/mikrotik-guest-portal:edge
docker exec -it guest-portal artisan portal:admin   # create the first admin
```

Lost authenticator without recovery codes: `artisan portal:admin --reset-2fa admin@example.com`.

- Guest portal: `https://<host>/`, admin UI: `https://<host>:8443/admin`.
- Router setup: admin UI → Sites → add site and router → "Set up" (setup assistant: guided steps
  with ready-to-copy RouterOS commands for container, guest network, hotspot, RADIUS/certificate,
  `login.html` fetch, status per router and diagnostics), or "Router configuration" (RouterOS
  script) and `login.html` download.
- `/data` holds the database, generated secrets (`.env`), certificates and backups.
  Certificates (PEM, full chain + key) go to `/data/certs`: `portal.crt` + `portal.key` for the guest
  and admin ports (otherwise a self-signed one is generated; legacy: `fullchain.pem` + `privkey.pem`
  mounted at `/certs`), `router.crt` + `router.key` (e.g. a wildcard) for the routers' HTTPS login,
  downloaded by each router's scheduler. Changed files are picked up within ~10 s (nginx reload).
  Admin UI → Certificates: current certificates and an optional source per certificate (CertWarden
  private cert chain with `<cert API key>.<key API key>`, or a URL returning key + certificate +
  chain as PEM with optional bearer token, own CA for a private CA), pulled every 6 h or on demand.
- Vouchers: admin UI → Codes → create 1-100 codes at once; the print view is an A4 sheet (10 cards)
  with the code, duration and a Wi-Fi join QR for the guest SSID entered in the setup assistant
  (shown when the code's sites share one SSID); admins can export the codes as CSV.
- Site codes: admin UI → Site codes → one shared code per site for any number of devices (each device
  gets its own access for the set duration; optional expiry and device limit), with history and a
  printable notice (A4/A6, Wi-Fi QR + sign-in QR, DE/IT/FR/EN). `/portal?code=123456` prefills the code.
- MAC whitelist: admin UI → MAC whitelist (or "allow this device permanently" in the guest list).
  Needs `login-by=https,mac` on the hotspot profile (set by the generated router script). MAC
  addresses can be spoofed: whitelist only trusted devices.
- OIDC login (admin UI only), e.g. Authentik: create a provider of type "OAuth2/OpenID Provider"
  (confidential client) with redirect URI `https://<admin host>:8443/admin/oidc/callback` (shown with a
  copy button in admin UI → Settings → Single sign-on; derived from the admin URL setting), keep the
  scopes `openid`, `profile`, `email` (Authentik's default `profile` mapping returns the group names
  as claim `groups`; otherwise add a scope mapping for it, to verify), create an application for it
  and the groups `portal-admin` / `portal-manager`.
  Then enter issuer URL (`https://<idp host>/application/o/<app slug>/`), client ID and secret in
  Settings. Users are created on their first login (verified email required) with the role from their group;
  without one of the groups the login is denied. Existing local users are linked by verified email
  (setting, default on; a user with portal 2FA enters the code once when linking). Deactivating a user
  or removing the groups at the provider takes effect at the next login or when the portal session
  expires; to cut access at once, delete the user in the portal. The password login stays as emergency access: keep one local admin.
  Portal 2FA after OIDC: "skip if the provider reports MFA" (default; `amr` claim or a configured `acr`
  value, optionally only if `auth_time` is recent), "always" or "never".
- Branding: admin UI → Settings → Appearance (logo, favicon, colours, custom CSS; admin only), per
  site on the site page. Files are stored in `/data/branding`. Stable CSS hooks (`gp-*` classes,
  `--gp-*` variables), examples and limits: [docs/branding.md](docs/branding.md).
- Backups: daily to `/data/backups` (SQLite snapshot, `.env`, `certs/`, `branding/`; one `.tar.gz`, mode 0600,
  14 kept by default), admin UI → Backups or `artisan portal:backup`. Backups contain secrets.
- Compose: [docker-compose.example.yml](docker-compose.example.yml).
  Docker host (Compose, e.g. Unraid): [docs/docker-host.md](docs/docker-host.md).
  RouterOS container: [docs/routeros-container.md](docs/routeros-container.md).
  Guest network (VLAN, Wi-Fi, firewall, hotspot, RADIUS, certificates):
  [docs/guest-network-setup.md](docs/guest-network-setup.md).
- Push approval: admin UI → Settings → admin URL for links (admin port as reached by approvers),
  notification language, notification channels. Telegram: create a bot with BotFather, add it to the
  approvers' group, enter bot token and group chat id (optionally an allowlist of user ids); button
  presses are received by long polling (s6 service `telegram`), no inbound connection needed.
- SMS login: admin UI → Settings → SMS login (duration, allowed countries, cost caps) and SMS
  providers (Clickatell API key, or a generic SMS webhook for an own gateway; test SMS button).
  Known limit: the cost caps bound the bill, but an attacker who exhausts the site or instance cap
  (or a number's cap) blocks SMS login for everyone (or that number) until the window passes; cap
  hits are logged as warnings. Code and approval login keep working.
- Restore: stop the container, delete `<data folder>/db/database.sqlite-wal` and `-shm` if present,
  extract the backup into the data folder (`tar -xzf portal-backup-….tar.gz -C <data folder>`;
  entries mirror the folder: `db/database.sqlite`, `.env`, `certs/`, `branding/`, plus `backup-manifest.json`),
  start the container. The
  `.env` must belong to the database (same `APP_KEY`), otherwise stored secrets cannot be decrypted.
- Time zone: `TZ` (or `APP_TIMEZONE`, which wins) sets the zone of times shown in the admin UI,
  notifications ("open until"), entered dates and backup file names; the database, expiry logic
  and the scheduler (backup 02:45, purge 03:30) stay in UTC.
- Admin URL (approval links, OIDC redirect URI): admin UI → Settings; while empty, `APP_URL` with
  `ADMIN_PORT` (default 8443) is used and the settings page shows a warning.
- `QUEUE_CONNECTION` must stay `database` (image default); `sync` is not supported (SMS would be sent
  inside the request while the send lock is held).
- Image tags: `edge` (main), `X.Y.Z`, `X.Y`, `X`, `latest`, `X.Y.Z-YYYYMMDD` (weekly rebuild), see CONCEPT.md §4.

## Development

PHP 8.3+ (image: PHP 8.4 from Alpine), Node 22, Composer.

```sh
composer install && npm ci && npm run build
cp .env.example .env && php artisan key:generate && php artisan migrate
php artisan portal:admin
php artisan serve          # PORTAL_DEFAULT_ZONE=all in .env serves guest and admin routes
php artisan test
```

## Notes for AI assistants

- **Anonymity:** this repository must stay fully anonymous. The copyright notice in the README
  "License" section is the only personal identifier allowed. No other personal names, locations or site
  names, real domains, IP addresses, hostnames, email addresses, usernames or descriptions of any
  real infrastructure. Use generic examples only (`portal.example.com`, `10.0.0.0/24`, "site A").
  Approved exception: the container image URL `ghcr.io/helmi1987/mikrotik-guest-portal` (GitHub
  user name in the registry path) in the README, docs and compose example; nothing else.
- **No secrets** in code, docs, examples, tests, CI or commit messages.
- **Language:** everything in the repository is English (code, comments, docs, commits, logs).
- **Concept decisions live in `CONCEPT.md`.** Keep it up to date when a decision changes; do not
  record decisions elsewhere.
- **No implementation without maintainer approval.** Propose changes first; build only after an
  explicit OK.

## License

Copyright (C) 2026 Stefan Diethelm

Licensed under the GNU Affero General Public License v3.0 or later, see [LICENSE](LICENSE).
