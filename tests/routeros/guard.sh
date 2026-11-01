#!/usr/bin/env bash
# Placeholder guard test: the Wi-Fi block (network.wifi) and the read-only API access block (api_access)
# are wrapped in "{ }" with a ":if (... = (... . ...)) do={ :error }" guard that aborts while the password
# placeholder is not replaced. Checked for both ways a user runs a script:
#   paste  = lines typed into an interactive terminal session (ssh -tt, one line at a time)
#   import = file upload + /import
# Also: the whole Wi-Fi block incl. the lines after the objects (provisioning, manager, virtual AP) is inside
# the group, so with the placeholder left none of them runs (capsman2 has no password guard: not tested).
# Cases per block and way: placeholder left -> error, nothing created/changed; filled -> objects created;
# run twice -> idempotent; placeholder left on existing objects -> unchanged.
#
#   tests/routeros/guard.sh [wifi-mode ...]     (default: local capsman1)
#
# Needs a running CHR without configuration (see README.md). Exit code 0 = all checks passed.
set -uo pipefail
cd "$(dirname "$0")"
REPO=$(cd ../.. && pwd)
source ./lib.sh

WORK=${WORK:-$(mktemp -d /tmp/routeros-guard.XXXXXX)}
MODES=("${@:-local}")
[ $# -eq 0 ] && MODES=(local capsman1)
PASS_WIFI=GuestWifiPass1
PASS_API=Test-api-pass-1
FAILS=0
fail() { echo "FAIL: $*"; FAILS=$((FAILS + 1)); }
pass() { echo "ok: $*"; }
check() { local d=$1; shift; if "$@"; then pass "$d"; else fail "$d"; fi; }

# Terminal paste: every line is typed into an interactive session; returns the session output.
paste() { # <file>
    timeout 600 python3 -I ./paste.py "$1" -- sshpass -p "$ROS_PASS" ssh -tt "${SSH_OPTS[@]}" -p "$ROS_PORT" "$ROS_USER@$ROS_HOST"
}

# /import of the file
import() { # <file>
    ros_upload "$1" guard.rsc || { echo "upload failed"; return 1; }
    ros '/import file-name=guard.rsc'
    ros '/file remove [find where name="guard.rsc"]' >/dev/null
}

# Whole configuration (with secrets, without header lines), for "nothing changed" comparisons.
config() { ros '/export terse verbose show-sensitive' | grep -v '^# [0-9]\{4\}-' | grep -v '^# system id' | grep -v '^# software id' | grep -v '^/system keymat-provider'; }

# Whole guarded group as generated (comment lines dropped): from the opening "{" to its matching "}".
# Other placeholders replaced with harmless values (nothing of the tail may run while the password is left).
group_all() { # <in> <out>
    awk '/^\{$/{f=1} f&&!/^#/{print; o=gsub(/\{/,"{"); c=gsub(/\}/,"}"); d+=o-c; if(d==0) exit}' "$1" \
        | sed -e "s/<COUNTRY>/$COUNTRY/g" -e 's/<MASTER_CFG>/guard-master/g' -e 's/<EXISTING_SLAVES>/guard-slave/g' -e 's/<WIFI_INTERFACE>/ether1/g' > "$2"
}

# Group up to the three own objects: the tail (provisioning/manager/virtual AP) needs hardware on a real router,
# so the "password filled" checks run only this part, closed again with "}".
group_only() { # <in> <out>
    group_all "$1" "$2.all"
    awk '/^\/caps-man provisioning print/ || /^:if \(\[:len \[\/interface wifi find/ {print "}"; exit} {print}' "$2.all" > "$2"
}

fill() { sed -e "s/<WIFI_PASSWORD>/$PASS_WIFI/g" -e "s/<API_PASSWORD>/$PASS_API/g" "$1" > "$2"; }

count_wifi() { # <path> -> number of own wifi objects (comment key) in the 3 menus
    local p=$1 n=0 m
    for m in datapath security configuration; do
        n=$((n + $(ros ":put [:len [$p $m find where comment~\"^guest-portal:wifi:\"]]")))
    done
    echo "$n"
}
wifi_pass() { ros ":put [$1 security get [find where name=sec-guest && comment=\"guest-portal:wifi:sec-guest\"] passphrase]"; }
clean_wifi() { local m; for m in configuration security datapath; do ros "$1 $m remove [find where comment~\"^guest-portal:wifi:\"]" >/dev/null; done; }
clean_api() { ros '/user remove [find where comment~"^guest-portal:api:"]; /user group remove [find where comment~"^guest-portal:api:"]' >/dev/null; }
api_count() { echo $(( $(ros ':put [:len [/user find where comment~"^guest-portal:api:"]]') + $(ros ':put [:len [/user group find where comment~"^guest-portal:api:"]]') )); }

ros_wait
# Prerequisite of the fixture (as in seed.rsc): the bridge the datapath refers to
ros '/interface bridge add name=bridge comment="guard test"' >/dev/null
log "Rendering (work dir $WORK)"
(cd "$REPO" && php tests/routeros/render.php "$WORK/rsc" 10.0.2.15 10.0.2.2 8443) >/dev/null || { echo "render failed"; exit 1; }

run_way() { # <way> <file>
    if [ "$1" = paste ]; then paste "$2"; else import "$2"; fi
}

for way in paste import; do
    # ---- Wi-Fi block
    for MODE in "${MODES[@]}"; do
        P="/interface wifi"; [ "$MODE" = capsman1 ] && P="/caps-man"
        T="[$way/$MODE wifi]"
        COUNTRY=Germany; [ "$MODE" = capsman1 ] && COUNTRY=germany
        group_only "$WORK/rsc/$MODE/network.wifi.masked.rsc" "$WORK/$MODE-masked.rsc"
        fill "$WORK/$MODE-masked.rsc" "$WORK/$MODE-filled.rsc"
        check "$T block starts with { and has the guard" grep -q '^:if ("<WIFI_PASSWORD>" = ("<" \. "WIFI_PASSWORD>")) do={ :error' "$WORK/$MODE-masked.rsc"
        check "$T filled copy has no placeholder left" bash -c "! grep -q '<WIFI_PASSWORD>' '$WORK/$MODE-filled.rsc'"
        clean_wifi "$P"; config > "$WORK/cfg0.txt"

        out=$(run_way $way "$WORK/$MODE-masked.rsc"); printf '%s\n' "$out" > "$WORK/out-$way-$MODE-left1.txt"
        check "$T placeholder left: error message" grep -qE '(^|[Ee]rror: )replace <WIFI_PASSWORD> first' <<<"$out"
        check "$T placeholder left: no object created" test "$(count_wifi "$P")" = 0
        check "$T placeholder left: configuration unchanged" diff -q "$WORK/cfg0.txt" <(config)

        # Tail inside the group: nothing after the guard may run (paste runs line by line, import as a whole)
        group_all "$WORK/rsc/$MODE/network.wifi.masked.rsc" "$WORK/$MODE-full-masked.rsc"
        check "$T whole block (incl. tail) is one group" test "$(tail -n1 "$WORK/$MODE-full-masked.rsc")" = "}"
        if [ "$MODE" = capsman1 ]; then
            ros '/caps-man manager set enabled=no' >/dev/null
            config > "$WORK/cfg0t.txt"
            out=$(run_way $way "$WORK/$MODE-full-masked.rsc"); printf '%s\n' "$out" > "$WORK/out-$way-$MODE-tail.txt"
            check "$T tail, placeholder left: error message" grep -qE '(^|[Ee]rror: )replace <WIFI_PASSWORD> first' <<<"$out"
            check "$T tail, placeholder left: caps-man manager still disabled" test "$(ros ':put [/caps-man manager get enabled]')" = false
        else
            config > "$WORK/cfg0t.txt"
            out=$(run_way $way "$WORK/$MODE-full-masked.rsc"); printf '%s\n' "$out" > "$WORK/out-$way-$MODE-tail.txt"
            check "$T tail, placeholder left: error message" grep -qE '(^|[Ee]rror: )replace <WIFI_PASSWORD> first' <<<"$out"
            check "$T tail, placeholder left: no wifi-guest interface added" test "$(ros ':put [:len [/interface wifi find where name=wifi-guest]]')" = 0
        fi
        check "$T tail, placeholder left: configuration unchanged" diff -q "$WORK/cfg0t.txt" <(config)

        out=$(run_way $way "$WORK/$MODE-filled.rsc"); printf '%s\n' "$out" > "$WORK/out-$way-$MODE-fill1.txt"
        check "$T password filled: no error" bash -c "! grep -qiE 'replace <|script error|syntax error|failure:|bad command|expected end|no such item|does not match' '$WORK/out-$way-$MODE-fill1.txt'"
        check "$T password filled: 3 objects created" test "$(count_wifi "$P")" = 3
        check "$T password filled: passphrase set" test "$(wifi_pass "$P")" = "$PASS_WIFI"
        config > "$WORK/cfg1.txt"

        out=$(run_way $way "$WORK/$MODE-filled.rsc")
        check "$T run twice: still 3 objects (add-or-set)" test "$(count_wifi "$P")" = 3
        check "$T run twice: configuration identical" diff -q "$WORK/cfg1.txt" <(config)

        out=$(run_way $way "$WORK/$MODE-masked.rsc")
        check "$T placeholder left on existing objects: error" grep -qE '(^|[Ee]rror: )replace <WIFI_PASSWORD> first' <<<"$out"
        check "$T placeholder left on existing objects: passphrase unchanged" test "$(wifi_pass "$P")" = "$PASS_WIFI"
        check "$T placeholder left on existing objects: configuration identical" diff -q "$WORK/cfg1.txt" <(config)
        clean_wifi "$P"
    done

    # ---- API access block (placeholder <API_PASSWORD>)
    T="[$way api_access]"
    MODE0=${MODES[0]}
    group_only "$WORK/rsc/$MODE0/api_access.rsc" "$WORK/api-masked.rsc"
    fill "$WORK/api-masked.rsc" "$WORK/api-filled.rsc"
    clean_api; config > "$WORK/cfg0.txt"
    out=$(run_way $way "$WORK/api-masked.rsc"); printf '%s\n' "$out" > "$WORK/out-$way-api-left1.txt"
    check "$T placeholder left: error message" grep -qE '(^|[Ee]rror: )replace <API_PASSWORD> first' <<<"$out"
    check "$T placeholder left: no group/user created" test "$(api_count)" = 0
    check "$T placeholder left: configuration unchanged" diff -q "$WORK/cfg0.txt" <(config)
    out=$(run_way $way "$WORK/api-filled.rsc")
    check "$T password filled: group and user created" test "$(api_count)" = 2
    config > "$WORK/cfg1.txt"
    out=$(run_way $way "$WORK/api-filled.rsc")
    check "$T run twice: still group and user once" test "$(api_count)" = 2
    check "$T run twice: configuration identical" diff -q "$WORK/cfg1.txt" <(config)
    out=$(run_way $way "$WORK/api-masked.rsc")
    check "$T placeholder left on existing objects: error" grep -qE '(^|[Ee]rror: )replace <API_PASSWORD> first' <<<"$out"
    check "$T placeholder left on existing objects: configuration identical" diff -q "$WORK/cfg1.txt" <(config)
    clean_api
done

ros '/interface bridge remove [find where comment="guard test"]' >/dev/null
log "Result: $FAILS failure(s); details in $WORK"
[ $FAILS = 0 ]
