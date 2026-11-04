#!/bin/sh
# NAS simulator run for the generic RADIUS captive-portal driver (experimental, decision 2026-11-04, v0.28.0).
#
# Container mode (the real target):
#   tests/nas-sim/run.sh <portal-container> [preset ...]
#   The simulator and a Playwright browser run in Docker in the NETWORK NAMESPACE of the portal container
#   (--network container:<name>): the simulator reaches FreeRADIUS on 127.0.0.1:1812/1813, the portal sends
#   its Disconnect-Request to 127.0.0.1:3799 (the router entry's NAS IP is 127.0.0.1), and the browser opens
#   the guest portal at https://127.0.0.1:<GUEST_PORT>. Nothing is published on the host.
#   The script seeds the portal through `docker exec -e HOME=/tmp <container> artisan tinker` (the image's artisan
#   wrapper runs as the app user, and psysh needs a writable HOME or it drops the output): site "nas-sim" (driver
#   generic_radius, preset applied, hotspot host 127.0.0.1), router "NAS simulator" (NAS IP 127.0.0.1) and one
#   access code per preset. Use a TEST instance: it adds that site, router and codes (remove with --cleanup).
#
# Local mode (no FreeRADIUS, no Docker):
#   PORTAL_DEFAULT_ZONE=guest php artisan serve      # in the repository, other terminal
#   tests/nas-sim/run.sh --local http://127.0.0.1:8000 [preset ...]
#   Seeds through `php artisan tinker` in the repository, runs the simulator with --no-radius and Playwright
#   from local node_modules (npm i --no-save playwright). Checks redirect and handoff only; RADIUS is skipped.
#
# Presets: cisco_wlc aruba pfsense opnsense opnsense_fragment coovachilli ruckus custom (default: all; opnsense_fragment
# = preset opnsense with the handoff method "fragment" through docs/opnsense-captive-template). Summary table at the end.
# Env: SIM_PORT (8099; ruckus always 9998), SITE_SLUG (nas-sim), PYTHON_IMAGE, PLAYWRIGHT_VERSION.
set -eu

DIR=$(cd "$(dirname "$0")" && pwd)
ROOT=$(cd "$DIR/../.." && pwd)
SIM_PORT=${SIM_PORT:-8099}
SLUG=${SITE_SLUG:-nas-sim}
PYTHON_IMAGE=${PYTHON_IMAGE:-python:3.12-alpine}
PLAYWRIGHT_VERSION=${PLAYWRIGHT_VERSION:-1.49.1}
PLAYWRIGHT_IMAGE=${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble}
UAMSECRET=sim-uamsecret
ALL="cisco_wlc aruba pfsense opnsense opnsense_fragment coovachilli ruckus custom"

usage() { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
say() { printf '%s\n' "[run] $*" >&2; }

MODE=container
CLEANUP=0
case "${1:-}" in
    ''|-h|--help) usage ;;
    --local) MODE=local; PORTAL_URL=${2:?portal URL, e.g. http://127.0.0.1:8000}; shift 2 ;;
    --cleanup) CLEANUP=1; CONTAINER=${2:?container}; shift 2 ;;
    *) CONTAINER=$1; shift ;;
esac
PRESETS=${*:-$ALL}

artisan() {
    if [ "$MODE" = local ]; then (cd "$ROOT" && php artisan tinker --execute="$1"); else docker exec -e HOME=/tmp "$CONTAINER" artisan tinker --execute="$1"; fi
}

if [ "$CLEANUP" = 1 ]; then
    artisan '$s = App\Models\Site::where("slug", "'"$SLUG"'")->first(); if ($s) { $s->nasClients()->each(fn ($n) => $n->delete()); $s->delete(); app(App\Radius\ClientsFile::class)->write(); } echo "removed\n";'
    exit 0
fi

