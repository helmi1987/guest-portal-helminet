# Running on Unraid (Docker Compose)

Target: Unraid 6.12+/7.x (not tested yet) with the **Docker Compose Manager** plugin (Apps → search
"Compose Manager"). Without the plugin, translate the same values into a `docker run` or an
Unraid Docker template.

## 1. Choose the network mode

The routers talk to the portal by IP (RADIUS 1812/1813 UDP, router certificate and `login.html`
download on 443) and the portal sends Disconnect requests to the routers (UDP 3799). The portal
checks the router by its **source IP**, so that IP must arrive unchanged.

| Mode | Portal IP | Notes |
|---|---|---|
| **A: own IP on `br0` (recommended)** | dedicated LAN IP, e.g. `192.168.1.50` | No port conflict with the Unraid web UI (which may use 80/443). Source IPs are preserved. Disconnects leave with the container IP. |
| B: bridge + published ports | the Unraid server IP | Port 443 can collide with the Unraid web UI (if it uses 443): change the Unraid web UI port first. Docker keeps the source IP for published ports (iptables DNAT, **to verify** on your host). Disconnects leave with the server IP (masquerade). |

In both modes the IP the routers use in `/radius address=...` and the IP the portal sends
Disconnects from must be the same one (the setup assistant / router script uses it).

Mode A: Settings → Docker → (Advanced view) enable "Host access to custom networks" if the
Unraid host itself (or other containers such as a reverse proxy on the host network) must
reach the portal; with macvlan the host cannot reach it otherwise.

## 2. Private image (GHCR)

While the repository is private, log in once on the Unraid console with a GitHub token that has
only `read:packages`:

```bash
docker login ghcr.io -u <github-user>
```

Do not put the token into the compose file.

## 3. Compose file

Compose Manager → Add New Stack → name `guest-portal` → Edit Stack → Compose File:

```yaml
services:
  portal:
    image: ghcr.io/OWNER/REPO:edge          # :latest after the first release, or a pinned X.Y.Z-YYYYMMDD tag
    container_name: guest-portal
    restart: unless-stopped
    # --- Mode A: own IP on br0 (remove the "ports:" block) ---
    networks:
      br0:
        ipv4_address: 192.168.1.50          # free IP outside your DHCP range
    # --- Mode B: bridge + published ports (remove "networks:" above and below) ---
    # ports:
    #   - "443:443"
    #   - "8443:8443"
    #   - "1812:1812/udp"
    #   - "1813:1813/udp"
    volumes:
      - /mnt/user/appdata/guest-portal:/data
    environment:
      APP_URL: https://portal.example.com     # name the guests/routers use (must match the certificate)
      PORTAL_HOSTNAME: portal.example.com
      TZ: Europe/Zurich
      # TRUSTED_PROXIES: 192.168.1.10       # only if the admin UI is published through a reverse proxy

networks:
  br0:
    external: true                           # Unraid's custom network (may be named br0, eth0 or bond0)
```

Check the custom network name with `docker network ls` (it depends on the Unraid network
settings, e.g. `br0`, `eth0`, `bond0`).

Then "Compose Up". First start creates `/mnt/user/appdata/guest-portal` content (`.env` with
generated secrets, SQLite database, self-signed certificate).

## 4. First admin

```bash
docker exec -it guest-portal php artisan portal:admin
```

Admin UI: `https://<portal-ip>:8443/admin` (self-signed certificate until you add your own).

## 5. Certificates

Copy PEM files (full chain + key) to `/mnt/user/appdata/guest-portal/certs/`:

- `portal.crt` + `portal.key`: guest and admin ports (name from `APP_URL`).
- `router.crt` + `router.key`: the routers' HTTPS hotspot login (e.g. a wildcard).

Changes are picked up within about 10 s (nginx reload; routers fetch the router certificate via
their scheduler).

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
follow the router configuration / setup assistant. Backups: `/mnt/user/appdata/guest-portal`
(contains secrets: keep backups private).

## 8. Update

Compose Manager → "Update Stack" (pulls the image and recreates the container), or
`docker compose pull && docker compose up -d` in the stack folder. Data stays in `/data`.
