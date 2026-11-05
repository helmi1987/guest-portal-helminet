#!/usr/bin/env bash
# Runs the generated RouterOS scripts of the setup assistant against a RouterOS CHR and checks that they
# create/replace/remove only their own objects. See tests/routeros/README.md.
#
#   tests/routeros/run.sh [wifi-mode ...]     (default: capsman2 capsman1 local multi)
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
[ $# -eq 0 ] && MODES=(capsman2 capsman1 local multi)
TOKEN=testcerttoken0123456789abcdefABCD
# Password of the read-only API user (fake test value, replaces <API_PASSWORD>)
API_PASS=Test-api-pass-1
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
    sed -e "s/<COUNTRY>/${COUNTRY:-Germany}/g" \
        -e 's/<TRUNK_PORT_TO_CAP>/veth-trunk/g' -e "s/<API_PASSWORD>/$API_PASS/g" "$f" > "$prep"
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
        # Export without the lines that change by themselves (ros_export in lib.sh)
        # /ip service lines sorted at the end: RouterOS reorders www-ssl in the export after it was enabled once
        local exp
        exp=$(ros_export)
        printf '%s\n' "$exp" | grep -v '^/ip service set '
        printf '%s\n' "$exp" | grep '^/ip service set ' | sort
        echo '#--- certificates'
        ros ':foreach c in=[/certificate find] do={ :put ([/certificate get $c name] . "|" . [/certificate get $c common-name] . "|" . [/certificate get $c fingerprint] . "|key=" . [/certificate get $c private-key] . "|trusted=" . [/certificate get $c trusted]) }' | sort
        echo '#--- files'
        ros ':foreach f in=[/file find] do={ :put ([/file get $f name] . "|" . [/file get $f type] . "|" . [/file get $f size]) }' | grep -v '^harness.rsc|' | sort
        echo '#--- file contents (foreign)'
        ros ':put [/file get [find name="hotspot-other/login.html"] contents]'
    } > "$1"
}

# Hotspot pages of the portal (decision 2026-11-05, v0.29.0) and the redirect target each must contain, with the
# RouterOS variables unexpanded in the file (they are expanded only when the hotspot serves the page).
PAGES="login status logout alogin"
expected_target() { # <page>; site slug of the current $MODE
    local q='&amp;mac=$(mac-esc)&amp;ip=$(ip)&amp;link-login-only=$(link-login-only-esc)'
    case $1 in
        login) printf '%s' "/portal?site=site-$MODE$q"'&amp;link-orig=$(link-orig-esc)' ;;
        status) printf '%s' "/portal/status?site=site-$MODE$q" ;;
        logout) printf '%s' "/portal?site=site-$MODE$q\"" ;;
        alogin) printf '%s' 'url=$(link-redirect)' ;;
    esac
}

certs() { ros ':foreach c in=[/certificate find] do={ :put ([/certificate get $c name] . "|" . $c . "|" . [/certificate get $c fingerprint] . "|key=" . [/certificate get $c private-key]) }' | sort; }
profile() { ros ":put ([/ip hotspot profile get [find where name=\"$N\"] ssl-certificate] . \"|\" . [:tostr [/ip hotspot profile get [find where name=\"$N\"] login-by]])" | tr ';' ','; }
profile_ok() { # <label>
    local p; p=$(profile)
    if [ "$p" = "$N|mac,https" ] || [ "$p" = "$N|https,mac" ]; then pass "$1 ($p)"; else fail "$1 (got: $p)"; fi
}
norm_fp() { tr -d ':\n ' | tr 'A-F' 'a-f'; }

# Slave configurations of the seed's provisioning rules (master configuration -> list), CAPsMAN modes.
slaves() { # <master-configuration>
    local p="/interface wifi provisioning"
    [ "$MODE" = capsman1 ] && p="/caps-man provisioning"
    ros ":put [:tostr [$p get [find where master-configuration=\"$1\"] slave-configurations]]" | tr ';' ','
}

# Selected masters: cfg-guest appended once to cfg-main (cfg-iot kept) and cfg-office, cfg-other unchanged;
# mode local: both masters reported as missing (no radio on the CHR), no virtual AP created.
check_wifi_masters() { # <label> <import output file>
    if [ "$MODE" = local ]; then
        check "$1: master wifi1 missing -> skipped with a message" grep -q 'master interface wifi1 not found, wifi-guest-wifi1 skipped' "$2"
        check "$1: master wifi2 missing -> skipped with a message" grep -q 'master interface wifi2 not found, wifi-guest-wifi2 skipped' "$2"
        check "$1: no virtual AP created" test "$(ros ':put [:len [/interface wifi find where name~"^wifi-guest"]]')" = 0
        return
    fi
    local got
    got=$(slaves cfg-main); check "$1: cfg-main slaves = cfg-iot,cfg-guest (existing slave kept, got: $got)" test "$got" = "cfg-iot,cfg-guest"
    got=$(slaves cfg-office); check "$1: cfg-office slaves = cfg-guest (got: $got)" test "$got" = "cfg-guest"
    got=$(slaves cfg-other); check "$1: cfg-other (not selected) unchanged (got: $got)" test "$got" = "cfg-iot"
}

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

