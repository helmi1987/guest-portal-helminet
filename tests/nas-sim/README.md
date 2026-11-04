# NAS simulator for the generic RADIUS captive-portal driver

Experimental driver `generic_radius` (CONCEPT.md §5.1, decision 2026-11-04, v0.28.0; user guide:
[docs/generic-captive-portal.md](../../docs/generic-captive-portal.md)). There is no vendor hardware in the project,
so this simulator plays the captive portal of each preset **as the vendor documents it** and the NAS towards the
portal's RADIUS. It is not part of `php artisan test`.

## What it does

`nas_sim.py` (Python 3, standard library only, one process per preset):

1. **Captive entry** `GET /`: redirects the browser to the portal with the vendor's parameter names and MAC notation
   (Cisco `switch_url`/`client_mac`, Aruba `mac`/`ip`/`url`, pfSense page variables, CoovaChilli `uamip`/`uamport`/
   `challenge`, Ruckus `client_mac`/`uip`/`sip`, custom `mac`/`ip`/`login`), the login URL pointing back at itself.
2. **Login form** (the portal's handoff): checks method, path, field names and fixed fields of the preset; for
   CoovaChilli it decodes the UAM password (hex, XOR with MD5(challenge + uamsecret)).
3. **RADIUS**: Access-Request (PAP, Message-Authenticator, `Calling-Station-Id` in the vendor's notation,
   `NAS-IP-Address`, `NAS-Identifier`, `Framed-IP-Address`), reads `Session-Timeout`, `Acct-Interim-Interval` and
   `WISPr-Bandwidth-Max-Up/Down`, sends Accounting-Start, Interim-Updates, and redirects the browser to the success URL.
4. **CoA**: answers a Disconnect-Request (RFC 5176) on UDP 3799 with Disconnect-ACK when every identifying attribute
   matches the session (else NAK, Error-Cause 503), then sends Accounting-Stop (Admin-Reset).
5. Logs one line per step (`[preset] step: ...`) and serves its state as JSON at `/state`.

`flow.js` (Playwright, Chromium) is the guest: captive entry → portal → code login → handoff → status page
«You are online». `run.sh` seeds the portal, runs simulator and browser per preset, revokes the grant and waits for
Disconnect-ACK and Accounting-Stop, then prints a summary table.

## Run against the container (the real target)

```sh
tests/nas-sim/run.sh <portal-container> [cisco_wlc aruba opnsense coovachilli ruckus custom]
```

- Needs Docker and `openssl` on the host and internet access for the images (`python:3.12-alpine`,
  `mcr.microsoft.com/playwright:v1.49.1-noble`) and `npm i playwright`.
- Simulator and browser share the **network namespace of the portal container** (`--network container:<name>`):
  RADIUS goes to `127.0.0.1:1812/1813`, the Disconnect-Request comes to `127.0.0.1:3799`, the browser opens the guest
  portal at `https://127.0.0.1:<GUEST_PORT>`. Nothing is published on the host.
- Seeds through `docker exec <container> artisan tinker`: site `nas-sim` (driver `generic_radius`, preset applied,
  hotspot host `127.0.0.1`, portal URL `https://127.0.0.1:<GUEST_PORT>`), router "NAS simulator" (NAS IP `127.0.0.1`,
  CoA port 3799; the clients file is rewritten and FreeRADIUS restarts), one access code per preset (rate limit
  `1M/4M`, so WISPr shows up). Overrides for the test: Aruba's fixed login host becomes the simulator, the custom
  mapping reads the login URL from `login`. Use a **test instance**; remove the seed with
  `tests/nas-sim/run.sh --cleanup <portal-container>`.
- Ruckus always uses port 9998 (the preset's login URL `https://{sip}:9998/login`); the others `SIM_PORT` (8099).
- Requires: code login switched on and terms of use published on the instance, the queue worker running (the
  Disconnect-Request is a queued job), no other router with NAS IP `127.0.0.1`.

## Run locally without FreeRADIUS

```sh
PORTAL_DEFAULT_ZONE=guest php artisan serve --port 8765         # other terminal (assets built: npm run build)
NODE_PATH=/path/to/node_modules tests/nas-sim/run.sh --local http://127.0.0.1:8765
```

The simulator runs with `--no-radius`: it checks the redirect and the handoff only, the status page keeps "waiting".
`NODE_PATH` points to a `node_modules` with `playwright`; `CHROMIUM_PATH` to a browser executable if the installed
Playwright has none.

## What it proves and what not

It proves that the driver and the portal follow the conventions written down in `app/Nas/Generic/Presets.php`
(parameter names, MAC notations, login URL rules, form fields, password encoding, RADIUS and CoA attributes). It cannot
prove that real devices behave like that: the presets come from public documentation and are unverified, see
[docs/generic-captive-portal.md](../../docs/generic-captive-portal.md).
