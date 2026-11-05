# RouterOS CHR harness

Runs every script block of the setup assistant (MikroTik driver) against a real RouterOS CHR and checks
that the scripts only create, change and remove their own objects (name `guest-portal` or comment key
`guest-portal:<block>:<object>`).
Not part of `php artisan test`; CI: `.github/workflows/routeros.yml` (optional, does not gate merging).

## Files

| File | Purpose |
|---|---|
| `render.php` | Renders all blocks for a fixture site/router (in-memory SQLite, fake values) into `.rsc` files, one directory per fixture (`capsman1`, `capsman2`, `local`, `multi` = all three modes with a CAP selection); with `RQ_*` variables runs the router state query against the CHR instead (with `RQ_EDIT` also the provisioning editor script from that state) |
| `legacy.rsc` | Objects as created by scripts before the comment keys (migration check) |
| `unmanaged.rsc` | Objects as created by versions 0.6.0 to 0.12.6 without comment (VLAN interface, DHCP server, `dp-guest`, `cfg-guest`): router state "unmanaged", adopt commands |
| `seed.rsc` | "Foreign" defconf-like configuration: firewall, interface lists, bridge, certificates, hotspot profiles, DNS, DHCP, address lists, RADIUS, Wi-Fi/CAPsMAN, scripts; some names start with `guest-portal` on purpose |
| `pki.sh` | Throw-away test PKI: portal CA + portal server certificate, router chain (root, intermediate, two leaves: RSA PKCS#1, EC PKCS#8) |
| `stub_portal.py` | HTTPS stand-in for `/router-cert/<id>/...` and `/router-login/<id>/<page>.html` (login, status, logout, alogin) with the `X-Portal-Token` check |
| `guard.sh`, `paste.py` | Placeholder guard test of the Wi-Fi block and the API access block (`{ }` + `:error` while the password placeholder is left): terminal paste (`paste.py`, interactive ssh session, line by line) and `/import`; placeholder left = error and nothing changed, filled = objects created, run twice = idempotent; capsman1: whole block filled appends `cfg-guest` to the selected rules (existing slave kept), twice = identical; the network uninstall takes it out again (slave kept, only-`cfg-guest` rule empty), twice = identical. Needs a router without configuration (`loadvm clean`) |
| `run.sh` | The test: seed, snapshot, run blocks, assertions, uninstall, compare with the snapshot |
| `chr.sh` | Download/prepare (wireless + container packages), boot (KVM or TCG), VM snapshots, stop |

## Local run

```sh
tests/routeros/chr.sh prepare 7.24.5 ~/chr            # once: ~/chr/base.img
qemu-img create -f qcow2 -b ~/chr/base.img -F raw ~/chr/work.qcow2
tests/routeros/chr.sh start ~/chr/work.qcow2          # SSH on 127.0.0.1:2222 (admin, empty password)
tests/routeros/chr.sh savevm clean                    # optional: fast reset point
tests/routeros/run.sh                                 # all fixtures, or e.g. run.sh capsman2 / run.sh multi
tests/routeros/guard.sh                               # placeholder guard (paste + import), modes local capsman1
tests/routeros/chr.sh loadvm clean                    # reset before the next run
```

Needs `qemu-system-x86_64`, `qemu-img`, `sshpass`, `openssl`, `python3`, PHP with the composer
dependencies. The run needs a router without configuration (fresh image or `loadvm clean`).

## What is checked

- Every block runs via `/import` without a RouterOS error (placeholders replaced with test values:
  `<COUNTRY>`, `<TRUNK_PORT_TO_CAP>`, `<API_PASSWORD>`).
- Wi-Fi masters (fixture: two per mode): CAPsMAN v1/v2 append `cfg-guest` once to the provisioning rules of
  `cfg-main` (existing slave `cfg-iot` kept) and `cfg-office` (no slave), the unselected rule `cfg-other` is unchanged,
  the re-run changes nothing (full configuration compared); mode local reports both masters as missing and skips
  them (the CHR has no radio).
- Objects without comment (`unmanaged.rsc`, per mode, read through REST with the test leaf imported as
  `harness-api` for www-ssl): VLAN interface, DHCP server, `dp-guest`, `cfg-guest` reported as `unmanaged`
  (`sec-guest` with the legacy comment stays `legacy`), the Wi-Fi block fails on them ("must use unique name"),
  the adopt commands of the state (also re-run) set the keys and leave an object with a foreign comment
  alone, afterwards nothing is unmanaged and the blocks run on the adopted objects.
- Firewall: the `guest-portal:fw:` rules are the first static rules of input and forward, in generated order,
  also when re-run with dynamic hotspot rules present; foreign rules unchanged and in their order.
- Legacy objects of scripts before the comment keys (`legacy.rsc`: comment `guest-portal` / `guest-portal: ...`,
  certificate script without comment) are updated in place or replaced; afterwards every `guest-portal:` comment
  on the router is a key of `managedObjects()` (file `keys` of `render.php`) and no legacy comment is left.
- Wi-Fi: `sec-guest` with the passphrase of the fixture (every character RouterOS escapes) and WPA
  authentication (capsman1, local), open (capsman2); re-run, and in mode local password removed and set again.
- Network blocks re-runnable (wifi, dhcp, vlan run twice).
- DNS: the router resolves the portal name via the CNAME.
- Dynamic hotspot entries (decision 2026-11-02): an own static hotspot-name entry of earlier versions is removed by the
  DNS block and not added again; after the hotspot block the dynamic entries of the walled garden, walled garden ip
  and DNS are printed (7.24.5 CHR: the DNS A entry of the hotspot dns-name and a dynamic walled-garden copy of every
  walled-garden ip entry with its comment, own and foreign; none in walled garden ip) and the hotspot name resolves; the DNS block re-run, the router
  script and its re-run, and the walled-garden/DNS lines of the uninstall run alone while the hotspot exists leave
  the dynamic entries unchanged; afterwards no own walled-garden/DNS entry is left.
- Certificate script: first import (leaf `guest-portal` with key and served fingerprint, CAs
  `guest-portal-ca-1/2`, profile uses it), second run and router script re-run are no-ops, rotation to a
  new leaf; foreign certificates (also `guest-portal-ca-x`, `guest-portal-backup`,
  `guest-portal.example.com`) and the default hotspot profile untouched. `check-certificate=yes` with the
  test portal CA imported as trusted (a foreign certificate as well).
- Hotspot pages fetch (`login.html`, `status.html`, `logout.html`, `alogin.html`) into the profile's
  html-directory, each with its redirect target and the RouterOS variables intact; they survive re-creating the
  hotspot; a foreign hotspot directory is untouched.
- Router state query (read-only API): the access block runs (and re-runs); with `www-ssl` and `api-ssl`
  set to the certificate `guest-portal` for the test (restored before the uninstall), the REST and the
  API-SSL client read the state through the forwarded ports (`ROS_REST_PORT` 2443, `ROS_APISSL_PORT`
  2729): every generated object present with the expected values (except the container objects, removed
  again before, and the virtual AP of mode local); wrong password = auth error, another pinned key =
  TLS error; a REST write with the read-only user is refused.
- Uninstall (CAPsMAN v1/v2): `cfg-guest` is taken out of every provisioning rule (`cfg-main`: `cfg-iot`
  kept, `cfg-office`: empty, `cfg-other`, not selected but given `cfg-guest` by hand: `cfg-iot`); both
  uninstall blocks re-run = configuration identical.
- Uninstall: afterwards the full `/export verbose show-sensitive`, the certificate store and the file
  list equal the snapshot taken after seeding, except for documented leftovers: `/radius incoming`,
  `capsman enabled`, the CAPsMAN certificates RouterOS generates when wifi CAPsMAN is enabled, the
  disabled "place hotspot rules here" placeholders RouterOS adds with the first hotspot and the files of
  the hotspot html-directory.

- Several Wi-Fi modes together (fixture `multi`, decision 2026-11-02): dp-guest, sec-guest and cfg-guest once in
  `/caps-man` and once in `/interface wifi`, cfg-guest appended to the capsman1 masters, the capsman2 masters not
  used (CAP selection), both selected radios and the local master reported as absent and skipped, re-run identical.
- Guest SSID per CAP with a simulated radio (the radio lookup of the block replaced by a fixed configuration
  `cfg-main`, the CHR has no radios): one own rule per radio with its key, radio-mac, the admin rule's action,
  master, slaves plus cfg-guest and band matcher, placed before the admin's rule; cfg-guest taken out of the
  admin's rule; re-run identical (not moved again); with the radios absent again the own rules stay; the block of
  a site without CAP selection removes them.
- Provisioning editor (v1 and v2, script from a REST state query): changed rule set by id, new rule added without
  comment with the band matcher; re-run: changed rule skipped by the guard (router changed since the query),
  new rule not added twice, configuration identical.
- Uninstall of `multi`: own CAP rules removed (a foreign rule with a look-alike comment kept), cfg-guest out of the
  rules and the objects of both packages removed, re-run identical, configuration equals the snapshot.

## Not covered (CHR limits)

Real CAPs/radios (provisioning, virtual APs `wifi-guest-<master>` and the rename of an earlier `wifi-guest`, the radio lookup of
the guest SSID per CAP: `radio-mac` and `configuration` of a CAP interface, CAPsMAN applying rule changes to the CAPs), guests on the hotspot (login, RADIUS
replies, Disconnect), `/container add` (needs device-mode, confirmed physically), a device with a flash
folder (`flash/hotspot` is a plain directory on the CHR), and the source-IP check of the downloads
(qemu user networking NATs the router's `src-address`).

## Not yet in run.sh (to add)

- Dead slave references (2026-11-02): remove a slave configuration that a provisioning rule lists (RouterOS then
  shows `*<id>` in `slave-configurations`), run the Wi-Fi block (append, per-CAP copy) and the network uninstall:
  the script must not abort and the rewritten rules must not carry the `*<id>`. Covered only by the PHP tests so far
  (`WifiModesAndCapsTest`, `ProvisioningEditorTest`).
- Container hint (2026-11-02): the state query reads `/system device-mode` (property `container`); the harness runs
  the query over REST and api-ssl, so a refused or unknown menu would show there. Not yet asserted: the value on
  the CHR and the hint "no container package" (CHR without the container package: both `/container` menus missing).
- Wi-Fi mode switch (2026-11-02): run.sh only checks that the state query of mode `local` reports the cfg-guest the
  open-site block (capsman2 fixture) appended to the wifi provisioning rules as `leftover` (then reset by hand). To
  add: install `multi` (capsman1, capsman2), switch the site to `local`, query the state and run the clean-up block
  (`leftoverCommands()`, written by render.php like the adopt commands): own CAP rules removed, cfg-guest out of the
  rules of both packages (other slaves kept), the objects of `/caps-man` removed, those of `/interface wifi` kept for
  mode local, re-run identical, then the uninstall of `local` gives the snapshot.
