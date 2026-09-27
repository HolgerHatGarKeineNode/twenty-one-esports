#!/usr/bin/env bash
# Starts and stops the promo Nostr bridge for the gallery opened as a plain file in Firefox.
# Called by the desktop handler for twentyone-bridge: links (~/.local/share/applications/twentyone-bridge.desktop,
# registered with: xdg-mime default twentyone-bridge.desktop x-scheme-handler/twentyone-bridge) or by hand:
#   bridge-ctl.sh start|stop|connect|status   (or twentyone-bridge:start etc.)
# Every start draws a new random token; the gallery gets it once through the URL fragment of the
# tab this script opens (#bridge=<token>), and the bridge refuses any request without it. That is
# what keeps other web pages away: a file:// page sends Origin "null", which any site can fake.
set -euo pipefail

action="${1:-status}"
action="${action#twentyone-bridge:}"
action="${action#//}"
action="${action%%/*}"

SRC="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
PROMO="$(dirname "$SRC")"
REPO="$(cd "$PROMO/../.." && pwd)"
RUN="${XDG_RUNTIME_DIR:-/tmp}/twentyone-bridge"
PORT=8919
mkdir -p "$RUN" && chmod 700 "$RUN"

running() {
    [ -f "$RUN/pid" ] || return 1
    local pid; pid="$(cat "$RUN/pid")"
    [ -n "$pid" ] && tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null | grep -q "127.0.0.1:$PORT .*nostr-bridge.php"
}

open_gallery() {
    local url="file://$PROMO/gallery.html#bridge=$1"
    if command -v firefox >/dev/null 2>&1; then firefox --new-tab "$url" >/dev/null 2>&1 & else xdg-open "$url" >/dev/null 2>&1 & fi
}

start() {
    if running; then open_gallery "$(cat "$RUN/token")"; return; fi
    local token; token="$(openssl rand -hex 24)"
    (umask 077 && printf '%s' "$token" > "$RUN/token")
    cd "$REPO"
    PROMO_BRIDGE_TOKEN="$token" nohup php -S "127.0.0.1:$PORT" docs/promo/src/nostr-bridge.php > "$RUN/bridge.log" 2>&1 &
    echo $! > "$RUN/pid"
    for _ in $(seq 1 30); do
        curl -s -o /dev/null "http://127.0.0.1:$PORT/status" && break
        sleep 0.2
    done
    [ "${NO_OPEN:-}" = 1 ] || open_gallery "$token"
}

stop() {
    if running; then kill "$(cat "$RUN/pid")"; fi
    rm -f "$RUN/pid" "$RUN/token"
}

case "$action" in
    start|connect) start ;;
    stop) stop ;;
    status) if running; then echo running; else echo stopped; fi ;;
    *) echo "usage: $0 start|stop|connect|status" >&2; exit 2 ;;
esac
