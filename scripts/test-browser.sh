#!/usr/bin/env bash
#
# Runs the Browser suite against the host's own Chromium (never a Playwright
# download — see link-host-chromium.sh) and against the built Vite assets,
# not the dev server: LaravelHttpServer serves public/build/* directly, so a
# stale build silently renders stale JS/CSS in the browser tests.
#
# TIA is off: the Browser group is opt-in and outside the TIA-tracked
# default suite, and passing no path argument crashes the TIA plugin
# outright ("A dependency with the name [Pest\Plugins\Tia\Contracts\State]
# cannot be resolved" — the same failure other repos in this fleet hit).
#
# SHARDING. With no arguments this splits the suite across SHARD_FILES below
# and runs each group in its own `pest` process, in parallel. Each shard gets:
#   - its own SQLite database. DB_DATABASE is ":memory:" (phpunit.xml, not
#     forced), so this falls out for free — every process has its own
#     connection and its own in-memory database, no file or coordination
#     needed. Nothing here has to allocate one.
#   - its own Reverb server, on its own OS-assigned port, with its own
#     throwaway app credentials — a straight copy of the single-shard setup
#     below, run once per shard instead of once per suite.
#   - its own Playwright/Chromium launch and its own LaravelHttpServer port,
#     both already OS-assigned per *process* by the plugin itself
#     (Pest\Browser\Support\Port::find() asks the kernel for a free port);
#     running several of these processes at once needs nothing extra here.
# Ports are never hand-picked or precomputed: each shard's Reverb port comes
# from the kernel (stream_socket_server on port 0) right before that shard
# starts, so two shards can never collide even if they start in the same
# tick.
#
# Files are grouped by measured wall time (per-test durations captured with a
# temporary beforeEach/afterEach timer on this machine — RouteSweepTest alone
# is heavier than any other file, so it gets its own shard):
#   1: RouteSweepTest                                (~29s)
#   2: BlitzGameTest, ClanRosterTest, LoginTest, ClanLogoTest (~26s + clan logos)
#   3: ChatAndDailyTest, SeriesResultTest, OpponentRatedTest, TournamentFlowTest, LadderDefaultTest (~27s + P8b + ladder)
#   4: NotificationsTest, SeasonChainTest, ClanEditTest (~25s)
# A file added to tests/Browser/ and not added to SHARD_FILES below would
# silently never run — the check after the array definition fails loudly
# instead.
#
# With arguments (a --filter, a specific file, --profile, ...) this instead
# runs a single, unsharded process exactly like before: sharding a filtered
# or profiled ad-hoc run has no well-defined split, so it is not attempted.
#
# Reverb: a throwaway Reverb server is started for each shard, on a free
# port with throwaway app credentials, and stopped when that shard ends. The
# same values are exported to that shard's test process, so the app
# broadcasts to it and the pages (which read the Reverb settings at runtime,
# partials/head.blade.php) connect to it. Exported variables win over
# phpunit.xml's non-forced <env> entries, which is how
# BROADCAST_CONNECTION=reverb reaches the tests.
#
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/link-host-chromium.sh

npm run build

SHARD_FILES=(
    "tests/Browser/RouteSweepTest.php"
    "tests/Browser/BlitzGameTest.php tests/Browser/ClanRosterTest.php tests/Browser/LoginTest.php tests/Browser/ClanLogoTest.php"
    "tests/Browser/ChatAndDailyTest.php tests/Browser/SeriesResultTest.php tests/Browser/OpponentRatedTest.php tests/Browser/TournamentFlowTest.php tests/Browser/LadderDefaultTest.php"
    "tests/Browser/NotificationsTest.php tests/Browser/SeasonChainTest.php tests/Browser/ClanEditTest.php tests/Browser/TournamentChooserTest.php"
)

