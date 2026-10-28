# Running on a RouterOS 7 container

Generic example; adjust names, addresses and disks to your setup. Commands marked
**(to verify)** depend on the RouterOS version (see CONCEPT.md §3).

## Prerequisites

- Container package installed, device mode allows containers:
  `/system/device-mode/update container=yes` (needs physical confirmation) **(to verify)**.
- External storage recommended (e.g. `usb1`), ~512 MB free RAM for the container.

## Network

```
/interface/veth add name=veth-portal address=172.17.0.2/24 gateway=172.17.0.1
/interface/bridge add name=containers
/interface/bridge/port add bridge=containers interface=veth-portal
/ip/address add address=172.17.0.1/24 interface=containers
```

Do not NAT RADIUS between routers and the instance: FreeRADIUS identifies routers by source IP.
Allow guests to reach only the guest port (TCP 443) of the instance; allow the admin port
(TCP 8443) only from admin/VPN networks.

## Container

```
/container/config set registry-url=https://ghcr.io tmpdir=usb1/tmp
/container/mounts add name=portal-data src=usb1/portal-data dst=/data
/container add remote-image=ghcr.io/OWNER/REPO:latest interface=veth-portal \
    root-dir=usb1/portal-root mounts=portal-data start-on-boot=yes logging=yes
```

- While the image is private, set `username=` and a read-only token as `password=` in
  `/container/config`.
- Only non-secret values belong in `/container/envs` (they appear in config exports), e.g.
  `APP_URL=https://portal.example.com`. Secrets are generated on first start into `/data/.env`.
- Memory limit: property name differs per version (`memory-high` / `memory-max`) **(to verify)**.

## First start

1. `/container/start [find]`, watch `/log print where topics~"container"`.
2. Create the first admin from a shell in the container:
   `/container/shell [find]` then `artisan portal:admin`.
3. Open `https://<container-ip>:8443/admin` (self-signed certificate until one is mounted at `/certs`).

## Update

Pull the new tag and restart: `/container/stop`, `/container/repull` **(to verify)** or remove and
re-add with the same mounts, `/container/start`. Data in `/data` is kept.
