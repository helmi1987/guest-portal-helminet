# Shared helpers of the RouterOS CHR harness (sourced by run.sh, guard.sh and chr.sh).
# Connection: ROS_HOST/ROS_PORT/ROS_USER/ROS_PASS (CHR default user admin, empty password).

ROS_HOST=${ROS_HOST:-127.0.0.1}
ROS_PORT=${ROS_PORT:-2222}
ROS_USER=${ROS_USER:-admin}
ROS_PASS=${ROS_PASS:-}
# Router API forwards (router state query): www-ssl (REST) and api-ssl
ROS_REST_PORT=${ROS_REST_PORT:-2443}
ROS_APISSL_PORT=${ROS_APISSL_PORT:-2729}
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10
    -o ServerAliveInterval=10 -o ServerAliveCountMax=6)

# Run RouterOS commands; output with CR removed and trailing blanks trimmed.
ros() {
    timeout "${ROS_TIMEOUT:-180}" sshpass -p "$ROS_PASS" ssh "${SSH_OPTS[@]}" -p "$ROS_PORT" "$ROS_USER@$ROS_HOST" "$1" </dev/null \
        | tr -d '\r' | sed -e 's/[[:space:]]*$//'
}

ros_upload() { # <local file> <remote name>
    timeout 120 sshpass -p "$ROS_PASS" scp -O -q "${SSH_OPTS[@]}" -P "$ROS_PORT" "$1" "$ROS_USER@$ROS_HOST:/$2"
}

ros_wait() { # wait until SSH answers (max ~6 min under TCG)
    local i
    for i in $(seq 1 120); do
        if ROS_TIMEOUT=15 ros ':put ready' 2>/dev/null | grep -q ready; then return 0; fi
        sleep 3
    done
    echo "RouterOS did not answer on $ROS_HOST:$ROS_PORT" >&2
    return 1
}

# Full verbose export (with secrets) without the lines that change by themselves, for "nothing changed"
# comparisons (run.sh snapshot(), guard.sh config()). None of them is touched by the portal scripts:
# - header lines (timestamp, system id, software id)
# - /system keymat-provider: its hkdf-secret="" appears/disappears by itself (RouterOS internal)
# - /app (built-in app catalog, incl. continuation lines) and the default ipsec profile: their export
#   varies between runs on RouterOS 7.25 (the profile line moves)
# - /system resource irq ... cpu=auto: these lines appear a few seconds after boot (RouterOS 7.24.5)
ros_export() {
    ros '/export terse verbose show-sensitive' | grep -v '^# [0-9]\{4\}-' | grep -v '^# system id' | grep -v '^# software id' \
        | grep -v '^/system keymat-provider' \
        | awk '/^\/(app |ip ipsec profile set \[ find default=yes \])/ { skip = 1 } skip { if ($0 !~ /\\$/) skip = 0; next } { print }' \
        | grep -vE '^/system resource irq set .*cpu=auto( |$)'
}

log() { printf '\n=== %s\n' "$*"; }
