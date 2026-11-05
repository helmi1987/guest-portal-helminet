# AVM FRITZ!Box: not a NAS, but fine as the internet router in front of a MikroTik

A FRITZ!Box **cannot** be the NAS of a portal site: its guest access has no RADIUS, no redirect to an external portal
and no way to let a single guest device out or block it again. There is no FRITZ!Box driver and none is planned
(decision 2026-11-05, [CONCEPT.md](../CONCEPT.md) §5.1). What works is the usual setup with the FRITZ!Box as the
internet router (DSL, cable or fibre) and a MikroTik behind it that runs the hotspot (§3).

This page is based on a desk study of 2026-11-05 against AVM's TR-064 specifications, AVM's knowledge base, the
FRITZ!OS release notes up to 8.25 and the Home Assistant source (§1). **No FRITZ!Box was tested.** Statements that
could not be confirmed from a source are marked **(to verify)**; open points for a test box are in §7. Background on
what a NAS driver must do: [nas-driver.md](nas-driver.md) (§3 for drivers that authorize through an API).

## 1. Verdict and reasons

A NAS driver needs either a RADIUS-backed login (MikroTik, generic driver) or, for a controller-based portal, a
vendor redirect that names the device plus `authorizeClient`, `deauthorizeClient` and `clientStatus` **per client**
([nas-driver.md](nas-driver.md) §3). FRITZ!OS offers neither:

