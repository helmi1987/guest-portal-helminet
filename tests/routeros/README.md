# RouterOS CHR harness

Runs every script block of the setup assistant (MikroTik driver) against a real RouterOS CHR and checks
that the scripts only create, change and remove their own objects (name/comment `guest-portal`).
Not part of `php artisan test`; CI: `.github/workflows/routeros.yml` (optional, does not gate merging).

## Files

| File | Purpose |
|---|---|
| `render.php` | Renders all blocks for a fixture site/router (in-memory SQLite, fake values) into `.rsc` files, one directory per Wi-Fi mode |
| `seed.rsc` | "Foreign" defconf-like configuration: firewall, interface lists, bridge, certificates, hotspot profiles, DNS, DHCP, address lists, RADIUS, Wi-Fi/CAPsMAN, scripts; some names start with `guest-portal` on purpose |
| `pki.sh` | Throw-away test PKI: portal CA + portal server certificate, router chain (root, intermediate, two leaves: RSA PKCS#1, EC PKCS#8) |
| `stub_portal.py` | HTTPS stand-in for `/router-cert/<id>/...` and `/router-login/<id>/login.html` with the `X-Portal-Token` check |
| `run.sh` | The test: seed, snapshot, run blocks, assertions, uninstall, compare with the snapshot |
| `chr.sh` | Download/prepare (wireless + container packages), boot (KVM or TCG), VM snapshots, stop |

## Local run

```sh
tests/routeros/chr.sh prepare 7.24.5 ~/chr            # once: ~/chr/base.img
qemu-img create -f qcow2 -b ~/chr/base.img -F raw ~/chr/work.qcow2
tests/routeros/chr.sh start ~/chr/work.qcow2          # SSH on 127.0.0.1:2222 (admin, empty password)
tests/routeros/chr.sh savevm clean                    # optional: fast reset point
tests/routeros/run.sh                                 # all Wi-Fi modes, or e.g. run.sh capsman2
tests/routeros/chr.sh loadvm clean                    # reset before the next run
```

Needs `qemu-system-x86_64`, `qemu-img`, `sshpass`, `openssl`, `python3`, PHP with the composer
dependencies. The run needs a router without configuration (fresh image or `loadvm clean`).

## What is checked

- Every block runs via `/import` without a RouterOS error (placeholders replaced with test values:
  `<COUNTRY>`, `<MASTER_CFG>`, `<EXISTING_SLAVES>`, `<TRUNK_PORT_TO_CAP>`; the line with `<WIFI_INTERFACE>`
  is skipped: the CHR has no radio).
- Firewall: the `guest-portal: ` rules are the first static rules of input and forward, in generated order,
  also when re-run with dynamic hotspot rules present; foreign rules unchanged and in their order.
- DNS: the router resolves the portal name via the CNAME.
- Certificate script: first import (leaf `guest-portal` with key and served fingerprint, CAs
  `guest-portal-ca-1/2`, profile uses it), second run and router script re-run are no-ops, rotation to a
  new leaf; foreign certificates (also `guest-portal-ca-x`, `guest-portal-backup`,
  `guest-portal.example.com`) and the default hotspot profile untouched. `check-certificate=yes` with the
  test portal CA imported as trusted (a foreign certificate as well).
- `login.html` fetch into the profile's html-directory; survives re-creating the hotspot; a foreign
  hotspot directory is untouched.
- Uninstall: afterwards the full `/export verbose show-sensitive`, the certificate store and the file
  list equal the snapshot taken after seeding, except for documented leftovers: `/radius incoming`,
  `capsman enabled`, the CAPsMAN certificates RouterOS generates when wifi CAPsMAN is enabled, the
  disabled "place hotspot rules here" placeholders RouterOS adds with the first hotspot and the files of
  the hotspot html-directory.

## Not covered (CHR limits)

Real CAPs/radios (provisioning, virtual AP `master-interface`), guests on the hotspot (login, RADIUS
replies, Disconnect), `/container add` (needs device-mode, confirmed physically), a device with a flash
folder (`flash/hotspot` is a plain directory on the CHR), and the source-IP check of the downloads
(qemu user networking NATs the router's `src-address`).