if [ "$MODE" = container ]; then
    command -v docker >/dev/null || { say "docker not found"; exit 1; }
    [ "$(docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null)" = true ] || { say "container $CONTAINER is not running"; exit 1; }
    GUEST_PORT=$(docker exec "$CONTAINER" printenv GUEST_PORT 2>/dev/null || true)
    PORTAL_URL="https://127.0.0.1:${GUEST_PORT:-443}"
    SIM_HOST=127.0.0.1
    command -v openssl >/dev/null || { say "openssl not found (needed for the simulator's test certificate)"; exit 1; }
    CERTS=$(mktemp -d)
    trap 'rm -rf "$CERTS"' EXIT
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=127.0.0.1" -keyout "$CERTS/sim.key" -out "$CERTS/sim.crt" 2>/dev/null
    chmod 644 "$CERTS/sim.key"
else
    SIM_HOST=127.0.0.1
    CERTS=$(mktemp -d)
    trap 'rm -rf "$CERTS"; [ -n "${SIM_PID:-}" ] && kill "$SIM_PID" 2>/dev/null || true' EXIT
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=127.0.0.1" -keyout "$CERTS/sim.key" -out "$CERTS/sim.crt" 2>/dev/null
fi
say "portal $PORTAL_URL, mode $MODE, presets: $PRESETS"

# Seed: site, preset mapping, router, access code. Prints "SEED {json}".
seed() {
    preset=$1 port=$2
    artisan '
use App\Models\Site; use App\Models\NasClient; use App\Nas\Generic\GenericSettings; use App\Nas\Generic\Presets;
$preset = "'"$preset"'"; $port = '"$port"'; $slug = "'"$SLUG"'";
$site = Site::where("slug", $slug)->first() ?? Site::create(["slug" => $slug, "name" => "NAS simulator", "driver" => "generic_radius", "settings" => []]);
$other = NasClient::where("nas_ip", "127.0.0.1")->where("site_id", "!=", $site->id)->exists();
if ($other) { echo "SEED_ERROR another site already has a router with NAS IP 127.0.0.1\n"; return; }
$site->driver = "generic_radius";
$site->settings = array_merge($site->settings ?? [], ["hotspot_hosts" => ["127.0.0.1"], "portal_url" => "'"$PORTAL_URL"'",
    "radius_address" => "127.0.0.1", "portal_address" => "127.0.0.1"]);
$site->save();
$overrides = match ($preset) {
    "aruba" => ["login_url" => "https://127.0.0.1:".$port."/cgi-bin/login"],
    "custom" => ["param_login_url" => "login"],
    "opnsense_fragment" => ["method" => "fragment", "login_url" => "http://{nas_host}/index.html"],
    default => [],
};
$variant = $preset; $preset = $preset === "opnsense_fragment" ? "opnsense" : $preset;
$site->settings = GenericSettings::mergeInto($site, ["preset" => $preset] + $overrides + Presets::PRESETS[$preset]
    + ["wispr" => true, "disconnect_attrs" => Presets::DISCONNECT[$preset] ?? GenericSettings::DEFAULT_DISCONNECT], $preset === "coovachilli" ? "'"$UAMSECRET"'" : "");
$site->save();
$nas = $site->nasClients()->where("nas_ip", "127.0.0.1")->first() ?? $site->nasClients()->create(["name" => "NAS simulator", "nas_ip" => "127.0.0.1", "coa_port" => 3799]);
$written = app(App\Radius\ClientsFile::class)->write();
$grant = app(App\Services\GrantManager::class)->createCode(["duration_s" => 3600, "expiry_mode" => App\Enums\ExpiryMode::FirstLogin,
    "rate_limit" => "1M/4M", "label" => "nas-sim ".$variant]);
echo "SEED ".json_encode(["secret" => $nas->radius_secret, "code" => $grant->code, "grant" => $grant->id, "written" => $written])."\n";
' | sed -n 's/^SEED //p;s/^\(SEED_ERROR .*\)/\1/p'
}