- **No per-device control for guest devices.** All devices in the guest network share the fixed access profile
  "Guest"; the profile can only be changed as a whole (AVM KB 294, below). The TR-064 call
  `X_AVM-DE_HostFilter:DisallowWANAccessByIP` blocks "a LAN device" and never mentions the guest network
  ([TR-064 Host Filter, v8](https://fritz.support/resources/TR-064_Host_Filter.pdf)); the host list flags guest
  devices separately (`X_AVM-DE_Guest`, and `X_AVM-DE_HostFilterEditHide` "not supported for this host",
  [TR-064 Hosts, v32](https://fritz.support/resources/TR-064_Hosts.pdf)). AVM's FRITZ!OS 7.25 release notes for the
  7590 fixed a page that showed device blocking for guest devices "even though this function is reserved for home
  network devices" ([7590 release notes](https://public.avm.de/fritzbox/fritzbox-7590/other/fritz.os/info_en.txt)).
  Home Assistant's fritz integration gives guest devices no internet switch for the same reason
  ([PR 63407](https://github.com/home-assistant/core/pull/63407),
  [coordinator.py](https://github.com/home-assistant/core/blob/dev/homeassistant/components/fritz/coordinator.py)).
- **No RADIUS and no external portal.** The guest access is a private Wi-Fi guest access or a public hotspot with
  an optional own "captive portal" page (text, logo, consent to terms of use); there is no option for an external
  portal or a RADIUS server ([AVM KB 294](https://uk.fritz.com/service/knowledge-base/dok/FRITZ-Box-7590/294_Setting-up-Wi-Fi-guest-access-in-the-FRITZ-Box/),
  release notes of the 7590 and the [5590 Fiber](https://public.avm.de/fritzbox/fritzbox-5590-fiber/other/fritz.os/info_en.txt)
  up to 8.25).
- **The consent page ("Vorschaltseite") cannot be the portal's entry.** Guests must accept the terms before they get
  internet ([AVM KB 1469](https://uk.fritz.com/service/knowledge-base/dok/FRITZ-Box-7590/1469_Cannot-access-the-internet-over-guest-access/)).
  After the consent the device is **fully online**, and only then is it forwarded to an optional fixed home page
  URL that carries no MAC, no IP and no token (guides such as
  [tutonaut.de](https://www.tutonaut.de/captive-portal-in-fritzbox-aktivieren-und-einrichten/); no placeholders
  documented anywhere). A portal login behind it would be decorative: the portal could neither release nor block
  the device.
- **No usage per device and no DHCP options** for the guest network: TR-064 has no byte counters per host, and the
  guest network's DHCP (fixed 192.168.189.0/24) only lets the lease time be set, so option 114 cannot be
  advertised (KB 1469, 7590 release notes,
  [TR-064 LAN Host Config Management](https://fritz.support/resources/TR-064_LAN_Host_Config_Management.pdf)).
- **The guest access is not available** in IP-client mode (KB 294), and in Mesh Repeater mode the captive portal
  settings are not available ([AVM guest access PDF, copy by an ISP](https://digiweb.ie/wp-content/uploads/2024/10/FRITZBox-Guest-Access.pdf)).

Community reports agree (guest devices cannot be restricted one by one; blocking the profile blocks all guests):
[administrator.de](https://administrator.de/forum/fritzbox-gastnetz-internetzugang-iot-geraete-674449.html),
[computerbase.de](https://www.computerbase.de/forum/threads/fritzbox-gastzugang-einzeln-sperren.2258266/),
[heimnetz.de](https://forum.heimnetz.de/threads/vorschaltseite-gastzugang-wird-nicht-aufgerufen.804/).
The same guest model applies to all current models (7530 AX, 7590 (AX), 7690, 5590, 4060, 6690 Cable and others);
AVM's knowledge base articles are identical across models.

**Rejected workaround.** `DisallowWANAccessByIP` does work for devices in the **home** network. A driver could put
guests into the owner's home network, deny new devices by default through the "Standard" access profile and release
them by IP. That gives up the isolation from the owner's devices, also blocks every new device of the owner, breaks
iOS joins with a "never" profile, is keyed by IPv4 address (no IPv6, the IP can change) and needs the portal or an
agent inside the home LAN with app rights on the whole box. It is a parental-control hack, not a hotspot, and is not
built.

## 2. FRITZ!Box guest access compared with the MikroTik hotspot

| | MikroTik hotspot (RouterOS 7 driver) | FRITZ!Box guest access |
|---|---|---|
| Redirect to an external portal with MAC/IP | yes (`login.html` handoff) | no; fixed URL after consent, device already online |
| Walled garden before login | yes | only the global "Guest" profile allow-list (all guests) |
| Per-device release and block | RADIUS Access-Accept / Disconnect-Request | none for guests (home network only) |
| Session-Timeout, rate limit | RADIUS attributes | none (global guest bandwidth reservation only) |
| Usage accounting | RADIUS accounting | none per host |
| Captive portal API (DHCP option 114) | yes | no DHCP options |
| Roaming, MAC whitelist | RADIUS MAC authentication | no |

## 3. Setup: FRITZ!Box as internet router, MikroTik behind it

The MikroTik is set up exactly as in [guest-network-setup.md](guest-network-setup.md): guest VLAN, Wi-Fi, firewall,
hotspot, RADIUS and certificates are unchanged. The FRITZ!Box only provides the internet uplink. Pick one way to
connect them.

### 3.1 Option a: MikroTik WAN on a FRITZ!Box LAN port (double NAT)

- Connect the MikroTik's WAN port to LAN 1–3 of the FRITZ!Box. The MikroTik gets its WAN address from the FRITZ!Box
  DHCP (home network) and masquerades its own networks as usual.
- Give the MikroTik a fixed address in the FRITZ!Box (home network → network connections → device → "Always assign
  this network device the same IPv4 address"; menu names vary by FRITZ!OS version **(to verify)**).
- Double NAT is fine for a hotspot: logins, RADIUS to a portal on the MikroTik side and the VPN to a central portal
  are all outbound (§4).
- The MikroTik's WAN is in the owner's home network. The guest isolation is the MikroTik's firewall
  ([guest-network-setup.md](guest-network-setup.md) §5): its forward chain must drop guest traffic to the FRITZ!Box
  home network (for example 192.168.178.0/24), not only to the MikroTik's own LAN.

### 3.2 Option b: the FRITZ!Box LAN guest port as uplink (recommended for isolation)

The FRITZ!Box can turn LAN 4 into a **LAN guest access**: devices on that port get internet but are isolated from
the home network ([AVM KB 949](https://at.fritz.com/en/apps/knowledge-base/FRITZ-Box-5690/949_Setting-up-LAN-guest-access-in-the-FRITZ-Box)).
Use it as the MikroTik's uplink, so the guest segment stays outside the owner's LAN even if the MikroTik firewall
has a gap.

On the FRITZ!Box:
1. Home network → network → network settings: switch on "LAN guest access" for LAN 4 (menu path after KB 949;
   it varies by version **(to verify)**).
2. Guest access settings: **switch off** the captive portal / consent page ("Display Captive Portal",
   "Anmeldung … nur nach Zustimmung zu den Nutzungsbedingungen"). Otherwise the MikroTik itself would have to accept
   the terms in a browser before it gets internet, and the FRITZ!Box would intercept the guests' first web request
   **(to verify** whether the consent page also applies to the LAN guest port on every model).
3. Do **not** use the FRITZ!Box guest Wi-Fi for the portal's guests. Switch it off, or keep it for the owner's own
   visitors who do not need the portal; portal guests use the MikroTik's guest SSID.
4. The FRITZ!Box serves DHCP on LAN 4 (the guest network, by default 192.168.189.0/24 per KB 1469; that the LAN
   guest port uses the same range **(to verify)**). The MikroTik's WAN takes a DHCP lease
   there; whether the FRITZ!Box can reserve a fixed address in the guest network is not documented **(to verify)**,
   and nothing in this setup depends on it unless you forward ports to the MikroTik (§4.4).
5. Check the guest profile ("Guest" in the parental controls): it applies to the MikroTik and so to every portal
   guest behind it. Leave it unrestricted, or the FRITZ!Box blocks or filters all guests at once.
6. No port forwards are needed for the portal's guest login, RADIUS, the certificate download or SMS/Telegram:
   those flows run inside the MikroTik's side or leave outbound through the FRITZ!Box (§4).

Limits of option b: the MikroTik and the portal on its side cannot reach the owner's home network (that is the
point), so a portal **in the home LAN** does not work with option b (use option a for that, §4.2). Port forwards to
a device on the LAN guest port are not documented by AVM **(to verify)**.

### 3.3 Option c: PPPoE passthrough or modem mode

Where the line uses PPPoE (DSL, some fibre lines), FRITZ!OS can let connected devices set up their own internet
connection ("PPPoE passthrough"), and the MikroTik then dials PPPoE itself and gets the public IPv4 address. Which
models and FRITZ!OS versions offer this, and whether cable or fibre models have a pure modem mode, is not covered by
the sources of this study **(to verify)**. Some providers allow only one PPPoE session or none from customer
equipment **(to verify** with your provider).
- MikroTik: `/interface pppoe-client` on the port to the FRITZ!Box, `add-default-route=yes`, masquerade on the PPPoE
  interface. MTU 1492 (PPPoE over Ethernet); clamp the TCP MSS if large pages hang
  (`/ip firewall mangle add chain=forward protocol=tcp tcp-flags=syn action=change-mss new-mss=clamp-to-pmtu
  out-interface=<PPPOE>`).
- Advantage: no double NAT and a public address on the MikroTik itself, which helps only when the portal is reached
  over the internet (§4.4).

## 4. Which portal flows cross the FRITZ!Box

The flow table in [guest-network-setup.md](guest-network-setup.md) §1 allows NAT only for the guests' web traffic and
the internet; for **RADIUS auth/accounting (UDP 1812/1813, router → portal) and Disconnect/CoA (UDP 3799,
portal → router) it says "NAT allowed? No"**. Its §7.1 gives the reason: FreeRADIUS identifies a router by its source IP
plus secret, and the portal sends Disconnect-Requests to the router IP it has stored. The router IP in the admin UI
(`nas_ip`) is a required, unique **IPv4 address**; host names are not accepted. The router certificate and hotspot
page downloads (TCP 443, router → portal) are accepted only from that registered router IP (setup guide §6.1), and
the read-only router API (setup guide §10c) is a connection from the portal to the router (TCP 443 for REST or 8729 for API-SSL).

So the answer depends on where the portal runs:

### 4.1 Portal on the MikroTik side (recommended for one site)

The portal runs as a RouterOS container on the MikroTik ([routeros-container.md](routeros-container.md)) or on a
Docker host in a MikroTik network ([docker-host.md](docker-host.md)). RADIUS, Disconnect, the certificate and page
downloads and the router API all stay behind the FRITZ!Box; the router IP is the MikroTik's internal address. The
FRITZ!Box only carries outbound traffic: SMS providers, Telegram (long polling), social login, push channels and the
guests' internet. **No port forward, no fixed public IP.** Works with options a, b and c.

Certificates: the portal and router certificates use DNS-01 ([guest-network-setup.md](guest-network-setup.md) §8.2),
so no inbound port 80 is needed. If the MikroTik uses the FRITZ!Box as upstream resolver, its DNS rebinding
protection may drop answers with private addresses **(to verify)**; pin the portal name on the MikroTik as the setup guide's
§8.1 describes, which the setup assistant does.

### 4.2 Portal in the owner's home LAN (option a only)

The portal runs on a host in the FRITZ!Box home network and the MikroTik's WAN is on a FRITZ!Box LAN port. There is
no NAT between them: the router IP is the MikroTik's WAN address in the home network (fixed by the FRITZ!Box, §3.1).
- Set `src-address` of `/radius` to that WAN address and register it as router IP.
- The MikroTik's input chain must accept UDP 3799 from the portal IP on its WAN (Disconnect), and TCP 443/8729 from
  the portal IP if you use the router API.
- Guests reach the portal through the MikroTik's masquerade, which the setup guide's §1 allows for the guest web traffic; open the
  forward chain for guests → portal IP TCP 443 only, everything else in the home network stays dropped.
- This does **not** work with option b (the LAN guest port is isolated from the home network).

### 4.3 Portal elsewhere, reached through a VPN (recommended for several sites)

The usual multi-site layout ([guest-network-setup.md](guest-network-setup.md) §7): the MikroTik builds a site-to-site
VPN (for example WireGuard) to the central site. The MikroTik **initiates** the tunnel, so it passes the FRITZ!Box
NAT outbound; keep it alive with `persistent-keepalive` (for example 25 s) so that the portal's Disconnect-Requests
reach the router through the tunnel at any time. The router IP is the MikroTik's tunnel or LAN address, routed
without NAT as the setup guide's §7.1 requires. **No port forward on the FRITZ!Box and no fixed public IP.** Works with options a, b
and c. The same applies to federation (TCP 9443 between instances, [federation.md](federation.md)): run it through
the tunnel.

### 4.4 Portal elsewhere, reached over the internet without VPN (not recommended)

If the portal is reached directly over the internet, every packet from the MikroTik leaves through the FRITZ!Box's
NAT, which breaks the rule of the setup guide's §1 and §7.1. What it would take, and what happens:
- **Router IP = the FRITZ!Box public IPv4.** That needs a **fixed public IPv4 address**: with a dynamic address
  every change makes the router an unknown RADIUS client (requests dropped silently), and the certificate and
  hotspot page downloads are refused until the router IP is changed in the admin UI. A dynamic DNS name does not
  help, because the router IP is an IP address, not a name. Lines without a public IPv4 address (DS-Lite, carrier
  NAT) cannot do this at all.
- **Disconnect-Request:** a port forward UDP 3799 on the FRITZ!Box to the MikroTik (in option c the MikroTik has the
  public address itself and needs no forward). Whether the MikroTik then accepts the request depends on the source
  address matching its `/radius` entry ([guest-network-setup.md](guest-network-setup.md) §6.5, **(to verify)**).
  Without it, revoking or shortening a grant takes effect only at the Session-Timeout.
- **Router API:** a port forward TCP 443 or 8729 to the MikroTik, which exposes the router's API to the internet.
  Restrict it to the portal's address on the MikroTik (`/ip service set ... address=`). Without it, the setup
  assistant's state check is unavailable and the commands are confirmed by hand.
- **Only one router per public IP:** two MikroTiks behind the same FRITZ!Box would arrive with the same address and
  collide (setup guide §7.1).
- RADIUS then crosses the internet without a tunnel (only the shared secret and Message-Authenticator protect it).
  Use a VPN (§4.3) instead.
- Whether the LAN guest port (option b) allows port forwards at all is **(to verify)**; with option b use §4.1 or
  §4.3.

## 5. Limitations and troubleshooting

| Symptom or topic | Cause | What to do |
|---|---|---|
| Guests get no internet after the login, the MikroTik itself has none | FRITZ!Box consent page or guest profile active on the uplink (option b) | Switch the captive portal / consent off for the guest access, set the "Guest" profile to unrestricted (§3.2) |
| No LAN guest port in the menu | FRITZ!Box in IP-client mode ("internet via LAN" / using an existing connection): no guest access in that mode (KB 294); or the model has no LAN 4 | Use option a, or let the FRITZ!Box be the internet router |
| Portal page does not load before login, names resolve to nothing | Upstream DNS rebinding protection (FRITZ!Box or provider) drops private answers | Pin the portal name on the MikroTik (guest-network-setup §8.1) |
| Large pages hang, small ones load (option c) | PPPoE MTU 1492 | MSS clamping as in §3.3 |
| Double NAT | Options a and b | Not a problem for the hotspot or the portal while router and portal flows stay on the MikroTik side or in a tunnel (§4); avoid port forwards |
| "Router IP mismatch", unknown RADIUS client | RADIUS leaves through the FRITZ!Box NAT (§4.4) | Use §4.1 or §4.3; otherwise a fixed public IP as router IP |
| Revoke does nothing | Disconnect-Request cannot reach the router through the FRITZ!Box | Tunnel (§4.3) or port forward UDP 3799 (§4.4) |
| Guests bypass the hotspot over IPv6 | The MikroTik hands an IPv6 prefix (from the FRITZ!Box) to the guest VLAN; the portal's guest network setup is IPv4 only (CONCEPT §12: NAS addresses IPv4 only) | Give the guest VLAN no IPv6, or drop guest IPv6 forwarding; whether the RouterOS hotspot covers IPv6 is **(to verify)** |
| FRITZ!Box guest Wi-Fi guests never see the portal | The FRITZ!Box guest Wi-Fi is not behind the MikroTik | Portal guests use the MikroTik's SSID only (§3.2) |

## 6. Alternatives

1. **FRITZ!Box + MikroTik** as in §3 (recommended). The RouterOS driver works unchanged; a small MikroTik with Wi-Fi
   (for example hAP ax lite or hAP ax²) can be router and access point at once.
2. **FRITZ!Box + another captive portal behind it**: UniFi with the experimental controller driver
   ([unifi-setup.md](unifi-setup.md)) or any RADIUS captive portal with the experimental generic driver
   ([generic-captive-portal.md](generic-captive-portal.md), for example OPNsense or CoovaChilli). Connect it like the
   MikroTik in §3; §4 applies the same way (RADIUS and Disconnect must not cross the FRITZ!Box NAT).
3. **OpenWrt with CoovaChilli** and the generic driver, either on a separate access point behind the FRITZ!Box or on
   a FRITZ!Box model that OpenWrt supports (for example 7530 or 4040; the box loses FRITZ!OS and DSL support varies).
   OpenWrt access points do not join the FRITZ!Mesh; run them as independent access points with their own SSID.
4. **The generic RADIUS driver on the FRITZ!Box itself:** not possible, FRITZ!OS has no RADIUS.

## 7. Open points for a test box (FRITZ!OS 8.25)

None of these changes the verdict; they would only replace "documented" by "observed".
1. `DisallowWANAccessByIP` and `GetWANAccessByIP` on a guest IP: error code or behaviour, and the value of
   `X_AVM-DE_HostFilterEditHide` for guest hosts.
2. How long the hotspot consent lasts (per MAC, until reboot, per lease) and whether deleting the host entry resets
   it.
3. The captive portal probe answers of iOS, Android and Windows behind the consent page.
4. LAN guest port: consent page behaviour on that port, port forwards to a device on it, fixed DHCP addresses in the
   guest network (§3.2, §4.4).
5. PPPoE passthrough: which models and versions offer it (§3.3).

Report results with the "Test report" issue template (device role "FRITZ!Box as internet router in front of the
MikroTik"). Do not post passwords, real host names or IP addresses.
