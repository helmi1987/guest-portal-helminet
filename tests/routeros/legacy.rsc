# Objects as created by the scripts before the comment keys (comment "guest-portal" / "guest-portal: ...",
# certificate script without comment). Added before each mode's blocks: re-running the current blocks must
# update or replace them, and the uninstall must leave nothing behind (snapshot comparison).
/radius add address=192.0.2.1 secret=legacysecret service=hotspot comment="guest-portal"
/ip dns static add name=legacy.guest.example.com address=10.30.0.1 comment="guest-portal"
/ip pool add name=guest-pool ranges=10.30.0.10-10.30.0.20 comment="guest-portal"
/ip firewall filter add chain=input action=accept in-interface-list=LAN protocol=udp dst-port=67 comment="guest-portal: guest DHCP"
/ip firewall address-list add list=PORTAL address=192.0.2.81 comment="guest-portal: portal instance (guest port)"
# Walled-garden ip entry of scripts before the keys (reported 2026-11-05): replaced by the router script, its dynamic copy follows
/ip hotspot walled-garden ip add dst-address=192.0.2.81 protocol=tcp dst-port=443 action=accept comment="guest-portal"
# (plus sec-guest with comment "guest-portal" in the Wi-Fi menu of the mode, added by run.sh)
/system script add name=guest-portal-cert source=":log info legacy"
