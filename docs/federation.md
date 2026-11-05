# Federation: several instances, one set of codes

One portal instance (container) per site, connected to each other, so codes, devices, MAC whitelist,
users, sites, routers and settings are the same everywhere and a guest's code works at every site it
is allowed for. Design and decisions: [CONCEPT.md](../CONCEPT.md) §10. A single instance serving several
sites needs none of this.

Status: implemented in M7 (v0.13.0, fixes v0.13.1), tested with simulated instances and three containers on one Docker
host; not yet tested across real sites and WAN links.

## 1. How it works

- **Star topology.** The first instance is the **hub** (e.g. at the main site). Every other
  instance ("site A", "site B") is paired with the hub only; the hub relays the changes of each instance
  to the others. Hub down: the other instances keep working with the last known state but do not sync
  with each other until it is back.
- **Multi-master.** The admin UI of every instance shows and changes the shared data; changes are applied
  locally and replicated (last writer wins per row, revocations are sticky).
- **Autonomous sites.** Each instance has its own database, FreeRADIUS and portal. Each router talks only
  to its local instance (RADIUS, certificate and `login.html` downloads, disconnects). A guest who
  logged in at site A is logged in automatically at site B (MAC authentication) for the remaining time
  if the code allows site B.
- **Trust.** Instances authenticate each other with their own key pair and self-signed certificate
  (`/data/federation`), pinned by public key (mutual TLS, no shared password). Every change is signed by
  the instance that made it, so the hub cannot forge changes of others.
- **Local only:** sessions and accounting (the guest list asks the other instances live), RADIUS
  one-time credentials, OTP challenges, approval requests, SMS log, backups, branding files (fetched by
  hash, see 8), the router status of the routers an instance serves (last RADIUS contact, downloads, router API
  access and state, hotspot page fingerprints, failed queries, outdated notifications). Only the home instance of a
  site checks its routers for outdated pages or configuration and notifies the admins ("Update router", v0.30.0);
  other instances show nothing about them. The reminder state of the social login secrets (which stage was sent
  for which expiry date, local setting `social_secret_reminders`, v0.31.0) is local too; only the hub sends these
  reminders, since the social login settings are hub-only.

## 2. Requirements

| Item | Requirement |
|---|---|
| Image | The same version on all instances (minor differences are tolerated, see 9.4) |
| Network | A private link from the hub to every instance, e.g. WireGuard (section 3) |
| Federation port | TCP 9443 (`FEDERATION_PORT`), reachable **only** between the instances (section 5) |
| Time | NTP on every host: above 30 s difference a warning, above 5 min replication with that instance pauses |
| Certificates | The same portal / router certificate on every instance (e.g. a wildcard), e.g. pulled from one CertWarden |
| DNS | Each site router resolves the portal name to **its local** instance (section 4) |

Example values used below (replace them):

| | Hub | Site A | Site B |
|---|---|---|---|
| WireGuard address | 10.8.0.1 | 10.8.0.2 | 10.8.0.3 |
| Federation URL | `https://10.8.0.1:9443` | `https://10.8.0.2:9443` | `https://10.8.0.3:9443` |
| Admin URL | `https://10.8.0.1:8443` | `https://10.8.0.2:8443` | `https://10.8.0.3:8443` |
| Portal IP (as seen by the site router) | 10.0.0.10 | 10.30.0.10 | 10.31.0.10 |

## 3. WireGuard between the hub and the sites

Any VPN works as long as the hub reaches each instance's federation port and each instance reaches the
hub's. Sites do not need a tunnel to each other. Example for Linux hosts (`wg-quick`); on RouterOS use
`/interface wireguard` with the same addresses and allowed IPs.

Hub (`/etc/wireguard/wg-fed.conf`):

```ini
[Interface]
Address = 10.8.0.1/24
ListenPort = 51820
PrivateKey = <HUB_PRIVATE_KEY>

[Peer]          # site A
PublicKey = <A_PUBLIC_KEY>
AllowedIPs = 10.8.0.2/32

[Peer]          # site B
PublicKey = <B_PUBLIC_KEY>
AllowedIPs = 10.8.0.3/32
```

Site A (site B alike with 10.8.0.3):

```ini
[Interface]
Address = 10.8.0.2/24
PrivateKey = <A_PRIVATE_KEY>

[Peer]          # hub
PublicKey = <HUB_PUBLIC_KEY>
Endpoint = <HUB_PUBLIC_ADDRESS>:51820
AllowedIPs = 10.8.0.1/32
PersistentKeepalive = 25
```

Publish the federation port of the container on the WireGuard address only, e.g. in the compose file
`- "10.8.0.2:9443:9443"`, and set `PORTAL_FEDERATION_URL=https://10.8.0.2:9443` (editable later on the
federation page). With a macvlan/ipvlan container (own LAN IP) route the WireGuard network to it instead.

## 4. Static DNS on every site router

Guests and the router must reach the **local** instance under the common portal name, so the same
certificate works everywhere. Pin the name on each router to its local instance, as the setup assistant
generates it (see [guest-network-setup.md](guest-network-setup.md) 8.1):

