# MikroTik Guest Portal

A self-hosted captive portal ("guest login") for MikroTik RouterOS 7 hotspots across multiple sites.
Guests authenticate with an admin-issued code, an SMS one-time password, a social login (Google,
Apple, Facebook, GitHub) or an admin push approval; the router enforces access via RADIUS. Laravel web app and FreeRADIUS ship together in a single
multi-arch container image that runs either as a RouterOS container or on any regular Docker host.
One instance can serve several sites, or each site runs its own instance and instances form a
federation so access grants are valid at every site.

Website: https://portal.helminet.ch (overview, screenshots, demo of the guest sign-in, installation).

> **Critical fix in v0.12.5:** v0.12.5 fixes a critical bug in the router certificate script of
> v0.12.1-v0.12.4 that could delete all certificates on the router (e.g. CAPsMAN). The script of v0.12.0
> and earlier had the same bug (`:local name` with `/certificate remove [find where name=$name]`).
> Cause: inside `find where`, a local named like a property is not substituted, so `name=$name`
> matches every item. Disable or replace the scheduler `guest-portal-cert` on every router
> (`/system scheduler disable [find where name="guest-portal-cert"]`) and run the router script of
> v0.12.5 again (it replaces the script and the scheduler).
> The same bug made `/ip hotspot profile find where name=$name` match every profile, so the first
> hotspot profile may have received `ssl-certificate`/`login-by`: check `/ip hotspot profile print`.

Built and tested for MikroTik RouterOS 7. The RADIUS core is vendor-neutral; other captive portals
can be supported via drivers (contributions welcome, see [docs/nas-driver.md](docs/nas-driver.md)); an
experimental Ubiquiti UniFi driver exists since v0.27.0 ([docs/unifi-setup.md](docs/unifi-setup.md)) and an
experimental generic RADIUS captive-portal driver with vendor presets since v0.28.0
([docs/generic-captive-portal.md](docs/generic-captive-portal.md)).

**Status:** early development, current version in [VERSION](VERSION). Highlights per minor version:

- v0.29: the portal generates all four MikroTik hotspot pages (`login.html`, `status.html`, `logout.html`,
  `alogin.html`), so a logged-in guest who opens the hotspot again sees the portal status page instead of the RouterOS
  default; the status page shows usage (connected time, data, as of the last accounting update) and a "Log out" that
  ends the session but keeps the code usable; the login page then says "You have logged out". v0.29.1: the adopt command of a
  legacy `/ip hotspot walled-garden ip` entry matches only comment and dst-address (the full condition matched nothing).
- v0.28: generic RADIUS captive-portal driver (experimental, presets for Cisco WLC, Aruba, pfSense, OPNsense,
  CoovaChilli, Ruckus) and a generic walled-garden checklist (docs/generic-captive-portal.md); v0.28.1: one-time adopt
  command for walled-garden entries with a comment of earlier versions (status "legacy"; the dynamic copy follows). v0.28.2: the
  firewall block and the uninstall skip the dynamic hs-unauth rules RouterOS derives from walled-garden ip entries (a legacy
  entry made the firewall block fail with "can't remove dynamic object").
- v0.27: experimental Ubiquiti UniFi driver (controller API, no RADIUS; untested on hardware, feedback welcome;
  docs/unifi-setup.md).
- v0.26: SMS providers modelled on Uptime Kuma's list (Twilio, seven.io, ClickSend, SMSAPI, 46elks,
  Cellsynt, Octopush, SMSEagle, SMS Planet, PromoSMS, SerwerSMS, SMSC, SMS Partner, SmsManager, GTX Messaging,
  plus the Swiss ASPSMS and eCall; Free Mobile for tests), one active provider with ordered backups and
  automatic failover.
- v0.25: display order of the guest login methods and of the social login providers set by the admin
  (up/down arrows in Settings, global, hub-only in a federation).
- v0.24: guest login with the site's Active Directory / LDAP account (user name and password checked by the
  portal with an LDAP bind, LDAPS with certificate check by default, per site on the site page with check
  button, optional group, lockout per account, grant type `account`; docs/guest-network-setup.md 6.7)
  (0.24.1: email address required on the code login; 0.24.2: router state ignores dynamic walled-garden rows and
  clears legacy entries; state cards link to the commands).
- v0.23: guest texts editable per language with live preview (admin System → Guest texts; the preview
  shows the real guest pages with unsaved texts and can be clicked through)
  (0.23.1: guest portal preview in the toolbox).
- v0.22: guest login method chooser (code, SMS, social login, push approval; one method is shown directly),
  switch per method and social login with Google, Apple, Facebook and GitHub (settings with setup guide,
  encrypted secrets, configuration check, walled-garden hosts in the generated RouterOS script, grant type
  `social`, access duration setting), default terms of use in all four languages (shown until published,
  published on the first site or by button), codes list shows current codes by default and ended codes can be
  deleted. Custom guest CSS: the hooks `gp-alt-login`, `gp-alt-sms` and `gp-alt-approval` are gone, the login
  pages use `gp-step-portal-<page>` and `gp-method-*` (docs/branding.md). Language switcher in the admin
  UI (login page and header, 0.22.1). Guide for writing a NAS driver, docs/nas-driver.md (0.22.2).
