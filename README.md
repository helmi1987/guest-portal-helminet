# MikroTik Guest Portal

A self-hosted captive portal ("guest login") for MikroTik RouterOS 7 hotspots across multiple sites.
Guests authenticate with an admin-issued code, an SMS one-time password or an admin push approval;
the router enforces access via RADIUS. Laravel web app and FreeRADIUS ship together in a single
multi-arch container image that runs either as a RouterOS container or on any regular Docker host.
One instance can serve several sites, or each site runs its own instance and instances form a
federation so access grants are valid at every site.

**Status:** early development / concept phase. No usable code yet.

See [CONCEPT.md](CONCEPT.md) for architecture, data model, security and milestones.

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
