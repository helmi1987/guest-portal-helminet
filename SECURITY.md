# Security policy

## Reporting a vulnerability

Please do not report security vulnerabilities in public issues, pull requests or discussions.

Report them privately through GitHub's private vulnerability reporting: open the repository's
**Security** tab and choose **Report a vulnerability** (this creates a draft security advisory that
only the maintainers can see). Include:

- the affected version (`VERSION` file or image tag) and deployment (Docker host or RouterOS container),
- the steps to reproduce, or a proof of concept,
- the impact as you see it (e.g. guest bypasses the portal, admin account takeover, secret disclosure).

Do not include real secrets, real guest data or details of your own infrastructure; generic example
values are enough.

## What to expect

- An acknowledgement within 7 days.
- An assessment and, for a confirmed issue, a plan for the fix within 30 days. This is a volunteer
  project, so complex fixes may take longer; you will be kept informed through the advisory.
- The fix is released first; the advisory is published afterwards, crediting you if you wish.

## Supported versions

| Version | Supported |
|---|---|
| Latest release (`X.Y.Z` / `latest` image tags) | yes |
| `edge` (current `main`) | yes |
| Older releases | no, please update |

## Scope

In scope: the portal application, the container image (nginx, PHP, FreeRADIUS configuration, s6
services), the generated RouterOS scripts and the federation protocol of this repository.

Out of scope: vulnerabilities in RouterOS, FreeRADIUS, PHP, Laravel or other upstream components.
Please report those to the respective upstream project (e.g. MikroTik, FreeRADIUS). If such an issue
needs a change or a workaround here (e.g. a dependency update), a report here is welcome as well.