json() { python3 -c 'import json,sys; print(json.loads(sys.argv[1]).get(sys.argv[2], ""))' "$1" "$2"; }

state() {
    if [ "$MODE" = local ]; then
        python3 - "$1" <<'PY'
import ssl, sys, urllib.request
print(urllib.request.urlopen(sys.argv[1] + '/state', context=ssl._create_unverified_context(), timeout=5).read().decode())
PY
    else
        docker exec "nas-sim-$2" python3 -c 'import ssl,sys,urllib.request; print(urllib.request.urlopen(sys.argv[1] + "/state", context=ssl._create_unverified_context(), timeout=5).read().decode())' "$1"
    fi
}

check() { python3 -c 'import json,sys; s=json.loads(sys.stdin.read()); print(eval(sys.argv[1]))' "$1"; }

SUMMARY=""
FAILED=0
for preset in $PRESETS; do
    port=$SIM_PORT
    [ "$preset" = ruckus ] && port=9998
    sim_preset=$preset
    [ "$preset" = opnsense_fragment ] && sim_preset=opnsense
    scheme=https
    case "$sim_preset" in coovachilli|opnsense) scheme=http ;; esac
    # OPNsense has no Disconnect-Request: revoke must not reach the device, access ends at the Session-Timeout.
    nocoa=0
    [ "$sim_preset" = opnsense ] && nocoa=1
    sim_url="$scheme://$SIM_HOST:$port"
    say "== $preset: seeding"
    out=$(seed "$preset" "$port")
    case "$out" in SEED_ERROR*|'') say "seed failed: ${out:-no output}"; FAILED=1; SUMMARY="$SUMMARY
