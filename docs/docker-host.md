# Running on a Docker host (Docker Compose)

Any Linux Docker host with Docker Compose (e.g. Debian, a NAS, Unraid). Not tested yet.
Unraid: use the **Docker Compose Manager** plugin, or translate the values into a Docker template.

## 1. Choose the network mode

The routers talk to the portal by IP (RADIUS 1812/1813 UDP, router certificate and `login.html`
download on 443) and the portal sends Disconnect requests to the routers (UDP 3799). The portal
checks the router by its **source IP**, so that IP must arrive unchanged.

| Mode | Portal IP | Notes |
|---|---|---|
| **A: own LAN IP (macvlan/ipvlan, recommended)** | dedicated LAN IP, e.g. `192.168.1.50` | No port conflict with services on the host (e.g. a NAS web UI on 443). Source IPs are preserved. Disconnects leave with the container IP. |
| B: bridge + published ports | the host IP | Ports 443/8443 must be free on the host. Docker keeps the source IP for published ports (iptables DNAT, **to verify** on your host). Disconnects leave with the server IP (masquerade). |

In both modes the IP the routers use in `/radius address=...` and the IP the portal sends
Disconnects from must be the same one (the setup assistant / router script uses it).

Mode A: with macvlan the host itself cannot reach the container. If the host (or a reverse
proxy on the host network) must reach the portal, add a macvlan shim interface on the host,
or on Unraid enable Settings → Docker → "Host access to custom networks".

## 2. Private image (GHCR)

While the repository is private, log in once on the Docker host with a GitHub token that has
only `read:packages`:

```bash
docker login ghcr.io -u <github-user>
```

Do not put the token into the compose file.

## 3. Compose file

Create a folder (e.g. `/opt/guest-portal`, Unraid: Compose Manager → Add New Stack) with this
`docker-compose.yml`:

```yaml
services:
  portal:
    image: ghcr.io/helmi1987/mikrotik-guest-portal:edge          # :latest after the first release, or a pinned X.Y.Z-YYYYMMDD tag
    container_name: guest-portal
    restart: unless-stopped
    # --- Mode A: own LAN IP (remove the "ports:" block) ---
    networks:
      lan:
        ipv4_address: 192.168.1.50          # free IP outside your DHCP range
    # --- Mode B: bridge + published ports (remove "networks:" above and below) ---
    # ports:
    #   - "443:443"
    #   - "8443:8443"
    #   - "1812:1812/udp"
    #   - "1813:1813/udp"
    volumes:
      - ./data:/data                          # Unraid: /mnt/user/appdata/guest-portal:/data
    environment:
      APP_URL: https://portal.example.com     # name the guests/routers use (must match the certificate)
      PORTAL_HOSTNAME: portal.example.com
      TZ: Europe/Zurich                       # display time zone (admin UI, notifications, backup names); data stays UTC
      # TRUSTED_PROXIES: 192.168.1.10       # only if the admin UI is published through a reverse proxy

networks:
  lan:
    external: true
    name: br0                                # existing macvlan network, see below
```

Mode A needs an existing macvlan network. Unraid creates one (name `br0`, `eth0` or `bond0`,
check with `docker network ls`). Elsewhere create it once:

```bash
docker network create -d macvlan --subnet=192.168.1.0/24 --gateway=192.168.1.1 -o parent=eth0 br0
```

Then `docker compose up -d` (Unraid: "Compose Up"). First start fills the data folder (`.env` with
generated secrets, SQLite database, self-signed certificate).

## 4. First admin

```bash
docker exec -it guest-portal artisan portal:admin
```

Admin UI: `https://<portal-ip>:8443/admin` (self-signed certificate until you add your own).

## 5. Certificates

Copy PEM files (full chain + key) to `<data folder>/certs/`:

- `portal.crt` + `portal.key`: guest and admin ports (name from `APP_URL`).
- `router.crt` + `router.key`: the routers' HTTPS hotspot login (e.g. a wildcard).

Changes are picked up within about 10 s (nginx reload; routers fetch the router certificate via
their scheduler). Instead of copying files, admin UI → Certificates can pull both certificates
every 6 h from CertWarden or a URL returning a PEM bundle.

## 6. DNS and firewall

- DNS: `portal.example.com` → portal IP, resolvable for guests (hotspot DNS) and routers.
- Allow towards the portal IP: 443/tcp from guest subnets and site routers, 8443/tcp from the
  admin network only, 1812-1813/udp from site routers only.
- Allow from the portal IP: 3799/udp to the site routers (Disconnect), outgoing HTTPS (SMS,
  Telegram, Gotify).
- Remote sites: guest subnets must reach the portal IP over the VPN, see
  [guest-network-setup.md](guest-network-setup.md) section 7.4.

## 7. Next steps

Admin UI → Sites → add site (hotspot host name) and router (NAS IP, as seen by the portal) →
follow the router configuration / setup assistant. Backups: see section 9. Logo, colours and
custom CSS of the guest pages: admin UI → Settings → Appearance, see [branding.md](branding.md).

## 8. Update

`docker compose pull && docker compose up -d` in the stack folder (Unraid: "Update Stack"). Data stays in `/data`.

## 9. Backups

The portal writes a daily backup to `/data/backups` (database snapshot, `.env`, certificates,
uploaded logo/favicon files from `/data/branding`;
admin UI → Backups: create, download, delete, number kept). Backups contain secrets (`APP_KEY`
decrypts stored passwords and tokens): keep them private. To keep them off the host, bind-mount a
private share there, e.g. `- /mnt/user/backups/guest-portal:/data/backups`; the container user
`portal` must be able to write to it. Restore: see the README ("Restore").
