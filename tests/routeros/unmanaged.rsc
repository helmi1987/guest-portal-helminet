# Objects as created by assistant versions 0.6.0 to 0.12.6 (and the manual commands of the setup guide
# before the comment keys): same names, no comment. run.sh replaces __WIFI__ (menu of the mode),
# __DATAPATH__ (mode specific datapath properties) and __COUNTRY__. The router state must report them as
# "unmanaged" with an adopt command; after it the blocks run without "already exists".
/interface vlan add name=vlan-guest interface=bridge vlan-id=30
/ip dhcp-server add name=guest-dhcp interface=vlan-guest address-pool=guest-pool lease-time=30m disabled=no
__WIFI__ datapath add name=dp-guest bridge=bridge vlan-id=30 __DATAPATH__
__WIFI__ configuration add name=cfg-guest ssid="Guest-WiFi" country=__COUNTRY__ security=sec-guest datapath=dp-guest