# Guard against a new tests/Browser/*Test.php file that nobody assigned to a
# shard: without this, it would just never run, and no exit code would say so.
assigned=$(printf '%s\n' "${SHARD_FILES[@]}" | tr ' ' '\n' | sort)
present=$(cd tests/Browser && ls -- *.php 2>/dev/null | sed 's#^#tests/Browser/#' | sort)
if [ "$assigned" != "$present" ]; then
    echo "test-browser: SHARD_FILES in $0 does not match tests/Browser/*.php." >&2
    echo "--- assigned ---" >&2
    echo "$assigned" >&2
    echo "--- present ---" >&2
    echo "$present" >&2
    exit 1
fi

run_single() {
    REVERB_PORT=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')

    export BROADCAST_CONNECTION=reverb
    export REVERB_APP_ID=browser-tests
    export REVERB_APP_KEY="browser-tests-$(php -r 'echo bin2hex(random_bytes(6));')"
    export REVERB_APP_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"
    export REVERB_HOST=127.0.0.1
    export REVERB_PORT
    export REVERB_SCHEME=http

    php artisan reverb:start --host=127.0.0.1 --port="$REVERB_PORT" --no-interaction >/dev/null 2>&1 &
    REVERB_PID=$!
    trap 'kill "$REVERB_PID" 2>/dev/null || true' EXIT

    for _ in $(seq 1 50); do
        if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$REVERB_PORT"; then
            break
        fi
        sleep 0.1
    done

    php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$REVERB_PORT" \
        || { echo "test-browser: Reverb did not start on port $REVERB_PORT" >&2; exit 1; }

    vendor/bin/pest --group=browser --no-tia "$@"
}

run_shard() {
    local idx=$1
    shift
    local -a files=("$@")

    local port
    port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')

    export BROADCAST_CONNECTION=reverb
    export REVERB_APP_ID="browser-tests-$idx"
    export REVERB_APP_KEY="browser-tests-$idx-$(php -r 'echo bin2hex(random_bytes(6));')"
    export REVERB_APP_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"
    export REVERB_HOST=127.0.0.1
    export REVERB_PORT="$port"
    export REVERB_SCHEME=http

    php artisan reverb:start --host=127.0.0.1 --port="$port" --no-interaction >/dev/null 2>&1 &
    # Not `local`: the EXIT trap below fires after this function returns (at
    # the end of the run_shard subshell), by which point a local variable is
    # already out of scope — set -u then turns the trap itself into "unbound
    # variable" instead of killing Reverb. Plain assignment scopes it to the
    # subshell that runs this whole function, which is exactly what the trap
    # needs and does not leak beyond that one shard's process.
    reverb_pid=$!
    trap 'kill "$reverb_pid" 2>/dev/null || true' EXIT

    for _ in $(seq 1 50); do
        if php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$port"; then
            break
        fi
        sleep 0.1
    done

    php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1]) ? 0 : 1);' "$port" \
        || { echo "test-browser: shard $idx: Reverb did not start on port $port" >&2; exit 1; }

    vendor/bin/pest --group=browser --no-tia "${files[@]}"
}

if [ "$#" -gt 0 ]; then
    run_single "$@"
    exit $?
fi

LOGDIR=$(mktemp -d)
cleanup_logdir() { rm -rf "$LOGDIR"; }
trap cleanup_logdir EXIT

pids=()
for i in "${!SHARD_FILES[@]}"; do
    idx=$((i + 1))
    read -ra files <<< "${SHARD_FILES[$i]}"

    (run_shard "$idx" "${files[@]}") >"$LOGDIR/shard-$idx.log" 2>&1 &
    pids+=($!)
done

status=0
for i in "${!pids[@]}"; do
    if ! wait "${pids[$i]}"; then
        status=1
    fi
done

for i in "${!SHARD_FILES[@]}"; do
    idx=$((i + 1))
    echo "===== shard $idx: ${SHARD_FILES[$i]} ====="
    cat "$LOGDIR/shard-$idx.log"
done

exit "$status"