- v0.21: security fixes (0.21.0): admins (creation, promotion, passwords, 2FA, demotion, deletion) and
  security settings only from the federation hub, router IP check kept behind proxies
  (`PORTAL_ROUTER_IP_CHECK`), trust-all `TRUSTED_PROXIES` refused, clients file validated, SMS host line
  allowlist, per-user TOTP limit, no redirects on outbound requests, OIDC callback bounce; Wi-Fi objects of a
  deselected mode (e.g. CAPsMAN to local) reported as left over with a clean-up block per router, scheduled
  job output and logs in the container log, no federation event for saves of local columns only, container
  step hint for a missing container package or device mode, fixes for deleted slave references, the
  "queued" refresh badge and the Wi-Fi password labels; container hardening: CSP and HSTS per listener,
  relative redirects, read-only app code, database permissions, IP SAN in the self-signed certificate,
  quiet scheduler, UTC logs.
- v0.20: SMS text editable per locale, default SMS texts in the iOS-detectable `code: 123456` form
  (0.20.1), new default logo and favicons (0.20.2), site code notice without the sign-in QR, A4/A6 on
  one page (0.20.3).
- v0.19: setup assistant with combinable Wi-Fi modes, guest SSID per CAP and a provisioning rule editor;
  guided setup wizard (one step at a time, each with its own router check); dynamic hotspot entries
  ignored (0.19.1); SMS code in the domain-bound one-time code format with WebOTP (0.19.2).
- v0.18: admin toolbox with a Wi-Fi QR code print sheet.
- v0.17: detection of unmanaged guest objects, `cfg-guest` removed from CAPsMAN rules on uninstall,
  uninstall lines marked by router state.
- v0.16: guest Wi-Fi on multiple masters (local, CAPsMAN v1 and v2).
- v0.15: script lines coloured by router state, placeholder inputs, guarded Wi-Fi block (a terminal
  paste with unfilled placeholders changes nothing).
- v0.14: router state query per router (REST or API-SSL, read-only) with CLI derived from it, optional
  guest Wi-Fi password, unique comment keys in RouterOS scripts, collapsible admin sections; router API
  access and Wi-Fi password local to the instance unless shared (0.14.1).
- v0.13: M7 federation, one instance per site, see [docs/federation.md](docs/federation.md).
- v0.12: site codes; setup and router script fixes up to 0.12.7, including the critical fix of 0.12.5
  (see the notice above) and generated RouterOS scripts verified on a CHR (tests/routeros).
- v0.1-v0.11: milestones M1-M6, branding, dark/light colour scheme, translated validation messages.

Features: code login, SMS one-time password
(20 provider drivers incl. a generic SMS webhook, one active provider with backups) and push approval (Telegram group, Gotify, generic webhook,
admin UI) via RADIUS with versioned terms of use, DE/IT/FR/EN, HTTPS router login with automatic
router certificate, setup assistant and guided wizard; admin extras (M6): users with roles admin/manager, TOTP two-factor
login, audit log, backups, certificate pull from CertWarden or a URL, test buttons, guests & sessions list,
bulk codes with printable A4 voucher sheet (Wi-Fi join QR) and CSV export, MAC whitelist (trusted devices
online without the portal via RouterOS MAC authentication), OIDC login for the admin UI (e.g.
Authentik), branding (logo, favicon, colours, custom CSS, per-site overrides); federation (M7): one
instance per site, paired with a hub over a private link (mutual TLS with pinned certificates, signed
changes), codes, devices, users, sites and settings shared, roaming between sites, offline rule "SMS
only" while the hub is unreachable, Telegram owner, hub takeover, restore resync.

See [CONCEPT.md](CONCEPT.md) for architecture, data model, security and milestones.

## Quick start

```sh
docker run -d --name guest-portal --restart unless-stopped \
  -p 443:443 -p 8443:8443 -p 1812:1812/udp -p 1813:1813/udp \
  -v "$PWD/data:/data" \
  -e APP_URL=https://portal.example.com \
  ghcr.io/helmi1987/guest-portal-helminet:edge
docker exec -it guest-portal artisan portal:admin   # create the first admin
```

Lost authenticator without recovery codes: `artisan portal:admin --reset-2fa admin@example.com`.

- Guest portal: `https://<host>/`, admin UI: `https://<host>:8443/admin`.
- Router setup: admin UI → Sites → add site and router → "Set up" (setup assistant: guided steps
  with ready-to-copy RouterOS commands for container, guest network, hotspot, RADIUS/certificate,
  hotspot pages fetch (`login.html`, `status.html`, `logout.html`, `alogin.html`), status per router and diagnostics;
  optional read-only router API to check the
  router state and prefill the inputs, docs/guest-network-setup.md §10c), or "Router configuration"
  (RouterOS script) and the hotspot pages download. "Guided wizard" (v0.19.0, site page and setup page):
  the same blocks one step at a time (router API access, container (or "not used" for a Docker
  host), guest network, Wi-Fi, firewall/DHCP option, hotspot/DNS/walled garden, router script with
  certificate checklist and RADIUS diagnostics, hotspot pages, status), each with its inputs, commands and a "Check" of only that step's objects on the router;
  "Next" once the step is complete, else "Skip"; progress is kept in the browser only.