# Dynamic entries RouterOS adds for a hotspot (decision 2026-11-02): never removed or changed by the scripts.
HS_NAME=hotspot.guest.example.com
# (walled garden: RouterOS adds a dynamic copy of every walled-garden ip entry with its comment, also of the own
# one; those copies come and go with the own entry, so only the others are counted)
dyn() { ros ':put ("walled-garden=" . [:len [/ip hotspot walled-garden find where dynamic && !(comment~"^guest-portal:")]] . " walled-garden-ip=" . [:len [/ip hotspot walled-garden ip find where dynamic]] . " dns-static=" . [:len [/ip dns static find where dynamic]])'; }
# The router script re-creates the hotspot: its dynamic entries come back within seconds
dyn_is() { local i; for i in $(seq 1 15); do [ "$(dyn)" = "$1" ] && return 0; sleep 2; done; echo "    got: $(dyn)"; return 1; }
dyn_print() { ros '/ip hotspot walled-garden print terse where dynamic; /ip hotspot walled-garden ip print terse where dynamic; /ip dns static print terse where dynamic'; }
# Own (key or legacy comment) static entries in the menus with dynamic hotspot entries
own_static() { ros ":put ([:len [/ip hotspot walled-garden find where !dynamic && (comment~\"^$N:\" || comment=\"$N\" || comment~\"^$N: \")]] + [:len [/ip hotspot walled-garden ip find where !dynamic && (comment~\"^$N:\" || comment=\"$N\" || comment~\"^$N: \")]] + [:len [/ip dns static find where !dynamic && (comment~\"^$N:\" || comment=\"$N\" || comment~\"^$N: \")]])"; }

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

# get returns true/false, set accepts only yes/no ("disabled=true" is a syntax error)
yesno() { if [ "$1" = true ]; then echo yes; else echo no; fi; }

query() { # <type> <port> <cert> <password> -> state file (router state query of the fixture, mode $MODE)
    mkdir -p "$WORK/state/$MODE"
    (cd "$REPO" && RQ_TYPE=$1 RQ_HOST=127.0.0.1 RQ_PORT=$2 RQ_CERT=$3 RQ_PASS=$4 \
        php tests/routeros/render.php "$WORK/state/$MODE" "$ROUTER_IP" "$PORTAL_IP" "$PORTAL_PORT" "$MODE") >/dev/null
    echo "$WORK/state/$MODE/state-$1.txt"
}

