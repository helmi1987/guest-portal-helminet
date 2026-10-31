#!/usr/bin/env bash
# Boot/stop a RouterOS CHR in qemu for the harness (KVM when available, otherwise TCG).
#
#   tests/routeros/chr.sh prepare <version> <dir>   download CHR image + extra packages into <dir>,
#                                                    install wireless + container, write <dir>/base.img
#   tests/routeros/chr.sh start <image> [console-log]  boot <image> (raw or qcow2; it is modified: use a copy)
#   tests/routeros/chr.sh savevm|loadvm <tag>          VM snapshot (qcow2 images only): loadvm resets the
#                                                    router to the saved state in seconds instead of a reboot
#   tests/routeros/chr.sh stop
#
# Host forward: $ROS_PORT (default 2222) -> 22 (SSH). Inside the CHR the host is 10.0.2.2 (qemu user net).
set -euo pipefail
cd "$(dirname "$0")"
source ./lib.sh

PIDFILE=${CHR_PIDFILE:-/tmp/routeros-chr.pid}
MONITOR=${CHR_MONITOR:-/tmp/routeros-chr.mon}

monitor() { # <command> -> qemu human monitor over the unix socket
    python3 -I - "$MONITOR" "$1" <<'PY'
import socket, sys, time
s = socket.socket(socket.AF_UNIX); s.connect(sys.argv[1]); s.settimeout(600)
def until(marker):
    buf = b""
    while marker not in buf:
        chunk = s.recv(4096)
        if not chunk: break
        buf += chunk
    return buf
until(b"(qemu)")
s.sendall(sys.argv[2].encode() + b"\n")
out = until(b"(qemu)").decode(errors="replace")
print("\n".join(l for l in out.splitlines()[1:] if "(qemu)" not in l and "\x1b" not in l))
PY
}

accel() {
    if [ -w /dev/kvm ]; then echo "-accel kvm -cpu host"; else echo "-accel tcg"; fi
}

start() { # <image> [console-log]
    local img=$1 console=${2:-/tmp/routeros-chr-console.log} fmt=raw
    case "$img" in *.qcow2) fmt=qcow2 ;; esac
    rm -f "$MONITOR"
    # shellcheck disable=SC2046
    qemu-system-x86_64 $(accel) -m 256 -smp 2 -display none -serial "file:$console" \
        -monitor "unix:$MONITOR,server,nowait" -drive "file=$img,format=$fmt,if=virtio" \
        -netdev "user,id=n0,hostfwd=tcp::${ROS_PORT}-:22" \
        -device virtio-net-pci,netdev=n0 -daemonize -pidfile "$PIDFILE"
    echo "CHR started ($(accel)), pid $(cat "$PIDFILE")"
    ros_wait
}

stop() {
    if [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
        ROS_TIMEOUT=15 ros '/system shutdown' >/dev/null 2>&1 <<<y || true
        for _ in $(seq 1 30); do kill -0 "$(cat "$PIDFILE" 2>/dev/null)" 2>/dev/null || break; sleep 2; done
        kill "$(cat "$PIDFILE" 2>/dev/null)" 2>/dev/null || true
    fi
    rm -f "$PIDFILE"
}

prepare() { # <version> <dir>
    local v=$1 dir=$2
    mkdir -p "$dir"
    curl -fsSL -o "$dir/chr.zip" "https://download.mikrotik.com/routeros/$v/chr-$v.img.zip"
    curl -fsSL -o "$dir/pkgs.zip" "https://download.mikrotik.com/routeros/$v/all_packages-x86-$v.zip"
    unzip -p "$dir/chr.zip" > "$dir/base.img"
    unzip -o -q -d "$dir/pkgs" "$dir/pkgs.zip"
    start "$dir/base.img" "$dir/console-prepare.log"
    local p
    for p in wireless container; do ros_upload "$dir/pkgs/$p-$v.npk" "$p-$v.npk"; done
    ROS_TIMEOUT=15 ros '/system reboot' >/dev/null 2>&1 || true
    sleep 10
    ros_wait
    ros '/system package print'
    stop
}

case "${1:-}" in
    start) start "$2" "${3:-}" ;;
    stop) stop ;;
    savevm) monitor "savevm $2" ;;
    loadvm) monitor "loadvm $2"; sleep 2; ros_wait ;;
    prepare) prepare "$2" "$3" ;;
    *) echo "usage: $0 prepare <version> <dir> | start <image> [console-log] | savevm|loadvm <tag> | stop" >&2; exit 2 ;;
esac