```
/ip dns static add name=guest-portal-v4.internal address=<LOCAL_PORTAL_IP> comment=guest-portal
/ip dns static add name=portal.example.com type=CNAME cname=guest-portal-v4.internal comment=guest-portal
```

`<LOCAL_PORTAL_IP>` differs per site (10.0.0.10 at the hub, 10.30.0.10 at site A, ...). The router's RADIUS
client points to the local instance too (site page → routers → router script).

## 5. Firewall

| Flow | From | To | Port |
|---|---|---|---|
| Federation | hub ↔ each instance (WireGuard addresses) | the other's federation port | TCP 9443 |
| Admin UI | admin / VPN networks | each instance | TCP 8443 |
| RADIUS, downloads | site routers | their local instance only | UDP 1812/1813, TCP 443 |
| Disconnect | local instance | its site routers | UDP 3799 |

Never expose 9443 to guests or the internet: without a pinned client certificate the port answers only
pairing requests that need a one-time join token, but there is no reason to offer it.

## 6. Setting up the hub, site A and site B

### 6.1 Hub

1. Install as a normal instance ([docker-host.md](docker-host.md) or
   [routeros-container.md](routeros-container.md)), set `PORTAL_INSTANCE_NAME` (e.g. `Hub`) and
   `PORTAL_FEDERATION_URL`, publish 9443 on the WireGuard address.
2. Create the first admin (`artisan portal:admin`), configure sites, routers, channels and settings as
   usual.
3. Admin UI → **Federation**: check name, federation URL and admin URL of this instance (the admin URL is
   used in the approval links of guests waiting here and in the OIDC redirect URI; with OIDC register the
   redirect URI of every instance at the identity provider).

### 6.2 Site A (and B)

A site joins as a **new, empty** instance; its data comes from the federation.

1. Install the container at the site with its own data folder, `PORTAL_INSTANCE_NAME` (e.g. `Site A`) and
   `PORTAL_FEDERATION_URL`, 9443 on its WireGuard address.
2. On the hub: Federation → **Create join token** (valid 15 minutes, single use). The page shows the token,
   the hub's federation URL and certificate pin (`sha256//...`) and a ready command.
3. On site A either
   - create a local bootstrap admin (`artisan portal:admin`), sign in, open **Join a federation** and enter
     hub URL, token and pin, its own name and federation URL, or
   - run `artisan portal:federation:join https://10.8.0.1:9443 <token> 'sha256//...' --name="Site A"
     --federation-url=https://10.8.0.2:9443 --admin-url=https://10.8.0.2:8443`.

   Compare the pin with the one shown on the hub: it is what authenticates the hub. After joining, the
   bootstrap admin is deleted; sign in with an account of the hub.
4. On site A: site page of the site served here → **home instance** = Site A (or "Take over this site"
   for a site moved from another instance), routers as usual; the router script points to this instance.
   Certificates page: the certificate source of this instance (each instance pulls with its own record).
5. Repeat for site B. Federation page on the hub: both instances with last contact, lag and clock skew.

Check: create a code on site B, it appears on site A within about 30 seconds (relayed by the hub).

## 7. Operation

- **Federation page** (admin only, every instance): this instance's identity and pin; hub; the instances
  with last contact, events waiting in each direction, clock skew, pause state (resume button) and
  remove button; join tokens (hub).
- **Offline rule "SMS only"** (setting on the federation page, default on): when an instance has not
  reached the hub for 2 minutes it accepts **new** logins only by SMS or site code (limited mode, notice
  on the portal); codes already in use and known devices keep working; normal codes work if the device
  is already bound to them. Never applies on the hub. Switch it off to keep normal codes working while the
  hub is down (each site then accepts codes the hub may already have revoked).
- **Telegram:** one bot for all instances; exactly one instance polls the button presses (**Telegram
  owner**, Settings, default the hub) and forwards presses for guests waiting at other instances to them.
  Gotify/webhook links point to the admin URL of the instance where the guest waits.
- **Sessions:** the guest list shows sessions of other instances live (unreachable instances are named);
  the disconnect button goes to the serving instance through the hub.
- **Routers:** replicated for recovery, but only the site's home instance serves them.
- **Changes only the hub shares** (since v0.21.0): new admins or promotions to admin, and the settings
  2FA requirement, OIDC, admin URL, Telegram owner and "offline: SMS only". Made on another instance they
  stay local to it (the others drop them and list `federation.event_refused` in the audit log), so make
  them on the hub. Hotspot host names and portal URL of a site are shared from the hub or the site's home
  instance. Manage admin accounts, their passwords and 2FA on the hub; such a change made on another
  instance (including demoting or deleting an admin) stays local to it.

## 8. Branding files

Logo and favicon are referenced by content hash. An instance without the file fetches it from the
instance that set it (or the hub) and checks it like an upload (size, type, SVG rules, hash); until then
the built-in default is shown. Files not available yet are retried every 5 minutes.

## 9. Special situations

### 9.1 Hub lost for good: hub takeover

