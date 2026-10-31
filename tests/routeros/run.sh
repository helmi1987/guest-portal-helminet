#!/usr/bin/env bash
# Runs the generated RouterOS scripts of the setup assistant against a RouterOS CHR and checks that they
# create/replace/remove only their own objects. See tests/routeros/README.md.
#
#   tests/routeros/run.sh [wifi-mode ...]     (default: capsman2 capsman1 local)
#
# Needs a running CHR with a clean configuration (tests/routeros/chr.sh start <copy of base image>),
# reachable via SSH (lib.sh), with the wireless and container packages installed. Environment:
#   PORTAL_IP (default 10.0.2.2: the host seen from the CHR), PORTAL_PORT (stub HTTPS server, 8443),
#   WORK (work directory, default a new temp dir). Exit code 0 = all checks passed.
set -uo pipefail
cd "$(dirname "$0")"
HERE=$(pwd)
REPO=$(cd ../.. && pwd)
source ./lib.sh

PORTAL_IP=${PORTAL_IP:-10.0.2.2}
PORTAL_PORT=${PORTAL_PORT:-8443}
WORK=${WORK:-$(mktemp -d /tmp/routeros-harness.XXXXXX)}
MODES=("${@:-capsman2}")
[ $# -eq 0 ] && MODES=(capsman2 capsman1 local)
TOKEN=testcerttoken0123456789abcdefABCD
N=guest-portal
FAILS=0
mkdir -p "$WORK"

fail() { echo "FAIL: $*"; FAILS=$((FAILS + 1)); }
pass() { echo "ok: $*"; }
check() { # <description> <command...>
    local d=$1; shift
    if "$@"; then pass "$d"; else fail "$d"; fi
}

# Upload and /import one generated block (placeholders replaced with test values); fails on any error.
run_block() { # <file> [label]
    local f=$1 label=${2:-$(basename "$1")} prep=$WORK/run-$(basename "$1")
    sed -e "s/<COUNTRY>/${COUNTRY:-Germany}/g" -e 's/<MASTER_CFG>/cfg-main/g' -e 's/<EXISTING_SLAVES>/cfg-iot/g' \
        -e 's/<TRUNK_PORT_TO_CAP>/veth-trunk/g' -e '/<WIFI_INTERFACE>/d' "$f" > "$prep"
    if grep -v '^[[:space:]]*#' "$prep" | grep -q '<[A-Z_/-]*>'; then
        fail "$label: unreplaced placeholder: $(grep -v '^[[:space:]]*#' "$prep" | grep -o '<[A-Z_/-]*>' | sort -u | tr '\n' ' ')"
        return 1
    fi
    ros_upload "$prep" harness.rsc || { fail "$label: upload"; return 1; }
    local out
    out=$(ros '/import file-name=harness.rsc')
    printf '%s\n' "$out" > "$WORK/out-$(basename "$1").txt"
    if printf '%s\n' "$out" | grep -q 'Script file loaded and executed successfully' \
        && ! printf '%s\n' "$out" | grep -qiE 'script error|syntax error|failure:|bad command|expected end'; then
        pass "$label runs without error"
        [ -n "$(printf '%s\n' "$out" | grep -v 'Script file loaded' | grep -v '^$')" ] \
            && printf '%s\n' "$out" | grep -v 'Script file loaded' | sed 's/^/    | /' | head -40
        return 0
    fi
    fail "$label: RouterOS error"
    printf '%s\n' "$out" | sed 's/^/    | /' | tail -20
    return 1
}

# Configuration snapshot: full verbose export (with secrets, without the timestamp header) plus the
# certificate store and files, both not part of the export.
snapshot() { # <file>
    {
        # keymat-provider: its hkdf-secret="" appears/disappears by itself (RouterOS internal)
        ros '/export terse verbose show-sensitive' | grep -v '^# [0-9]\{4\}-' | grep -v '^# system id' | grep -v '^# software id' \
            | grep -v '^/system keymat-provider'
        echo '#--- certificates'
        ros ':foreach c in=[/certificate find] do={ :put ([/certificate get $c name] . "|" . [/certificate get $c common-name] . "|" . [/certificate get $c fingerprint] . "|key=" . [/certificate get $c private-key] . "|trusted=" . [/certificate get $c trusted]) }' | sort
        echo '#--- files'
        ros ':foreach f in=[/file find] do={ :put ([/file get $f name] . "|" . [/file get $f type] . "|" . [/file get $f size]) }' | grep -v '^harness.rsc|' | sort
        echo '#--- file contents (foreign)'
        ros ':put [/file get [find name="hotspot-other/login.html"] contents]'
    } > "$1"
}

certs() { ros ':foreach c in=[/certificate find] do={ :put ([/certificate get $c name] . "|" . $c . "|" . [/certificate get $c fingerprint] . "|key=" . [/certificate get $c private-key]) }' | sort; }
profile() { ros ":put ([/ip hotspot profile get [find where name=\"$N\"] ssl-certificate] . \"|\" . [:tostr [/ip hotspot profile get [find where name=\"$N\"] login-by]])" | tr ';' ','; }
profile_ok() { # <label>
    local p; p=$(profile)
    if [ "$p" = "$N|mac,https" ] || [ "$p" = "$N|https,mac" ]; then pass "$1 ($p)"; else fail "$1 (got: $p)"; fi
}
norm_fp() { tr -d ':\n ' | tr 'A-F' 'a-f'; }

# Wi-Fi security sec-guest: passphrase and authentication types as rendered for the site.
check_wifi_security() { # <rsc dir> <label>
    local path="/interface wifi security" want got auth
    [ "$MODE" = capsman1 ] && path="/caps-man security"
    want=$(cat "$1/wifi_password")
    check "$2: exactly one sec-guest with the comment key" \
        test "$(ros ":put [:len [$path find where name=sec-guest && comment=\"$N:wifi:sec-guest\"]]")" = 1
    got=$(ros ":put [$path get [find where name=sec-guest && comment=\"$N:wifi:sec-guest\"] passphrase]")
    auth=$(ros ":put [:tostr [$path get [find where name=sec-guest && comment=\"$N:wifi:sec-guest\"] authentication-types]]")
    if [ -n "$want" ]; then
        check "$2: passphrase set exactly (special characters escaped)" test "$got" = "$want"
        check "$2: WPA authentication ($auth)" grep -q 'wpa2-psk' <<<"$auth"
    else
        check "$2: open SSID: no passphrase, no authentication types (got '$got' '$auth')" test -z "$got$auth"
    fi
}

# Every "guest-portal:" comment on the router is one of the keys of managedObjects() (render.php).
check_keys_known() { # <rsc dir> <label>
    local unknown
    unknown=$(ros '/export terse verbose' | grep -o 'comment="\?guest-portal:[^" ]*' | sed 's/comment="\?//' | sort -u \
        | grep -vxF -f "$1/keys" || true)
    if [ -z "$unknown" ]; then pass "$2: all comment keys are listed by managedObjects()"; else fail "$2: unknown keys: $unknown"; fi
    local legacy
    legacy=$(ros '/export terse verbose' | grep -E 'comment="?guest-portal("|: | |$)' || true)
    if [ -z "$legacy" ]; then pass "$2: no legacy comments left"; else fail "$2: legacy comments left"; printf '%s\n' "$legacy" | sed 's/^/    | /'; fi
}

# Static rules of a chain as "comment" lines in order.
chain_rules() { ros ":foreach r in=[/ip firewall filter find where chain=$1 && dynamic=no] do={ :put [/ip firewall filter get \$r comment] }"; }
chain_all() { ros ":foreach r in=[/ip firewall filter find where chain=$1] do={ :put ([/ip firewall filter get \$r dynamic] . \"|\" . [/ip firewall filter get \$r comment]) }"; }

check_firewall_order() { # <rsc dir> <label>
    local chain expected actual
    for chain in input forward; do
        expected=$(grep -o "add chain=$chain .*comment=\"$N:fw:[^\"]*\"" "$1/network.firewall.rsc" | sed 's/.*comment="\(.*\)"/\1/')
        actual=$(chain_rules "$chain")
        local ours n
        n=$(printf '%s\n' "$expected" | grep -c .)
        ours=$(printf '%s\n' "$actual" | head -n "$n")
        if [ "$ours" = "$expected" ]; then
            pass "$2: $chain: $n guest rules first among static rules, in generated order"
        else
            fail "$2: $chain: guest rules not first/in order"
            chain_all "$chain" | sed 's/^/    | /'
        fi
        [ "$(printf '%s\n' "$actual" | grep -c "^$N:fw:")" = "$n" ] || fail "$2: $chain: duplicated guest rules"
        # Foreign rules keep their relative order
        if [ "$(printf '%s\n' "$actual" | grep -v "^$N:fw:")" = "$(cat "$WORK/foreign-$chain.txt")" ]; then
            pass "$2: $chain: foreign rules unchanged and in their order"
        else
            fail "$2: $chain: foreign rules changed or reordered"
        fi
    done
}

wait_cert_script() { # the scheduler/script may still run; wait until no job of it is active
    local i
    for i in $(seq 1 60); do
        [ "$(ros ":put [:len [/system script job find where script=\"$N-cert\"]]")" = 0 ] && return 0
        sleep 2
    done
    fail "certificate script still running after 120s"
}

check_cert_state() { # <label> <leaf dir> -> asserts leaf/CAs/profile, prints cert list
    local label=$1 leafdir=$2 list fp
    fp=$(cat "$leafdir/fingerprint")
    list=$(certs)
    printf '%s\n' "$list" | sed 's/^/    | /'
    local leafline
    leafline=$(printf '%s\n' "$list" | grep "^$N|" || true)
    check "$label: leaf named $N exists once" test "$(printf '%s\n' "$leafline" | grep -c .)" = 1
    check "$label: leaf $N has the served fingerprint" test "$(printf '%s' "$leafline" | cut -d'|' -f3 | norm_fp)" = "$fp"
    check "$label: leaf $N has the private key" grep -q 'key=true' <<<"$leafline"
    check "$label: two chain CAs named $N-ca-1/2" test "$(printf '%s\n' "$list" | grep -cE "^$N-ca-[0-9]+\|")" = 2
    check "$label: no temporary import names left" test "$(printf '%s\n' "$list" | grep -c "^$N-import")" = 0
    profile_ok "$label: profile $N uses ssl-certificate $N, login-by https+mac"
    for c in CAPsMAN-CA-test CAPsMAN-test guest-portal.example.com my-cert guest-portal-ca-x guest-portal-backup test-portal-ca; do
        grep -q "^$c|" <<<"$list" || fail "$label: foreign certificate $c missing"
    done
    diff <(sed -n '/^#--- certificates/,/^#---/p' "$WORK/before.txt" | grep -v '^#' | grep -vE "^($N(-ca-[0-9]+)?|WiFi-CAPsMAN-.*)\|") \
        <(ros ':foreach c in=[/certificate find] do={ :put ([/certificate get $c name] . "|" . [/certificate get $c common-name] . "|" . [/certificate get $c fingerprint] . "|key=" . [/certificate get $c private-key] . "|trusted=" . [/certificate get $c trusted]) }' | sort | grep -vE "^($N(-ca-[0-9]+)?|WiFi-CAPsMAN-.*)\|") \
        >/dev/null && pass "$label: foreign certificates unchanged" || fail "$label: foreign certificates changed"
    diff <(grep '^/ip hotspot profile .*name=default' "$WORK/before.txt") <(ros '/export terse verbose' | grep '^/ip hotspot profile .*name=default') >/dev/null \
        && pass "$label: hotspot profile default unchanged" || fail "$label: hotspot profile default changed"
}

# ---------------------------------------------------------------------------------------------
log "Preparing (work dir $WORK)"
ROUTER_IP=$(ros ':put [/ip address get [:pick [/ip address find where interface=ether1] 0] address]' | cut -d/ -f1)
[ -n "$ROUTER_IP" ] || { echo "cannot read the CHR address of ether1"; exit 1; }
echo "router IP $ROUTER_IP, portal stub https://portal.example.com:$PORTAL_PORT -> $PORTAL_IP"
ros '/system resource print' | grep -E 'version|board-name|uptime'
bash ./pki.sh "$WORK/pki" >/dev/null || { echo "PKI generation failed"; exit 1; }
(cd "$REPO" && php tests/routeros/render.php "$WORK/rsc" "$ROUTER_IP" "$PORTAL_IP" "$PORTAL_PORT") || { echo "render failed"; exit 1; }
for m in "${MODES[@]}"; do mkdir -p "$WORK/login/$(cat "$WORK/rsc/$m/nas_id")"; cp "$WORK/rsc/$m/login.html" "$WORK/login/$(cat "$WORK/rsc/$m/nas_id")/"; done

python3 "$HERE/stub_portal.py" "$PORTAL_PORT" "$WORK/pki/portal/leaf.crt" "$WORK/pki/portal/router.key" \
    "$WORK/pki/current" "$WORK/login" "$TOKEN" "$WORK/stub.log" &
STUB=$!
trap 'kill $STUB 2>/dev/null' EXIT
sleep 1

# The CHR clock lags under TCG; certificates are valid from "now" on the host. Set the router clock
# (UTC, gmt-offset +00:00 on a fresh CHR) a few minutes ahead of the host.
sync_clock() { ros "/system clock set date=$(date -u -d '+5 min' +%Y-%m-%d) time=$(date -u -d '+5 min' +%H:%M:%S)" >/dev/null; }
sync_clock

log "Seeding foreign configuration"
ros_upload "$WORK/pki/portal-ca.crt" test-portal-ca.crt
out=$(ros '/certificate import file-name=test-portal-ca.crt passphrase="" name=test-portal-ca; /file remove [find where name="test-portal-ca.crt"]')
echo "$out" | sed 's/^/    | /'
run_block "$HERE/seed.rsc" "seed" || { echo "seeding failed"; exit 1; }
ros '/file remove [find where name="harness.rsc"]' >/dev/null
snapshot "$WORK/before.txt"
for c in input forward; do chain_rules "$c" > "$WORK/foreign-$c.txt"; done
wc -l "$WORK/before.txt"

first=1
for MODE in "${MODES[@]}"; do
    R=$WORK/rsc/$MODE
    sync_clock
    log "[$MODE] legacy objects of scripts before the comment keys (must be migrated/replaced)"
    run_block "$HERE/legacy.rsc" "[$MODE] legacy objects"
    if [ "$MODE" = capsman1 ]; then ros '/caps-man security add name=sec-guest comment="guest-portal"' >/dev/null
    else ros '/interface wifi security add name=sec-guest comment="guest-portal"' >/dev/null; fi
    log "[$MODE] network step"
    COUNTRY=Germany
    [ "$MODE" = capsman1 ] && COUNTRY=germany
    run_block "$R/network.vlan.rsc" "[$MODE] network.vlan"
    run_block "$R/network.vlan_filtering.rsc" "[$MODE] network.vlan_filtering"
    run_block "$R/network.wifi.rsc" "[$MODE] network.wifi"
    check_wifi_security "$R" "[$MODE] wifi"
    run_block "$R/network.wifi.rsc" "[$MODE] network.wifi (re-run)"
    check_wifi_security "$R" "[$MODE] wifi (re-run)"
    if [ "$MODE" = local ] && [ -f "$WORK/rsc/capsman2/network.wifi.rsc" ]; then
        # Password change on re-run: the block of the open site (capsman2 fixture) removes the password of
        # the own sec-guest, the site's block sets it again
        run_block "$WORK/rsc/capsman2/network.wifi.rsc" "[$MODE] network.wifi of the open site"
        check_wifi_security "$WORK/rsc/capsman2" "[$MODE] wifi (password removed)"
        run_block "$R/network.wifi.rsc" "[$MODE] network.wifi (password again)"
        check_wifi_security "$R" "[$MODE] wifi (password again)"
    fi
    run_block "$R/network.dhcp.rsc" "[$MODE] network.dhcp"
    check "[$MODE] legacy pool updated in place (ranges, key)" \
        test "$(ros ':put [/ip pool get [find where name=guest-pool && comment="guest-portal:net:pool"] ranges]')" = "10.30.0.10-10.30.0.250"
    run_block "$R/network.dhcp.rsc" "[$MODE] network.dhcp (re-run)"
    run_block "$R/network.vlan.rsc" "[$MODE] network.vlan (re-run)"
    run_block "$R/network.captive_option.rsc" "[$MODE] network.captive_option"
    run_block "$R/network.firewall.rsc" "[$MODE] network.firewall"
    check_firewall_order "$R" "[$MODE] firewall (no hotspot yet)"

    log "[$MODE] hotspot step"
    run_block "$R/hotspot.dns.rsc" "[$MODE] hotspot.dns"
    check "[$MODE] router resolves the portal name via the CNAME to $PORTAL_IP" \
        test "$(ros ':put [:resolve portal.example.com]')" = "$PORTAL_IP"
    run_block "$R/hotspot.hotspot.rsc" "[$MODE] hotspot.hotspot"
    run_block "$R/network.firewall.rsc" "[$MODE] network.firewall (re-run, hotspot dynamic rules present)"
    check_firewall_order "$R" "[$MODE] firewall (with hotspot)"

    log "[$MODE] router script (RADIUS, hotspot, walled garden, certificate script)"
    cp "$WORK/pki/leaf1/router.crt" "$WORK/pki/leaf1/router.key" "$WORK/pki/current/"
    run_block "$R/router.rsc" "[$MODE] router.rsc"
    wait_cert_script
    ros "/log print where message~\"$N\"" | tail -5 | sed 's/^/    log| /'
    check_cert_state "[$MODE] after first import" "$WORK/pki/leaf1"
    ids1=$(certs)

    log "[$MODE] certificate script again: must be a no-op"
    ros "/system script run $N-cert" | sed 's/^/    | /'
    wait_cert_script
    check "[$MODE] second run changed nothing" test "$(certs)" = "$ids1"
    run_block "$R/router.rsc" "[$MODE] router.rsc (re-run)"
    wait_cert_script
    check "[$MODE] router script re-run keeps the certificates" test "$(certs)" = "$ids1"
    profile_ok "[$MODE] profile after router script re-run"

    log "[$MODE] rotation to a new leaf (EC key)"
    cp "$WORK/pki/leaf2/router.crt" "$WORK/pki/leaf2/router.key" "$WORK/pki/current/"
    ros "/system script run $N-cert" | sed 's/^/    | /'
    wait_cert_script
    ros "/log print where message~\"$N\"" | tail -3 | sed 's/^/    log| /'
    check_cert_state "[$MODE] after rotation" "$WORK/pki/leaf2"
    check "[$MODE] no leftover .crt/.key files" test "$(ros ":put [:len [/file find where name=\"$N.crt\" || name=\"$N.key\"]]")" = 0

    log "[$MODE] login.html fetch"
    run_block "$R/login_fetch.rsc" "[$MODE] login_fetch"
    sleep 2
    html=$(ros ":foreach f in=[/file find where name~\"login.html\\\$\"] do={ :put ([/file get \$f name] . \" \" . [/file get \$f size]) }")
    echo "$html" | sed 's/^/    | /'
    check "[$MODE] foreign hotspot-other/login.html untouched" \
        test "$(ros ':put [/file get [find name="hotspot-other/login.html"] contents]')" = "foreign login page"
    hdir=$(ros ":put [/ip hotspot profile get [find where name=\"$N\"] html-directory]")
    check "[$MODE] login.html in the profile's html-directory ($hdir) is the portal page" \
        grep -q "portal.example.com" <<<"$(ros ":put [/file get [find name=\"$hdir/login.html\"] contents]")"

    log "[$MODE] diagnostics blocks (read-only)"
    # (output shortened: the prints are in $WORK/out-diagnostics.*)
    run_block "$R/diagnostics.verify.rsc" "[$MODE] diagnostics.verify" > "$WORK/diag.log"
    run_block "$R/diagnostics.diagnostics.rsc" "[$MODE] diagnostics.diagnostics" >> "$WORK/diag.log"
    grep -E '^(ok|FAIL)' "$WORK/diag.log"
    check "[$MODE] diagnostics print the foreign walled-garden ip drop entry" \
        grep -q "LAN block covering the portal IP" "$WORK/out-diagnostics.verify.rsc.txt"
    check "[$MODE] diagnostics print the default profile (html-directory)" \
        grep -q 'name="default".*html-directory=' <(tr -d '\n' < "$WORK/out-diagnostics.verify.rsc.txt")

    log "[$MODE] hotspot block again after the login.html upload"
    run_block "$R/hotspot.hotspot.rsc" "[$MODE] hotspot.hotspot (re-run)"
    wait_cert_script
    check "[$MODE] uploaded login.html survives re-creating the hotspot" \
        grep -q "portal.example.com" <<<"$(ros ":put [/file get [find name=\"$hdir/login.html\"] contents]")"
    check "[$MODE] re-created profile has no certificate yet only if missing (cert exists: https set)" \
        test "$(profile | cut -d'|' -f1)" = "$N"

    if [ $first = 1 ]; then
        log "[$MODE] container network (device mode not enabled on the CHR: container_add not run)"
        run_block "$R/container.container_network.rsc" "[$MODE] container.container_network"
        run_block "$R/uninstall.uninstall_container.rsc" "[$MODE] uninstall.uninstall_container"
    fi

    check_keys_known "$R" "[$MODE] before uninstall"
    log "[$MODE] uninstall"
    # Manual step described in the block: take cfg-guest out of the slave list first
    ros '/interface wifi provisioning set [find where comment="foreign"] slave-configurations=cfg-iot; /caps-man provisioning set [find where comment="foreign"] slave-configurations=cfg-iot' >/dev/null
    run_block "$R/uninstall.uninstall.rsc" "[$MODE] uninstall.uninstall"
    run_block "$R/uninstall.uninstall_network.rsc" "[$MODE] uninstall.uninstall_network"
    ros '/file remove [find where name="harness.rsc"]' >/dev/null
    snapshot "$WORK/after-$MODE.txt"
    # Left on purpose (documented in the uninstall block as manual / not ours to remove):
    #   global settings /radius incoming, /interface wifi capsman enabled, /caps-man manager enabled;
    #   created by RouterOS itself: the CAPsMAN certificates of "/interface wifi capsman set enabled=yes",
    #   the disabled "place hotspot rules here" placeholders and the default files of the hotspot
    #   html-directory (shared with other hotspots, login.html is not removed)
    allowed='/radius incoming set|/interface wifi capsman set|/caps-man manager set|WiFi-CAPsMAN-|.*comment="place hotspot rules here"|(flash/)?hotspot(/|\\|)|flash\\|'
    d=$(diff "$WORK/before.txt" "$WORK/after-$MODE.txt" | grep '^[<>]' | grep -vE "^[<>] ($allowed)")
    if [ -z "$d" ]; then
        pass "[$MODE] after uninstall the configuration equals the snapshot before (except: radius incoming, capsman enabled)"
    else
        fail "[$MODE] configuration differs after uninstall"
        printf '%s\n' "$d" | sed 's/^/    | /' | head -60
    fi
    diff "$WORK/before.txt" "$WORK/after-$MODE.txt" | grep -E "^[<>] ($allowed)" | sed 's/^/    allowed| /'
    first=0
done

log "Stub requests"
sed 's/^/    | /' "$WORK/stub.log" | tail -30
log "Result: $FAILS failure(s); details in $WORK"
[ $FAILS = 0 ]