- `/data` holds the database, generated secrets (`.env`), certificates and backups.
  Certificates (PEM, full chain + key) go to `/data/certs`: `portal.crt` + `portal.key` for the guest
  and admin ports (otherwise a self-signed one is generated; legacy: `fullchain.pem` + `privkey.pem`
  mounted at `/certs`), `router.crt` + `router.key` (e.g. a wildcard) for the routers' HTTPS login,
  downloaded by each router's scheduler. Changed files are picked up within ~10 s (nginx reload).
  Admin UI → Certificates: current certificates and an optional source per certificate (CertWarden
  private cert chain: server URL without path, certificate and private key API keys in two fields; or a URL returning key + certificate +
  chain as PEM with optional bearer token, own CA for a private CA), pulled every 6 h or on demand.
- Vouchers: admin UI → Codes → create 1-100 codes at once; the print view is an A4 sheet (10 cards)
  with the code, duration and a Wi-Fi join QR for the guest SSID entered in the setup assistant
  (shown when the code's sites share one SSID); admins can export the codes as CSV.
- Site codes: admin UI → Site codes → one shared code per site for any number of devices (each device
  gets its own access for the set duration; optional expiry and device limit), with history and a
  printable notice (A4/A6, Wi-Fi QR, DE/IT/FR/EN). `/portal?code=123456` prefills the code.
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
- Backups: daily to `/data/backups` (SQLite snapshot, `.env`, `certs/`, `branding/`, `federation/`; one `.tar.gz`, mode 0600,
  14 kept by default), admin UI → Backups or `artisan portal:backup`. Backups contain secrets.
- Compose: [docker-compose.example.yml](docker-compose.example.yml).
  Docker host (Compose, e.g. Unraid): [docs/docker-host.md](docs/docker-host.md).
  RouterOS container: [docs/routeros-container.md](docs/routeros-container.md).
  Several instances (one per site, federation): [docs/federation.md](docs/federation.md).
  Guest network (VLAN, Wi-Fi, firewall, hotspot, RADIUS, certificates):
  [docs/guest-network-setup.md](docs/guest-network-setup.md).
- Push approval: admin UI → Settings → admin URL for links (admin port as reached by approvers),
  notification language, notification channels. Telegram: create a bot with BotFather, add it to the
  approvers' group, enter bot token and group chat id (optionally an allowlist of user ids); button
  presses are received by long polling (s6 service `telegram`), no inbound connection needed.
- SMS login: admin UI → Settings → SMS login (duration, allowed countries, cost caps, failover wait time)
  and SMS providers (pick a provider, enter the credentials shown in its help line; test SMS button).
  Only the provider marked «Aktiv» sends; the others are ordered backups that take over for an SMS when
  it fails (unreachable, error, timeout; not when the number is rejected). A failed provider is skipped
  for 5 minutes (setting), then tried again. The first failover of an outage is audited (`sms.failover`)
  and announced once on the enabled notification channels; recovery is audited (`sms.recovered`).
  Per-provider notes: most providers require the sender id (alphanumeric, max. 11 characters) to be
  registered or approved, and some countries (e.g. the US) refuse alphanumeric senders; Twilio needs a
  Twilio number or a Messaging Service SID; SMSEagle needs a certificate trusted by the container (TLS is
  verified); Free Mobile only reaches the subscriber's own number and is therefore offered for the test
  button only, never for guests. The drivers are tested against the providers' documented formats, not
  against live accounts: send a test SMS before relying on a provider.
  Known limit: the cost caps bound the bill, but an attacker who exhausts the site or instance cap
  (or a number's cap) blocks SMS login for everyone (or that number) until the window passes; cap
  hits are logged as warnings. Code and approval login keep working.
- Restore: stop the container, delete `<data folder>/db/database.sqlite-wal` and `-shm` if present,
  extract the backup into the data folder (`tar -xzf portal-backup-….tar.gz -C <data folder>`;
  entries mirror the folder: `db/database.sqlite`, `.env`, `certs/`, `branding/`, `federation/`, plus
  `backup-manifest.json`), start the container. The
  `.env` must belong to the database (same `APP_KEY`), otherwise stored secrets cannot be decrypted.
  A federated instance then fetches its own changes made after the backup back from the other instances
  before it accepts changes again ([docs/federation.md](docs/federation.md) 9.6).
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

## Contributing and security

See [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in
[SECURITY.md](SECURITY.md), not in public issues.

## Notes for AI assistants

- **Anonymity:** this repository must stay fully anonymous. The copyright notice in the README
  "License" section is the only personal identifier allowed. No other personal names, locations or site
  names, real domains, IP addresses, hostnames, email addresses, usernames or descriptions of any
  real infrastructure. Use generic examples only (`portal.example.com`, `10.0.0.0/24`, "site A").
  Approved exception: the container image URL `ghcr.io/helmi1987/guest-portal-helminet` (GitHub
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