$preset|seed failed|-|-|-|-|-|-"; continue ;; esac
    secret=$(json "$out" secret); code=$(json "$out" code); grant=$(json "$out" grant)
    [ "$(json "$out" written)" = True ] && { say "clients file rewritten: waiting for the FreeRADIUS restart"; sleep 8; }

    args="--preset $sim_preset --portal $PORTAL_URL --site $SLUG --host $SIM_HOST --port $port --secret $secret --uamsecret $UAMSECRET"
    if [ "$MODE" = local ]; then
        # shellcheck disable=SC2086
        python3 "$DIR/nas_sim.py" $args --no-radius --cert "$CERTS/sim.crt" --key "$CERTS/sim.key" --template "$ROOT/docs/opnsense-captive-template/index.html" &
        SIM_PID=$!
        sleep 1
        (cd "$DIR" && SIM_URL="$sim_url/" CODE="$code" PRESET="$preset" NO_RADIUS=1 node flow.js) >"$CERTS/flow.json" || true
    else
        docker rm -f "nas-sim-$preset" >/dev/null 2>&1 || true
        # shellcheck disable=SC2086
        docker run -d --name "nas-sim-$preset" --network "container:$CONTAINER" -v "$DIR:/sim:ro" -v "$CERTS:/certs:ro" \
            -v "$ROOT/docs/opnsense-captive-template:/template:ro" \
            "$PYTHON_IMAGE" python3 -u /sim/nas_sim.py $args --cert /certs/sim.crt --key /certs/sim.key --template /template/index.html >/dev/null
        sleep 2
        docker run --rm --network "container:$CONTAINER" -v "$DIR:/sim:ro" -e SIM_URL="$sim_url/" -e CODE="$code" -e PRESET="$preset" \
            "$PLAYWRIGHT_IMAGE" sh -c "cd /tmp && npm init -y >/dev/null 2>&1 && npm i --no-save --no-audit --no-fund playwright@$PLAYWRIGHT_VERSION >/dev/null 2>&1 && cp /sim/flow.js . && node flow.js" \
            >"$CERTS/flow.json" || true
    fi
    flow_ok=$(python3 -c 'import json,sys; l=[x for x in open(sys.argv[1]).read().splitlines() if x.startswith("{")]; print(bool(l) and json.loads(l[-1]).get("ok"))' "$CERTS/flow.json")
    say "flow: $(tail -n 1 "$CERTS/flow.json")"

    disc=-; stop=-
    if [ "$MODE" = container ] && [ "$flow_ok" = True ]; then
        artisan '$g = App\Models\Grant::find("'"$grant"'"); app(App\Services\GrantManager::class)->revoke($g); echo "revoked\n";' >/dev/null
        if [ "$nocoa" = 1 ]; then
            say "revoked the grant: no Disconnect-Request expected (not supported), waiting 15 s"
            sleep 15
        else
            say "revoked the grant: expecting Disconnect-ACK and Accounting-Stop"
        fi
        i=0
        while [ "$nocoa" = 0 ] && [ $i -lt 30 ]; do
            st=$(state "$sim_url" "$preset" 2>/dev/null || echo '{}')
            stop=$(printf '%s' "$st" | check '"Stop" in s.get("accounting", [])')
            [ "$stop" = True ] && break
            sleep 2; i=$((i + 1))
        done
        st=$(state "$sim_url" "$preset" 2>/dev/null || echo '{}')
        stop=$(printf '%s' "$st" | check '"Stop" in s.get("accounting", [])')
        disc=$(printf '%s' "$st" | check '",".join(d["result"] for d in s.get("disconnect", [])) or "none"')
    fi
    st=$(state "$sim_url" "$preset" 2>/dev/null || echo '{}')
    handoff=$(printf '%s' "$st" | check 'bool(s.get("handoff"))')
    access=$(printf '%s' "$st" | check '(s.get("access") or {}).get("result", "-")')
    wispr=$(printf '%s' "$st" | check '"%s/%s" % ((s.get("access") or {}).get("wispr_up", "-"), (s.get("access") or {}).get("wispr_down", "-"))')
    start=$(printf '%s' "$st" | check '"Start" in s.get("accounting", [])')
    errors=$(printf '%s' "$st" | check '"; ".join(s.get("errors", []))[:120] or "-"')
    if [ "$MODE" = local ]; then
        ok=$([ "$flow_ok" = True ] && [ "$handoff" = True ] && echo PASS || echo FAIL)
        kill "$SIM_PID" 2>/dev/null || true; wait "$SIM_PID" 2>/dev/null || true; SIM_PID=
    else
        if [ "$nocoa" = 1 ]; then
            # Expected: accepted, no Disconnect-Request reached the device, the session stays until Session-Timeout.
            ok=$([ "$flow_ok" = True ] && [ "$access" = accept ] && [ "$disc" = none ] && [ "$stop" = False ] && echo PASS || echo FAIL)
        else
            ok=$([ "$flow_ok" = True ] && [ "$access" = accept ] && [ "$disc" = ack ] && [ "$stop" = True ] && echo PASS || echo FAIL)
        fi
        docker logs "nas-sim-$preset" 2>&1 | tail -n 40 >&2
        docker rm -f "nas-sim-$preset" >/dev/null 2>&1 || true
    fi
    [ "$ok" = PASS ] || FAILED=1
    SUMMARY="$SUMMARY
$preset|$flow_ok|$handoff|$access|$wispr|$start|$disc|$stop|$ok|$errors"
done

printf '\n%-18s %-6s %-8s %-7s %-17s %-6s %-11s %-6s %-5s %s\n' preset flow handoff access wispr_up/down start disconnect stop result errors
printf '%s\n' "$SUMMARY" | while IFS='|' read -r p f h a w s d t r e; do
    [ -n "$p" ] && printf '%-18s %-6s %-8s %-7s %-17s %-6s %-11s %-6s %-5s %s\n' "$p" "$f" "$h" "$a" "$w" "$s" "$d" "$t" "$r" "${e:-}"
done
[ "$MODE" = local ] && say "local mode: RADIUS, accounting and Disconnect-Request were skipped (no FreeRADIUS)."
exit $FAILED