# Objects of versions 0.6.0 to 0.12.6 without comment (unmanaged.rsc): reported as "unmanaged" by the state
# query, the Wi-Fi block fails on them ("already exists"), the adopt commands of the state set the key, then
# nothing is unmanaged any more (the blocks of the mode then run on the adopted objects). The REST query
# needs www-ssl with a certificate whose key is pinned: the test leaf is imported as "harness-api" for this
# phase only (removed again, www-ssl restored).
check_unmanaged() { # <rsc dir>
    local R=$1 wifi="/interface wifi" dp="client-isolation=yes" f out
    [ "$MODE" = capsman1 ] && wifi="/caps-man" && dp="vlan-mode=use-tag local-forwarding=no client-to-client-forwarding=no"
    sed -e "s|__WIFI__|$wifi|g" -e "s|__DATAPATH__|$dp|g" -e "s|__COUNTRY__|$COUNTRY|g" "$HERE/unmanaged.rsc" > "$WORK/unmanaged-$MODE.rsc"
    run_block "$WORK/unmanaged-$MODE.rsc" "[$MODE] objects without comment (0.6.0 to 0.12.6)"
    # Leaf only (router.crt holds the chain: three certificates of the same name, www-ssl might pick a CA)
    awk '/BEGIN CERTIFICATE/{n++} n==1' "$WORK/pki/leaf2/router.crt" > "$WORK/harness-api.crt"
    ros_upload "$WORK/harness-api.crt" harness-api.crt && ros_upload "$WORK/pki/leaf2/router.key" harness-api.key
    ros '/certificate import file-name=harness-api.crt passphrase="" name=harness-api; /certificate import file-name=harness-api.key passphrase="" name=harness-api; /file remove [find where name="harness-api.crt" || name="harness-api.key"]' >/dev/null
    run_block "$R/api_access.rsc" "[$MODE] api_access (for the unmanaged check)"
    local svc
    svc=$(ros ':put ([/ip service get www-ssl certificate] . "|" . [/ip service get www-ssl disabled])')
    ros '/ip service set www-ssl certificate=harness-api disabled=no' >/dev/null
    local i
    for i in 1 2 3 4 5; do
        sleep 3
        f=$(query rest "$ROS_REST_PORT" "$WORK/pki/leaf2/router.crt" "$API_PASS")
        grep -qE '^error (tls|connect)' "$f" || break
    done
    if grep -q '^error' "$f"; then
        fail "[$MODE] unmanaged: state query: $(grep '^error' "$f")"
    else
        local k
        for k in wifi:dp-guest wifi:cfg-guest net:vlan net:dhcp-server; do
            check "[$MODE] unmanaged: guest-portal:$k without comment reported as unmanaged" grep -qx "guest-portal:$k unmanaged" "$f"
        done
        check "[$MODE] unmanaged: sec-guest with the legacy comment stays legacy" grep -qx 'guest-portal:wifi:sec-guest legacy.*' "$f"
        cp "$f" "$WORK/state/$MODE/state-rest-unmanaged.txt"; cp "$WORK/state/$MODE/adopt-rest.rsc" "$WORK/state/$MODE/adopt-unmanaged.rsc"
        check "[$MODE] unmanaged: exactly these 4 objects are unmanaged" \
            test "$(grep -c ' unmanaged$' "$f")" = 4
        local adopt=$WORK/state/$MODE/adopt-unmanaged.rsc
        sed 's/^/    adopt| /' "$adopt"
        check "[$MODE] unmanaged: one adopt command per object, only for an empty comment" \
            test "$(grep -c '&& (\?!comment.*\] comment="guest-portal:' "$adopt")" = 4
        # Before adopting: the Wi-Fi block fails on the name (nothing of it changed), as reported
        cp "$R/network.wifi.rsc" "$WORK/wifi-before-adopt.rsc"
        local prep=$WORK/run-wifi-before-adopt.rsc
        sed -e "s/<COUNTRY>/$COUNTRY/g" "$WORK/wifi-before-adopt.rsc" > "$prep"
        ros_upload "$prep" harness.rsc
        out=$(ros '/import file-name=harness.rsc')
        check "[$MODE] unmanaged: Wi-Fi block fails before adopting (already exists)" grep -qiE 'already (have|exists)|must use unique name' <<<"$out"
        printf '%s\n' "$out" | grep -iE 'already|failure' | head -3 | sed 's/^/    | /'
        # The adopt condition never matches an object with a comment (names are unique, so checked on a test pool)
        ros '/ip pool add name=gp-adopt-test ranges=10.99.0.1-10.99.0.2 comment="foreign"; /ip pool set [find where name="gp-adopt-test" && !comment] comment="guest-portal:net:pool"' >/dev/null
        check "[$MODE] unmanaged: adopt condition leaves a foreign comment alone" \
            test "$(ros ':put [/ip pool get [find where name="gp-adopt-test"] comment]')" = foreign
        ros '/ip pool remove [find where name="gp-adopt-test"]' >/dev/null
        run_block "$adopt" "[$MODE] adopt commands"
        run_block "$adopt" "[$MODE] adopt commands (re-run: no-op)"
        f=$(query rest "$ROS_REST_PORT" "$WORK/pki/leaf2/router.crt" "$API_PASS")
        check "[$MODE] unmanaged: after adopting nothing is unmanaged" test "$(grep -c ' unmanaged$' "$f")" = 0
        check "[$MODE] unmanaged: dp-guest now has its key" \
            test "$(ros ":put [:len [$wifi datapath find where name=dp-guest && comment=\"$N:wifi:dp-guest\"]]")" = 1
    fi
    ros "/ip service set www-ssl certificate=$(cut -d'|' -f1 <<<"$svc") disabled=$(yesno "$(cut -d'|' -f2 <<<"$svc")")" >/dev/null
    ros '/certificate remove [find where name~"^harness-api"]' >/dev/null
    ros '/file remove [find where name="harness.rsc"]' >/dev/null
}

# ---------------------------------------------------------------------------------------------
# Fixture "multi" (decision 2026-11-02): all three Wi-Fi modes together, guest SSID per CAP for the wifi
# package (radios absent on the CHR: skip path; the add/update path is run with the radio lookup replaced
# by a fixed configuration, "simulated radio"), the provisioning editor (guards, add-if-missing) and the
# uninstall of both packages.
prules() { # <provisioning menu> <rule comment> <property> -> value (lists comma separated)
    ros ":put [:tostr [$1 get [find where comment=\"$2\"] $3]]" | tr ';' ','
}
pindex() { # <provisioning menu> <rule comment> -> position of the rule
    ros ":put [:find [$1 find] [:pick [$1 find where comment=\"$2\"] 0]]"
}
multi_prep() { # <in> <out>: <COUNTRY> per package (legacy CAPsMAN takes lower case)
    sed -e '/\/caps-man/s/<COUNTRY>/germany/g' "$1" > "$2"
}

run_multi() {
    local R=$WORK/rsc/multi P1="/caps-man provisioning" P2="/interface wifi provisioning" got out f
    local K1=$N:wifi:cap-aabbcc000001 K2=$N:wifi:cap-aabbcc000002
    COUNTRY=Germany
    sync_clock
    log "[multi] all Wi-Fi modes together"
    run_block "$R/network.vlan.rsc" "[multi] network.vlan"
    multi_prep "$R/network.wifi.rsc" "$WORK/multi-wifi.rsc"
    run_block "$WORK/multi-wifi.rsc" "[multi] network.wifi"
    out=$WORK/out-multi-wifi.rsc.txt
    for p in "/caps-man" "/interface wifi"; do
        check "[multi] $p: dp-guest, sec-guest, cfg-guest with their keys" \
            test "$(( $(ros ":put [:len [$p datapath find where comment=\"$N:wifi:dp-guest\"]]") + $(ros ":put [:len [$p security find where comment=\"$N:wifi:sec-guest\"]]") + $(ros ":put [:len [$p configuration find where comment=\"$N:wifi:cfg-guest\"]]") ))" = 3
    done
    got=$(prules "$P1" foreign slave-configurations); check "[multi] capsman1 master cfg-main: cfg-iot,cfg-guest (got $got)" test "$got" = "cfg-iot,cfg-guest"
    got=$(prules "$P1" "foreign office" slave-configurations); check "[multi] capsman1 master cfg-office: cfg-guest (got $got)" test "$got" = "cfg-guest"
    got=$(prules "$P2" foreign slave-configurations); check "[multi] capsman2 with CAP selection: masters not used, cfg-main rule unchanged (got $got)" test "$got" = "cfg-iot"
    check "[multi] radio AA:BB:CC:00:00:01 absent: skipped" grep -q 'radio AA:BB:CC:00:00:01: not connected' "$out"
    check "[multi] radio AA:BB:CC:00:00:02 absent: skipped" grep -q 'radio AA:BB:CC:00:00:02: not connected' "$out"
    check "[multi] local master wifi1 absent: skipped" grep -q 'master interface wifi1 not found' "$out"
    check "[multi] no own CAP rule without a radio" test "$(ros ":put [:len [$P2 find where comment~\"^$N:wifi:cap-\"]]")" = 0
    snapshot "$WORK/multi1.txt"
    run_block "$WORK/multi-wifi.rsc" "[multi] network.wifi (re-run)"
    snapshot "$WORK/multi2.txt"
    check "[multi] wifi re-run idempotent" diff -q "$WORK/multi1.txt" "$WORK/multi2.txt"

    log "[multi] guest SSID per CAP, simulated radio (lookup replaced by configuration cfg-main)"
    # The admin's rule carries cfg-guest and a band matcher: copied to the own rules, cfg-guest taken out of it
    ros "$P2 set [find where comment=\"foreign\"] slave-configurations=cfg-iot,cfg-guest supported-bands=5ghz-ax,5ghz-ac" >/dev/null
    sed -e 's/:local gpCfg ""/:local gpCfg "cfg-main"/' "$WORK/multi-wifi.rsc" > "$WORK/multi-sim.rsc"
    run_block "$WORK/multi-sim.rsc" "[multi] network.wifi (simulated radios)"
    for k in "$K1" "$K2"; do
        check "[multi] own rule $k exists once" test "$(ros ":put [:len [$P2 find where comment=\"$k\"]]")" = 1
        got=$(prules "$P2" "$k" master-configuration); check "[multi] $k master cfg-main (got $got)" test "$got" = cfg-main
        got=$(prules "$P2" "$k" slave-configurations); check "[multi] $k slaves cfg-iot,cfg-guest (got $got)" test "$got" = "cfg-iot,cfg-guest"
        got=$(prules "$P2" "$k" supported-bands); check "[multi] $k band matcher copied (got $got)" grep -q '5ghz-ax' <<<"$got"
        got=$(prules "$P2" "$k" action); check "[multi] $k action copied (got $got)" test "$got" = create-dynamic-enabled
        check "[multi] $k placed before the admin's rule" test "$(pindex "$P2" "$k")" -lt "$(pindex "$P2" foreign)"
    done
    got=$(prules "$P2" "$K1" radio-mac); check "[multi] $K1 radio-mac (got $got)" test "$got" = AA:BB:CC:00:00:01
    got=$(prules "$P2" foreign slave-configurations); check "[multi] admin's rule: cfg-guest taken out (got $got)" test "$got" = cfg-iot
    snapshot "$WORK/multi3.txt"
    run_block "$WORK/multi-sim.rsc" "[multi] network.wifi (simulated radios, re-run)"
    snapshot "$WORK/multi4.txt"
    check "[multi] simulated radios: re-run idempotent (rules not moved again)" diff -q "$WORK/multi3.txt" "$WORK/multi4.txt"
    run_block "$WORK/multi-wifi.rsc" "[multi] network.wifi (radios absent again)"
    snapshot "$WORK/multi5.txt"
    check "[multi] radios absent: own rules kept as they are" diff -q "$WORK/multi3.txt" "$WORK/multi5.txt"
    # Deselected radios: the block of the single capsman2 fixture (no CAP selection) removes the own rules
    multi_prep "$WORK/rsc/capsman2/network.wifi.rsc" "$WORK/multi-c2.rsc"
    run_block "$WORK/multi-c2.rsc" "[multi] network.wifi of a site without CAP selection"
    check "[multi] without CAP selection the own rules are removed" test "$(ros ":put [:len [$P2 find where comment~\"^$N:wifi:cap-\"]]")" = 0
    run_block "$WORK/multi-sim.rsc" "[multi] network.wifi (simulated radios again)"
    check "[multi] own rules added again" test "$(ros ":put [:len [$P2 find where comment~\"^$N:wifi:cap-\"]]")" = 2
    check_keys_known "$R" "[multi] before uninstall"

    log "[multi] provisioning editor (state query, guarded script)"
    awk '/BEGIN CERTIFICATE/{n++} n==1' "$WORK/pki/leaf2/router.crt" > "$WORK/harness-api.crt"
    ros_upload "$WORK/harness-api.crt" harness-api.crt && ros_upload "$WORK/pki/leaf2/router.key" harness-api.key
    ros '/certificate import file-name=harness-api.crt passphrase="" name=harness-api; /certificate import file-name=harness-api.key passphrase="" name=harness-api; /file remove [find where name="harness-api.crt" || name="harness-api.key"]' >/dev/null
    run_block "$R/api_access.rsc" "[multi] api_access"
    local svc
    svc=$(ros ':put ([/ip service get www-ssl certificate] . "|" . [/ip service get www-ssl disabled])')
    ros '/ip service set www-ssl certificate=harness-api disabled=no' >/dev/null
    sleep 3
    local e2='{"mode":"capsman2","rules":{"cfg-office":{"master":"cfg-office","slaves":["cfg-iot"]}},"new":[{"band":"5ghz","master":"cfg-other","slaves":["cfg-guest"]}]}'
    local e1='{"mode":"capsman1","rules":{"cfg-office":{"master":"cfg-office","slaves":["cfg-iot","cfg-guest"]}},"new":[{"band":"2ghz","master":"cfg-other","slaves":[]}]}'
    local mode edit menu
    for mode in capsman2 capsman1; do
        if [ $mode = capsman2 ]; then edit=$e2; menu=$P2; else edit=$e1; menu=$P1; fi
        mkdir -p "$WORK/state/multi"
        (cd "$REPO" && RQ_EDIT=$edit RQ_TYPE=rest RQ_HOST=127.0.0.1 RQ_PORT=$ROS_REST_PORT RQ_CERT=$WORK/pki/leaf2/router.crt RQ_PASS=$API_PASS \
            php tests/routeros/render.php "$WORK/state/multi" "$ROUTER_IP" "$PORTAL_IP" "$PORTAL_PORT" multi) >/dev/null
        f=$WORK/state/multi/provisioning-rest.rsc
        cp "$f" "$WORK/provisioning-$mode.rsc"
        if grep -q '^error' "$f" || grep -q '^error' "$WORK/state/multi/state-rest.txt"; then
            fail "[multi] editor $mode: $(head -c 300 "$f") $(grep '^error' "$WORK/state/multi/state-rest.txt")"; continue
        fi
        sed 's/^/    editor| /' "$f" | grep -v '^    editor| #' | head -5
        check "[multi] editor $mode: never provisions" bash -c "! grep -v '^#' '$f' | grep -qw provision"
        run_block "$WORK/provisioning-$mode.rsc" "[multi] editor $mode script"
        got=$(prules "$menu" "foreign office" slave-configurations)
        if [ $mode = capsman2 ]; then
            check "[multi] editor $mode: changed rule set (got $got)" test "$got" = cfg-iot
            got=$(ros ":put [:len [$menu find where master-configuration=cfg-other && !comment]]")
            check "[multi] editor $mode: new rule added without comment" test "$got" = 1
            got=$(ros ":put [:tostr [$menu get [find where master-configuration=cfg-other && !comment] supported-bands]]" | tr ';' ',')
            check "[multi] editor $mode: band matcher of the new rule (got $got)" grep -q '5ghz-ac' <<<"$got"
        else
            check "[multi] editor $mode: changed rule set (got $got)" test "$got" = "cfg-iot,cfg-guest"
            got=$(ros ":put [:tostr [$menu get [find where master-configuration=cfg-other && !comment] hw-supported-modes]]" | tr ';' ',')
            check "[multi] editor $mode: new rule with band matcher (got $got)" grep -q 'gn' <<<"$got"
        fi
        snapshot "$WORK/multi-ed1-$mode.txt"
        run_block "$WORK/provisioning-$mode.rsc" "[multi] editor $mode script (re-run)"
        check "[multi] editor $mode re-run: changed rule skipped by the guard" grep -q 'was changed on the router after the state query, skipped' "$WORK/out-provisioning-$mode.rsc.txt"
        check "[multi] editor $mode re-run: new rule not added twice" grep -q 'exists, not added' "$WORK/out-provisioning-$mode.rsc.txt"
        snapshot "$WORK/multi-ed2-$mode.txt"
        check "[multi] editor $mode re-run changes nothing" diff -q "$WORK/multi-ed1-$mode.txt" "$WORK/multi-ed2-$mode.txt"
        # Reset by hand (the editor's rules belong to the admin)
        ros "$menu remove [find where master-configuration=cfg-other && !comment]" >/dev/null
    done
    ros "$P2 unset [find where comment=\"foreign office\"] slave-configurations; $P1 set [find where comment=\"foreign office\"] slave-configurations=cfg-guest" >/dev/null
    ros "/ip service set www-ssl certificate=$(cut -d'|' -f1 <<<"$svc") disabled=$(yesno "$(cut -d'|' -f2 <<<"$svc")")" >/dev/null
    ros '/certificate remove [find where name~"^harness-api"]' >/dev/null

    log "[multi] uninstall"
    # A foreign rule whose comment only starts like an own key stays
    ros "$P2 add action=create-dynamic-enabled master-configuration=cfg-other comment=\"$N:wifi:cap-x\"" >/dev/null
    run_block "$R/uninstall.uninstall.rsc" "[multi] uninstall.uninstall"
    run_block "$R/uninstall.uninstall_network.rsc" "[multi] uninstall.uninstall_network"
    check "[multi] uninstall: own CAP rules removed" test "$(ros ":put [:len [$P2 find where comment~\"^$N:wifi:cap-[0-9a-f]\"]]")" = 0
    check "[multi] uninstall: foreign look-alike rule kept" test "$(ros ":put [:len [$P2 find where comment=\"$N:wifi:cap-x\"]]")" = 1
    got=$(prules "$P1" foreign slave-configurations); check "[multi] uninstall: capsman1 cfg-main slaves cfg-iot (got $got)" test "$got" = cfg-iot
    got=$(prules "$P1" "foreign office" slave-configurations); check "[multi] uninstall: capsman1 cfg-office slaves empty (got '$got')" test -z "$got"
    for p in "/caps-man" "/interface wifi"; do
        check "[multi] uninstall: $p cfg-guest removed" test "$(ros ":put [:len [$p configuration find where name=cfg-guest]]")" = 0
    done
    ros "$P2 remove [find where comment=\"$N:wifi:cap-x\"]; $P2 unset [find where comment=\"foreign\"] supported-bands" >/dev/null
    ros '/file remove [find where name="harness.rsc"]' >/dev/null
    snapshot "$WORK/after-multi.txt"
    run_block "$R/uninstall.uninstall.rsc" "[multi] uninstall.uninstall (re-run)"
    run_block "$R/uninstall.uninstall_network.rsc" "[multi] uninstall.uninstall_network (re-run)"
    ros '/file remove [find where name="harness.rsc"]' >/dev/null
    snapshot "$WORK/after2-multi.txt"
    check "[multi] uninstall re-run idempotent" diff -q "$WORK/after-multi.txt" "$WORK/after2-multi.txt"
    local allowed='/radius incoming set|/interface wifi capsman set|/caps-man manager set|WiFi-CAPsMAN-|.*comment="place hotspot rules here"|(flash/)?hotspot(/|\|)|flash\|'
    local d
    d=$(diff "$WORK/before.txt" "$WORK/after-multi.txt" | grep '^[<>]' | grep -vE "^[<>] ($allowed)")
    if [ -z "$d" ]; then pass "[multi] after uninstall the configuration equals the snapshot before"
    else fail "[multi] configuration differs after uninstall"; printf '%s\n' "$d" | sed 's/^/    | /' | head -40; fi
}

# ---------------------------------------------------------------------------------------------
log "Preparing (work dir $WORK)"
ROUTER_IP=$(ros ':put [/ip address get [:pick [/ip address find where interface=ether1] 0] address]' | cut -d/ -f1)
[ -n "$ROUTER_IP" ] || { echo "cannot read the CHR address of ether1"; exit 1; }
echo "router IP $ROUTER_IP, portal stub https://portal.example.com:$PORTAL_PORT -> $PORTAL_IP"
ros '/system resource print' | grep -E 'version|board-name|uptime'
bash ./pki.sh "$WORK/pki" >/dev/null || { echo "PKI generation failed"; exit 1; }
(cd "$REPO" && php tests/routeros/render.php "$WORK/rsc" "$ROUTER_IP" "$PORTAL_IP" "$PORTAL_PORT") || { echo "render failed"; exit 1; }
for m in "${MODES[@]}"; do mkdir -p "$WORK/login/$(cat "$WORK/rsc/$m/nas_id")"; for p in $PAGES; do cp "$WORK/rsc/$m/$p.html" "$WORK/login/$(cat "$WORK/rsc/$m/nas_id")/"; done; done

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
    if [ "$MODE" = multi ]; then run_multi; continue; fi
    R=$WORK/rsc/$MODE
    sync_clock
    log "[$MODE] legacy objects of scripts before the comment keys (must be migrated/replaced)"
    run_block "$HERE/legacy.rsc" "[$MODE] legacy objects"
    if [ "$MODE" = capsman1 ]; then ros '/caps-man security add name=sec-guest comment="guest-portal"' >/dev/null
    else ros '/interface wifi security add name=sec-guest comment="guest-portal"' >/dev/null; fi
    COUNTRY=Germany
    [ "$MODE" = capsman1 ] && COUNTRY=germany
    log "[$MODE] objects without comment of versions 0.6.0 to 0.12.6 (unmanaged, adopt command)"
    check_unmanaged "$R"
    log "[$MODE] network step"
    run_block "$R/network.vlan.rsc" "[$MODE] network.vlan"
    run_block "$R/network.vlan_filtering.rsc" "[$MODE] network.vlan_filtering"
    run_block "$R/network.wifi.rsc" "[$MODE] network.wifi"
    check_wifi_security "$R" "[$MODE] wifi"
    check_wifi_masters "[$MODE] wifi masters" "$WORK/out-network.wifi.rsc.txt"
    snapshot "$WORK/wifi1-$MODE.txt"
    run_block "$R/network.wifi.rsc" "[$MODE] network.wifi (re-run)"
    check_wifi_security "$R" "[$MODE] wifi (re-run)"
    check_wifi_masters "[$MODE] wifi masters (re-run)" "$WORK/out-network.wifi.rsc.txt"
    snapshot "$WORK/wifi2-$MODE.txt"
    check "[$MODE] wifi re-run idempotent (configuration identical)" diff -q "$WORK/wifi1-$MODE.txt" "$WORK/wifi2-$MODE.txt"
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
    # Own static hotspot-name entry of versions before 0.19.1: removed by the DNS block, not added again
    ros "/ip dns static add name=$HS_NAME address=10.30.0.1 comment=\"$N:dns:hotspot-name\"" >/dev/null
    run_block "$R/hotspot.dns.rsc" "[$MODE] hotspot.dns"
    check "[$MODE] own hotspot-name entry of earlier versions removed, no static entry for $HS_NAME" \
        test "$(ros ":put [:len [/ip dns static find where name=\"$HS_NAME\" && !dynamic]]")" = 0
    check "[$MODE] router resolves the portal name via the CNAME to $PORTAL_IP" \
        test "$(ros ':put [:resolve portal.example.com]')" = "$PORTAL_IP"
    run_block "$R/hotspot.hotspot.rsc" "[$MODE] hotspot.hotspot"
    sleep 2
    log "[$MODE] dynamic entries of the hotspot (walled garden, walled garden ip, DNS)"
    DYN=$(dyn); echo "    $DYN"
    dyn_print | sed 's/^/    dyn| /'
    check "[$MODE] hotspot dns-name resolves on the router (dynamic entry of the hotspot)" \
        test "$(ros ":put [:resolve $HS_NAME]")" = 10.30.0.1
    run_block "$R/hotspot.dns.rsc" "[$MODE] hotspot.dns (re-run, dynamic hotspot entries present)"
    check "[$MODE] hotspot.dns re-run: dynamic entries unchanged ($DYN)" dyn_is "$DYN"
    check "[$MODE] hotspot.dns re-run: still no static entry for $HS_NAME" \
        test "$(ros ":put [:len [/ip dns static find where name=\"$HS_NAME\" && !dynamic]]")" = 0
    run_block "$R/network.firewall.rsc" "[$MODE] network.firewall (re-run, hotspot dynamic rules present)"
    check_firewall_order "$R" "[$MODE] firewall (with hotspot)"

    log "[$MODE] router script (RADIUS, hotspot, walled garden, certificate script)"
    cp "$WORK/pki/leaf1/router.crt" "$WORK/pki/leaf1/router.key" "$WORK/pki/current/"
    run_block "$R/router.rsc" "[$MODE] router.rsc"
    wait_cert_script
    DYN=$(dyn)
    check "[$MODE] router script: own walled-garden entries present next to the dynamic ones ($DYN)" \
        test "$(ros ":put ([:len [/ip hotspot walled-garden find where comment=\"$N:walled:portal-host\" && !dynamic]] . [:len [/ip hotspot walled-garden ip find where comment=\"$N:walled:portal-ip\" && !dynamic]])")" = 11
    ros "/log print where message~\"$N\"" | tail -5 | sed 's/^/    log| /'
    check_cert_state "[$MODE] after first import" "$WORK/pki/leaf1"
    ids1=$(certs)

    log "[$MODE] certificate script again: must be a no-op"
    ros "/system script run $N-cert" | sed 's/^/    | /'
    wait_cert_script
    check "[$MODE] second run changed nothing" test "$(certs)" = "$ids1"
    run_block "$R/router.rsc" "[$MODE] router.rsc (re-run)"
    wait_cert_script
    check "[$MODE] router script re-run: dynamic entries unchanged ($DYN)" dyn_is "$DYN"
    check "[$MODE] router script re-run keeps the certificates" test "$(certs)" = "$ids1"
    profile_ok "[$MODE] profile after router script re-run"

    log "[$MODE] rotation to a new leaf (EC key)"
    cp "$WORK/pki/leaf2/router.crt" "$WORK/pki/leaf2/router.key" "$WORK/pki/current/"
    ros "/system script run $N-cert" | sed 's/^/    | /'
    wait_cert_script
    ros "/log print where message~\"$N\"" | tail -3 | sed 's/^/    log| /'
    check_cert_state "[$MODE] after rotation" "$WORK/pki/leaf2"
    check "[$MODE] no leftover .crt/.key files" test "$(ros ":put [:len [/file find where name=\"$N.crt\" || name=\"$N.key\"]]")" = 0

    log "[$MODE] hotspot pages fetch ($PAGES)"
    run_block "$R/login_fetch.rsc" "[$MODE] login_fetch"
    sleep 2
    html=$(ros ":foreach f in=[/file find where name~\"(login|status|logout|alogin).html\\\$\"] do={ :put ([/file get \$f name] . \" \" . [/file get \$f size]) }")
    echo "$html" | sed 's/^/    | /'
    check "[$MODE] foreign hotspot-other/login.html untouched" \
        test "$(ros ':put [/file get [find name="hotspot-other/login.html"] contents]')" = "foreign login page"
    hdir=$(ros ":put [/ip hotspot profile get [find where name=\"$N\"] html-directory]")
    for p in $PAGES; do
        check "[$MODE] $p.html in the profile's html-directory ($hdir) is the portal page, variables intact" \
            grep -qF "$(expected_target "$p")" <<<"$(ros ":put [/file get [find name=\"$hdir/$p.html\"] contents]")"
    done

    log "[$MODE] diagnostics blocks (read-only)"
    # (output shortened: the prints are in $WORK/out-diagnostics.*)
    run_block "$R/diagnostics.verify.rsc" "[$MODE] diagnostics.verify" > "$WORK/diag.log"
    run_block "$R/diagnostics.diagnostics.rsc" "[$MODE] diagnostics.diagnostics" >> "$WORK/diag.log"
    grep -E '^(ok|FAIL)' "$WORK/diag.log"
    check "[$MODE] diagnostics print the foreign walled-garden ip drop entry" \
        grep -q "LAN block covering the portal IP" "$WORK/out-diagnostics.verify.rsc.txt"
    check "[$MODE] diagnostics print the default profile (html-directory)" \
        grep -q 'name="default".*html-directory=' <(tr -d '\n' < "$WORK/out-diagnostics.verify.rsc.txt")

    log "[$MODE] hotspot block again after the hotspot pages upload"
    run_block "$R/hotspot.hotspot.rsc" "[$MODE] hotspot.hotspot (re-run)"
    wait_cert_script
    for p in $PAGES; do
        check "[$MODE] uploaded $p.html survives re-creating the hotspot" \
            grep -qF "$(expected_target "$p")" <<<"$(ros ":put [/file get [find name=\"$hdir/$p.html\"] contents]")"
    done
    check "[$MODE] re-created profile has no certificate yet only if missing (cert exists: https set)" \
        test "$(profile | cut -d'|' -f1)" = "$N"

    if [ $first = 1 ]; then
        log "[$MODE] container network (device mode not enabled on the CHR: container_add not run)"
        run_block "$R/container.container_network.rsc" "[$MODE] container.container_network"
        run_block "$R/uninstall.uninstall_container.rsc" "[$MODE] uninstall.uninstall_container"
    fi

    log "[$MODE] read-only API access and router state query (REST via www-ssl, API via api-ssl)"
    run_block "$R/api_access.rsc" "[$MODE] api_access"
    run_block "$R/api_access.rsc" "[$MODE] api_access (re-run)"
    # The services are not own objects: set for the test, restored before the uninstall
    svc_before=$(ros ':foreach s in=[/ip service find where name="www-ssl" || name="api-ssl"] do={ :put ([/ip service get $s name] . "|" . [/ip service get $s certificate] . "|" . [/ip service get $s disabled]) }')
    ros "/ip service set www-ssl certificate=$N disabled=no; /ip service set api-ssl certificate=$N disabled=no" >/dev/null
    sleep 2
    for t in rest api-ssl; do
        port=$ROS_REST_PORT; [ "$t" = api-ssl ] && port=$ROS_APISSL_PORT
        f=$(query "$t" "$port" "$WORK/pki/leaf2/router.crt" "$API_PASS")
        if grep -q '^error' "$f"; then fail "[$MODE] $t: state query: $(grep '^error' "$f")"; continue; fi
        pass "[$MODE] $t: state query ($(grep '^version' "$f"))"
        # Every generated object present with the expected values (container: removed again above; the
        # virtual AP of mode local needs a radio)
        bad=$(grep -vE '^(version |guest-portal:container:)' "$f" | grep -vE ' (present|foreign)$' | grep -vxE 'guest-portal:wifi:wifi-guest-wifi[12] missing')
        if [ "$MODE" = local ] && [ -f "$WORK/rsc/capsman2/network.wifi.rsc" ]; then
            # The Wi-Fi block of the open site (capsman2 fixture, run above) appended cfg-guest to the wifi provisioning
            # rules: reported as left over by an earlier mode (mode switch, decision 2026-11-02), reset by hand below
            check "[$MODE] $t: cfg-guest in the wifi provisioning rules reported as left over" grep -qE '^leftover:/interface wifi provisioning:slave-\*[0-9A-F]+ leftover$' "$f"
            bad=$(printf '%s\n' "$bad" | grep -vxE 'leftover:/interface wifi provisioning:slave-\*[0-9A-F]+ leftover')
        fi
        if [ -z "$bad" ]; then pass "[$MODE] $t: all objects present, values as expected"
        else fail "[$MODE] $t: objects not present or different"; printf '%s\n' "$bad" | sed 's/^/    | /'; fi
        check "[$MODE] $t: foreign RFC1918 10.0.0.0/8 entry (seed) reported as foreign, not added" \
            grep -qx 'guest-portal:fw:addr-rfc1918-10 foreign' "$f"
    done
    f=$(query rest "$ROS_REST_PORT" "$WORK/pki/leaf2/router.crt" wrong-password)
    check "[$MODE] rest: wrong password -> auth error" grep -q '^error auth' "$f"
    f=$(query api-ssl "$ROS_APISSL_PORT" "$WORK/pki/leaf2/router.crt" wrong-password)
    check "[$MODE] api-ssl: wrong password -> auth error" grep -q '^error auth' "$f"
    f=$(query rest "$ROS_REST_PORT" "$WORK/pki/leaf1/router.crt" "$API_PASS")
    check "[$MODE] rest: other key pinned -> tls error" grep -q '^error tls' "$f"
    f=$(query api-ssl "$ROS_APISSL_PORT" "$WORK/pki/leaf1/router.crt" "$API_PASS")
    check "[$MODE] api-ssl: other key pinned -> tls error" grep -q '^error tls' "$f"
    # The group is read-only: a write through REST is refused
    pin=sha256//$(openssl x509 -in "$WORK/pki/leaf2/router.crt" -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64)
    code=$(curl -s -o /dev/null -w '%{http_code}' -k --pinnedpubkey "$pin" -u "portal-ro:$API_PASS" -X PUT \
        -H 'content-type: application/json' --data '{"name":"gp-write-test","ranges":"10.99.0.1-10.99.0.2"}' \
        "https://127.0.0.1:$ROS_REST_PORT/rest/ip/pool")
    check "[$MODE] rest: write with the read-only user refused (HTTP $code)" test "$code" != 200 -a "$code" != 201
    check "[$MODE] rest: nothing written" test -z "$(ros ':put [/ip pool find where name=gp-write-test]')"
    while IFS='|' read -r sname scert sdis; do
        [ -n "$sname" ] && ros "/ip service set $sname certificate=$scert disabled=$(yesno "$sdis")" >/dev/null
    done <<<"$svc_before"

    check_keys_known "$R" "[$MODE] before uninstall"
    log "[$MODE] uninstall"
    if [ "$MODE" != local ]; then
        # cfg-guest also in a rule that is not selected (added by hand): the uninstall takes it out of every rule
        pv="/interface wifi provisioning"; [ "$MODE" = capsman1 ] && pv="/caps-man provisioning"
        ros "$pv set [find where comment=\"foreign other\"] slave-configurations=cfg-iot,cfg-guest" >/dev/null
        got=$(slaves cfg-other); check "[$MODE] before uninstall: cfg-other slaves cfg-iot,cfg-guest (got: $got)" test "$got" = "cfg-iot,cfg-guest"
    fi
    if [ "$MODE" = local ] && [ -f "$WORK/rsc/capsman2/network.wifi.rsc" ]; then
        # The Wi-Fi block of the open site (capsman2 fixture, run above for the password change) appended cfg-guest
        # to the wifi provisioning rules; the uninstall of mode local does not touch provisioning: reset by hand
        ros '/interface wifi provisioning set [find where comment="foreign"] slave-configurations=cfg-iot; /interface wifi provisioning unset [find where comment="foreign office"] slave-configurations' >/dev/null
    fi
    DYN=$(dyn)
    # The walled-garden and DNS lines of the uninstall alone while the hotspot (and its dynamic entries)
    # still exists: own entries removed, dynamic ones untouched (the full uninstall removes the hotspot first)
    grep -E '^/ip (hotspot walled-garden|dns static) ' "$R/uninstall.uninstall.rsc" > "$WORK/uninstall-dyn.rsc"
    run_block "$WORK/uninstall-dyn.rsc" "[$MODE] uninstall walled-garden/DNS lines (dynamic hotspot entries present)"
    check "[$MODE] uninstall lines: dynamic entries unchanged ($DYN)" dyn_is "$DYN"
    check "[$MODE] uninstall lines: no own walled-garden / DNS entry left" test "$(own_static)" = 0
    run_block "$R/uninstall.uninstall.rsc" "[$MODE] uninstall.uninstall"
    echo "    dynamic entries before the uninstall: $DYN, after: $(dyn)"
    check "[$MODE] uninstall: no own walled-garden / DNS entry left" test "$(own_static)" = 0
    run_block "$R/uninstall.uninstall_network.rsc" "[$MODE] uninstall.uninstall_network"
    if [ "$MODE" != local ]; then
        got=$(slaves cfg-main); check "[$MODE] uninstall: cfg-main slaves cfg-iot (cfg-guest removed, other slave kept, got: $got)" test "$got" = "cfg-iot"
        got=$(slaves cfg-office); check "[$MODE] uninstall: cfg-office slaves empty (only cfg-guest before, got: '$got')" test -z "$got"
        got=$(slaves cfg-other); check "[$MODE] uninstall: cfg-other (not selected) slaves cfg-iot (got: $got)" test "$got" = "cfg-iot"
        check "[$MODE] uninstall: cfg-guest removed" test "$(ros ":put [:len [${pv% provisioning} configuration find where name=cfg-guest]]")" = 0
    fi
    ros '/file remove [find where name="harness.rsc"]' >/dev/null
    snapshot "$WORK/after-$MODE.txt"
    # Re-run of both uninstall blocks: no error, nothing changed
    run_block "$R/uninstall.uninstall.rsc" "[$MODE] uninstall.uninstall (re-run)"
    run_block "$R/uninstall.uninstall_network.rsc" "[$MODE] uninstall.uninstall_network (re-run)"
    ros '/file remove [find where name="harness.rsc"]' >/dev/null
    snapshot "$WORK/after2-$MODE.txt"
    check "[$MODE] uninstall re-run idempotent (configuration identical)" diff -q "$WORK/after-$MODE.txt" "$WORK/after2-$MODE.txt"
    # Left on purpose (documented in the uninstall block as manual / not ours to remove):
    #   global settings /radius incoming, /interface wifi capsman enabled, /caps-man manager enabled;
    #   created by RouterOS itself: the CAPsMAN certificates of "/interface wifi capsman set enabled=yes",
    #   the disabled "place hotspot rules here" placeholders and the default files of the hotspot
    #   html-directory (shared with other hotspots, the hotspot pages are not removed)
    allowed='/radius incoming set|/interface wifi capsman set|/caps-man manager set|WiFi-CAPsMAN-|.*comment="place hotspot rules here"|(flash/)?hotspot(/|\|)|flash\|'
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
