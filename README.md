# MikroTik Guest Portal

A self-hosted captive portal ("guest login") for MikroTik RouterOS 7 hotspots across multiple sites.
Guests authenticate with an admin-issued code, an SMS one-time password or an admin push approval;
the router enforces access via RADIUS. Laravel web app and FreeRADIUS ship together in a single
multi-arch container image that runs either as a RouterOS container or on any regular Docker host.
One instance can serve several sites, or each site runs its own instance and instances form a
federation so access grants are valid at every site.

Built and tested for MikroTik RouterOS 7. The RADIUS core is vendor-neutral; other captive portals
can be supported via drivers (contributions welcome).

**Status:** early development (milestone M5: SMS login; v0.6.0: setup assistant). Code login, SMS one-time password
(Clickatell or generic SMS webhook) and push approval (Telegram group, Gotify, generic webhook,
admin UI) via RADIUS with versioned terms of use, DE/IT/FR/EN, HTTPS router login with automatic
router certificate; M6 (in progress): users with roles admin/manager, TOTP two-factor login, audit log;
remaining admin extras and federation follow (see CONCEPT.md §14).

See [CONCEPT.md](CONCEPT.md) for architecture, data model, security and milestones.

## Quick start

```sh
docker run -d --name guest-portal --restart unless-stopped \
  -p 443:443 -p 8443:8443 -p 1812:1812/udp -p 1813:1813/udp \
  -v "$PWD/data:/data" \
  -e APP_URL=https://portal.example.com \
  ghcr.io/OWNER/REPO:edge
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
- **No secrets** in code, docs, examples, tests, CI or commit messages.
- **Language:** everything in the repository is English (code, comments, docs, commits, logs).
- **Concept decisions live in `CONCEPT.md`.** Keep it up to date when a decision changes; do not
  record decisions elsewhere.
- **No implementation without maintainer approval.** Propose changes first; build only after an
  explicit OK.

## License

Copyright (C) 2026 Stefan Diethelm

Licensed under the GNU Affero General Public License v3.0 or later, see [LICENSE](LICENSE).
