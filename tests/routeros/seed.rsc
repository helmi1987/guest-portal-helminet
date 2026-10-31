# "Foreign" configuration resembling a real router (defconf-like), created before the generated
# scripts run. None of it may be changed or removed by them. Names starting with "guest-portal" but
# not owned by the scripts are included on purpose (prefix/regex matching must not catch them).
/interface bridge add name=bridge comment=defconf
/interface veth add name=veth-trunk address=172.31.255.2/30 gateway=172.31.255.1 comment="test: stand-in trunk port"
/interface bridge port add bridge=bridge interface=veth-trunk comment=defconf
/interface bridge vlan add bridge=bridge vlan-ids=40 tagged=bridge comment="foreign vlan"
/interface list add name=WAN comment=defconf
/interface list add name=LAN comment=defconf
/interface list member add list=LAN interface=bridge comment=defconf
/interface list member add list=WAN interface=ether1 comment=defconf
/ip address add address=192.168.88.1/24 interface=bridge comment=defconf
/ip pool add name=default-dhcp ranges=192.168.88.10-192.168.88.254
/ip dhcp-server add name=defconf interface=bridge address-pool=default-dhcp
/ip dhcp-server option add code=42 name=ntp-foreign value="'192.168.88.1'"
/ip dhcp-server option add code=114 name=captive-foreign value="'https://other.example.net/captive'" comment="guest-portal-old"
/ip dhcp-server network add address=192.168.88.0/24 gateway=192.168.88.1 dns-server=192.168.88.1 dhcp-option=ntp-foreign comment=defconf
/ip dns set allow-remote-requests=yes
/ip dns static add name=router.lan address=192.168.88.1 comment=defconf
/ip dns static add name=nas.lan address=192.168.88.10
/ip dns static add name=old.guest.example.com address=192.168.88.20 comment="guest-portal-old"

# defconf firewall; the extra accept for SSH from WAN keeps the harness connected (ether1 = WAN)
/ip firewall filter
add chain=input action=accept connection-state=established,related,untracked comment="defconf: accept established,related,untracked"
add chain=input action=drop connection-state=invalid comment="defconf: drop invalid"
add chain=input action=accept protocol=icmp comment="defconf: accept ICMP"
add chain=input action=accept dst-address=127.0.0.1 comment="defconf: accept to local loopback (for CAPsMAN)"
add chain=input action=accept in-interface-list=WAN protocol=tcp dst-port=22,8728 comment="test: harness access"
add chain=input action=drop in-interface-list=!LAN comment="defconf: drop all not coming from LAN"
add chain=forward action=accept ipsec-policy=in,ipsec comment="defconf: accept in ipsec policy"
add chain=forward action=accept ipsec-policy=out,ipsec comment="defconf: accept out ipsec policy"
add chain=forward action=fasttrack-connection connection-state=established,related comment="defconf: fasttrack"
add chain=forward action=accept connection-state=established,related,untracked comment="defconf: accept established,related, untracked"
add chain=forward action=drop connection-state=invalid comment="defconf: drop invalid"
add chain=forward action=drop connection-state=new connection-nat-state=!dstnat in-interface-list=WAN comment="defconf: drop all from WAN not DSTNATed"
add chain=forward action=drop src-address=192.0.2.66 comment="guest-portal-old manual block"
/ip firewall nat
add chain=srcnat action=masquerade out-interface-list=WAN ipsec-policy=out,none comment="defconf: masquerade"
add chain=dstnat action=dst-nat protocol=tcp dst-port=8081 to-addresses=192.168.88.10 comment="guest-portal-old forward"
/ip firewall address-list
add list=RFC1918 address=10.0.0.0/8
add list=blocklist address=192.0.2.66 comment="guest-portal-old"
add list=PORTAL address=192.0.2.80 comment="foreign entry in a list name we use"

# RADIUS client of another service
/radius add address=192.168.88.5 secret=foreignsecret service=login comment="foreign"

# Certificates: CA + signed cert (CAPsMAN-like), self-signed ones with confusable names
/certificate
add name=CAPsMAN-CA-test common-name=CAPsMAN-CA-test key-size=2048 days-valid=3650 key-usage=key-cert-sign,crl-sign
sign CAPsMAN-CA-test
add name=CAPsMAN-test common-name=CAPsMAN-test key-size=2048 days-valid=365
sign CAPsMAN-test ca=CAPsMAN-CA-test
add name=guest-portal.example.com common-name=guest-portal.example.com key-size=2048 days-valid=365
sign guest-portal.example.com
add name=my-cert common-name=my-cert key-size=2048 days-valid=365
sign my-cert
add name=guest-portal-ca-x common-name=foreign-ca-x key-size=2048 days-valid=365
sign guest-portal-ca-x
add name=guest-portal-backup common-name=foreign-backup key-size=2048 days-valid=365
sign guest-portal-backup

# Hotspot: default profile with known values (uses a foreign certificate), foreign profile + server
/ip hotspot profile set [find default=yes] login-by=cookie,http-chap,https ssl-certificate=my-cert
/ip hotspot profile add name=hsprof-other hotspot-address=192.168.88.1 dns-name=other.example.net \
    login-by=https ssl-certificate=guest-portal.example.com html-directory=hotspot-other
/ip hotspot add name=hs-other interface=bridge profile=hsprof-other address-pool=default-dhcp disabled=yes
/ip hotspot walled-garden add dst-host=other.example.net action=allow comment="guest-portal-old"
/ip hotspot walled-garden ip add dst-address=192.0.2.80 action=accept comment="foreign"
/ip hotspot walled-garden ip add dst-address=10.0.2.0/24 action=drop comment="foreign: LAN block covering the portal IP"
/file add name=hotspot-other/login.html contents="foreign login page"

# Wi-Fi (wifi package) and legacy CAPsMAN: master configuration with an existing slave
/interface wifi configuration add name=cfg-main ssid=Main-WiFi country=Germany
/interface wifi configuration add name=cfg-iot ssid=IoT-WiFi country=Germany
/interface wifi provisioning add action=create-dynamic-enabled master-configuration=cfg-main slave-configurations=cfg-iot comment="foreign"
/caps-man configuration add name=cfg-main ssid=Main-WiFi country=germany
/caps-man configuration add name=cfg-iot ssid=IoT-WiFi country=germany
/caps-man provisioning add action=create-dynamic-enabled master-configuration=cfg-main slave-configurations=cfg-iot comment="foreign"

# Scheduler/scripts, one with a confusable name
/system script add name=backup-foreign source=":log info backup"
/system script add name=guest-portal-cert-old source=":log info old"
/system scheduler add name=backup-foreign interval=1w on-event=backup-foreign start-time=startup