1. On the instance that becomes the new hub (e.g. site A): Federation → **Make this instance the hub**,
   confirm with the instance name. The old hub is removed, the data key is rotated and the page lists the
   secrets the old hub knew (renew them, see 9.3).
2. On the new hub: create a join token. On every other instance: Federation → **Re-pair with a new hub**
   (new hub URL, token, pin). Data stays; replication continues.
3. If the old hub comes back, it notices that it was replaced, stops replicating and shows a warning.
   Re-pair it with the new hub as a normal instance (it keeps its data and sends what it wrote meanwhile).

### 9.2 Removing an instance

Federation page on the hub → **Remove** (not possible for the hub itself; take it over first). Its
certificate is no longer trusted anywhere, the data key is rotated automatically and a checklist names
the secrets the removed instance knew: channel tokens/API keys/webhook secrets, OIDC client secret,
RADIUS secrets of the routers (regenerate on the site page and enter on each router), certificate source
API keys. Stop and wipe the removed container.

### 9.3 Data key

Replicated secrets (TOTP secrets, channel configuration, OIDC client secret, RADIUS secrets, certificate
source API keys) are encrypted with a federation-wide data key; every instance has the key ring (in its
database, encrypted with its own `APP_KEY`). A compromised instance can read them, which is why removing
an instance or taking over the hub rotates the key and lists them for renewal. New keys reach the other
instances with the heartbeats (and with the re-pairing answer).

### 9.4 Updates (rolling)

Update one instance after the other. Unknown columns from newer instances are ignored, missing ones get
their defaults. If an instance uses a newer federation protocol, the older instances pause replication
with it (warning on the federation page) until they are updated too; then resume.

### 9.5 Clock skew

Above 30 s difference the federation page warns; above 5 minutes replication with that instance pauses.
Fix NTP, then resume on the federation page (both sides) or with `artisan portal:federation:repair
resume <instance>`.

### 9.6 Backups and restore

Backups include `/data/federation`, so a restored instance keeps its certificate pin. After a restore
(manual extract, README) the instance first fetches the changes it made after the backup back from its
peers; until then replicated data is read-only (new guest logins and admin changes answer "temporarily
unavailable", running sessions keep working). Admins can still log in and out (password, OIDC, TOTP
code; recovery codes only after the resync) and open the admin UI; audit entries of that time go to the
application log instead of the audit log. This normally takes one sync run (30 s) once the hub (or,
on the hub, every instance) is reachable. Status: `artisan portal:federation:repair status`.
If an instance that has those changes is gone for good, or the recovery keeps failing (e.g. an invalid
signature in the status output): `artisan portal:federation:repair skip-resync --force` (changes not
recovered are lost). The skip remembers the own sequence each peer reported: new changes continue
above it (no reused numbers), and the resync starts again only if a peer later reports more. An
instance that had fewer of those changes than the highest report stops at the gap ("gap: expected seq");
there run `artisan portal:federation:repair reset-cursor <restored instance> --seq=<highest reported>`
(the number is in the status output of the restored instance). A backup older than the retention period cannot be
caught up (the others have compacted the changes in between): set the instance up fresh and join again.

### 9.7 Retention

The daily purge (03:30 UTC) also removes deleted rows (tombstones) and old entries of the change log, but
only after the retention period and once every directly connected instance has them. An instance that
is unreachable for longer than the retention period holds this back until it catches up or is removed.

## 10. Repair command

`artisan portal:federation:repair <action> [instance] [options]` (instance = id or name):

| Action | Effect |
|---|---|
| `status` | Instances, last contact, transport and apply state, applied / available sequence, resync state |
| `resume <instance>` | Lift a pause (clock skew, protocol, rejected event) of that instance |
| `repin <instance> --pin=sha256//...` | Trust a new certificate of that instance (its `/data/federation` was lost and regenerated); take the pin from its federation page |
| `reset-cursor <instance> [--seq=N]` | Apply that instance's changes again from sequence N (default 0; idempotent) and relearn what it has |
| `resync` | Fetch this instance's own changes back from the peers (as after a restore) |
| `skip-resync --force` | Lift the restore block without them (see 9.6) |

All changing actions are written to the audit log (while the restore block is active: to the application log).

## 11. Troubleshooting

| Symptom | Check |
|---|---|
| Instance shows "no contact" | WireGuard up? `curl -k https://<peer>:9443/federation/status` from the host answers 403 (no client certificate) when the port is reachable. Federation URL on the federation page correct? |
| "certificate pin" / TLS errors | The peer's `/data/federation` was regenerated: `repair repin`, or remove and join it again |
| "paused: clock skew ..." | NTP on both hosts, then resume |
| "paused: protocol ..." | Update the older instance, then resume |
| Changes of one instance missing elsewhere | Federation page: apply pause of that origin (reason shown), `repair status` |
| Site works only with SMS | Limited mode: this instance has not reached the hub for 2 minutes (see 7) |
| Writes fail with "temporarily unavailable" after a restore | Restore resync waits for the peers (9.6) |
