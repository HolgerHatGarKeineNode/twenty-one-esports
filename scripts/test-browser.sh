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
#   1: RouteSweepTest, NotificationDmPagesTest (~29s + ~6s, grown since), LiveCountTest (P20b, the live count and on-air flips), ClanPrideTest (/clans cards and proud moments at 0, 2 and 12 clans)
#   2: BlitzGameTest, ClanRosterTest, LoginTest, ClanLogoTest, ShareTest, StrongestListTest (~26s + clan logos + P40)
#   3: ChatAndDailyTest, ChessCorrespondenceQuietTest (P52), SeriesResultTest, OpponentRatedTest, OpponentRequestsTest (P57), TournamentFlowTest, LadderDefaultTest, GameCoversTest, GamePageTest, MempoolStripTest (~27s + P8b + ladder + covers + P26 game pages + the /matches mempool strip)
#   4: NotificationsTest, SeasonChainTest, ClanEditTest, TournamentChooserTest, EngagementTest (~25s + P10), TournamentEditTest, TournamentHonestDurationTest, RulesProtocolTest (P28/P29), AoeLobbyTest (AoE2 lobby cards en and de at 375 and 1440, a shared place 1 reported and confirmed)
#   5: NavigationCrawlTest (the P16 walk for guest, player and captain), LivePlayerTest (P20, the floating player and /live against a local ffmpeg-made HLS stream), CasualPlayTest (P23 S3, queue and invite to the ready prompt and the room), BoardGameTest (board game core next to chess: the fixture game to the end at 390 and 1440), NineMensMorrisTest (P3, nine men's morris to a win at 390 and 1440)
#   6: NavigationMenusTest, NavigationCrawlStaffTest, InvitePlacementTest (P16 menus and context actions; the walk for organizer and admin), GameChannelTest (P21, the game channels with polls), NostrCommentsTest (P48, comments, likes and RSVPs over a local relay), NostrInvitesZapsTest (P47, follows here, invite DMs, zap the winner, NIP-05 names), BoardCorrespondenceTest (Mühle and Dame by correspondence from a challenge, en and de at 390 and 1440), StackerTest (Blockfill: practice and a ranked run fed through the test hook, 375 and 1440)
#   7: TournamentLandingTest, PlayerPickerTest, ShellNavigationWidthsTest (the shell at six widths per role, German at the desktop widths), CasualLobbyCardTest (P23 S2, a lobby card host to guest), FairPlayAdminTest (P41, the admin link flow), CasualCupRegionsTest (EU and US cups side by side), LeagueSettingsAdminTest (P44, change and reset a setting), BoardFollowsTest (your follows in a board game lobby, 320/375/1280 en and de), BlockfillWeekTest (the Blockfill week on /blockfill and scores/blockfill, en and de at 375 and 1440)
#   8: ShellNavigationTest, TournamentTimeTest, BunkerSessionTest, TournamentControlTest (header concept B: hub, context bar, phone sheets, /play; the when block; NIP-46; the P18 control), NavigateRaceTest (a late Livewire answer after wire:navigate is not morphed into the old page), CheckersTest (checkers to a win at 390 and 1440), BoardMiningAdminTest (the board games on the admin season page, en and de at 390 and 1440), BoardFindabilityTest (the board games next to chess on /play and home, en and de at 390 and 1440)
#   9: TournamentTvTest (P19, the TV live at 1080p and 4K; its soak test runs only with TV_SOAK), HomeHubTest (home as the engagement hub), LiveChatTest (P24, the stream chat on /live over the mini relay), MeHubTest (P30, the own page), SettingsTabsTest (P51, the settings tabs and the gamer tag page), BoardLeagueTest (board game lobbies to the board, en and de at 390 and 1440)
# Measured 2026-09-27, every shard in parallel: origin/master (7 shards) ran
# 69-82 s in shards 1-4 already; a 10-shard split only raised the host load
# (37 on 24 cores) and with it every shard. The crawl of five roles took 71 s
# alone (54 s before header concept B), so it is split by role.
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
# LOCKING. Concurrent sessions in this fleet serialise browser runs with
#   flock /tmp/claude-<uid>/esports-browser.lock bash scripts/test-browser.sh [args]
# because two Reverb/Playwright/LaravelHttpServer stacks racing for the same
# build output would flake. That convention has a sharp edge: plain
# `flock FILE cmd` (no -o) leaves the lock's file descriptor OPEN in `cmd`
# and everything `cmd` forks — including, several layers down, the
# `playwright run-server` node process Pest spawns per run. flock(2) locks
# belong to the *open file description*, not the process that first opened
# it, so if the supervising chain (flock -> this script -> php artisan pest)
# is killed or times out while that grandchild is still alive, the
# grandchild survives as an orphan (reparented to the user's systemd
# instance, per `ps`), keeps that inherited fd open, and the lock never
# releases — every later `flock` call queues behind a lock nobody is still
# using. Measured 2026-09-27 in this file's own worktree: SIGTERM to the
# `flock ... bash scripts/test-browser.sh <file>` top process while its
# `playwright run-server` grandchild was live left that grandchild running
# with `/proc/<pid>/fd/3 -> .../esports-browser.lock` still open, and the
# very next `flock -n` on that file failed immediately afterwards, even
# though every process in the original supervising chain was already gone.
#
# Fix, in three parts:
#   1. This script takes its OWN lock with `flock -o` (close-on-exec before
#      running the wrapped command), so the lock fd never propagates past
#      this script's own top-level process — no matter what a grandchild
#      does or how it dies, it never held a copy to leak. Callers can now
#      just run `scripts/test-browser.sh` directly, no external flock
#      needed. Callers who still wrap it in the old
#      `flock LOCKFILE bash scripts/test-browser.sh` form keep working
#      without a double-lock deadlock: this script detects an
#      already-inherited fd on the same lock file (by device:inode, via
#      /proc/$$/fd) and skips taking its own lock when it finds one.
#   2. Both run_single and run_shard start their `pest` invocation with
#      `setsid`, giving it (and everything it forks, including the
#      playwright run-server several layers down) its own process group,
#      and trap EXIT/INT/TERM to kill that whole group. A kill or timeout of
#      this script now takes any playwright run-server (and Reverb) it
#      started down with it, instead of leaving a fresh orphan behind.
#   3. A stale-orphan sweep at the top additionally clears out orphans left
#      by runs that predate this fix, or that were killed with -9 (no trap
#      catches that): any `playwright run-server` whose cwd is this
#      worktree, whose parent is not a live process (reparented to
#      systemd/init), and that has no established connection right now is
#      killed before a fresh run starts.
#
set -euo pipefail

cd "$(dirname "$0")/.."

# --- 1. lock wrapper -----------------------------------------------------
LOCKFILE="${ESPORTS_BROWSER_LOCK:-${TMPDIR:-/tmp}/claude-$(id -u)/esports-browser.lock}"

# True if an ancestor already holds a lock on this exact file — the old
# `flock LOCKFILE bash scripts/test-browser.sh` convention, which does not
# close its fd before exec, so a copy of it is inherited here. Matched by
# device:inode, not by path, so a symlink or a different literal path to
# the same file is still recognised; a nonexistent lock file (nobody has
# ever locked it yet) correctly reports "not held".
esports_browser_lock_already_held() {
    local target fd cand
    target=$(stat -c '%d:%i' "$LOCKFILE" 2>/dev/null) || return 1
    for fd in /proc/$$/fd/*; do
        [ -e "$fd" ] || continue
        cand=$(stat -L -c '%d:%i' "$fd" 2>/dev/null) || continue
        [ "$cand" = "$target" ] && return 0
    done
    return 1
}

if [ -z "${ESPORTS_BROWSER_LOCK_TAKEN:-}" ] && ! esports_browser_lock_already_held; then
    mkdir -p "$(dirname "$LOCKFILE")"
    export ESPORTS_BROWSER_LOCK_TAKEN=1
    exec flock -o "$LOCKFILE" "$0" "$@"
fi
# From here on the lock is held either by an ancestor (old-style caller) or
# by the `flock -o` above wrapping this exact process (self-wrapped) — and
# in the self-wrapped case, without a copy of its fd for anything below
# this process to leak.

# --- 3. stale-orphan sweep ------------------------------------------------
# Clears `playwright run-server` processes left behind by a run of THIS
# worktree that ended before the trap in run_single/run_shard existed, or
# that was killed with -9 (no trap can catch that). Scoped to this
# worktree's cwd, and only touches a process that is (a) reparented — its
# parent is not a live test-runner process, it shows up under systemd/init —
# and (b) has no established TCP connection right now.
esports_sweep_stale_playwright_servers() {
    local here pid ppid pcomm
    here=$(pwd -P)
    for pid in $(pgrep -f 'playwright run-server' 2>/dev/null || true); do
        [ "$(readlink -f "/proc/$pid/cwd" 2>/dev/null)" = "$here" ] || continue
        ppid=$(ps -o ppid= -p "$pid" 2>/dev/null | tr -d ' ')
        [ -n "$ppid" ] || continue
        pcomm=$(ps -o comm= -p "$ppid" 2>/dev/null)
        case "$pcomm" in
            systemd | init) : ;;
            *) continue ;; # has a live, recognisable parent -- not an orphan
        esac
        if ss -tnp 2>/dev/null | grep -q "pid=$pid,"; then
            continue # still has a live connection, leave it alone
        fi
        echo "test-browser: sweeping orphaned playwright run-server pid=$pid (parent reparented to $pcomm, no live connection)" >&2
        kill -TERM "$pid" 2>/dev/null || true
    done
}
esports_sweep_stale_playwright_servers

./scripts/link-host-chromium.sh

npm run build

SHARD_FILES=(
    "tests/Browser/RouteSweepTest.php tests/Browser/NotificationDmPagesTest.php tests/Browser/LiveCountTest.php tests/Browser/ClanPrideTest.php"
    "tests/Browser/BlitzGameTest.php tests/Browser/ClanRosterTest.php tests/Browser/LoginTest.php tests/Browser/ClanLogoTest.php tests/Browser/ShareTest.php tests/Browser/StrongestListTest.php tests/Browser/VisualPassTest.php"
    "tests/Browser/ChatAndDailyTest.php tests/Browser/ChessCorrespondenceQuietTest.php tests/Browser/SeriesResultTest.php tests/Browser/OpponentRatedTest.php tests/Browser/OpponentRequestsTest.php tests/Browser/TournamentFlowTest.php tests/Browser/LadderDefaultTest.php tests/Browser/GameCoversTest.php tests/Browser/GamePageTest.php tests/Browser/MempoolStripTest.php"
    "tests/Browser/NotificationsTest.php tests/Browser/SeasonChainTest.php tests/Browser/ClanEditTest.php tests/Browser/TournamentChooserTest.php tests/Browser/EngagementTest.php tests/Browser/TournamentEditTest.php tests/Browser/TournamentHonestDurationTest.php tests/Browser/RulesProtocolTest.php tests/Browser/AoeLobbyTest.php"
    "tests/Browser/NavigationCrawlTest.php tests/Browser/LivePlayerTest.php tests/Browser/CasualPlayTest.php tests/Browser/BoardGameTest.php tests/Browser/NineMensMorrisTest.php"
    "tests/Browser/NavigationMenusTest.php tests/Browser/NavigationCrawlStaffTest.php tests/Browser/InvitePlacementTest.php tests/Browser/ChessLobbyTest.php tests/Browser/GameChannelTest.php tests/Browser/NostrCommentsTest.php tests/Browser/NostrInvitesZapsTest.php tests/Browser/BoardCorrespondenceTest.php tests/Browser/StackerTest.php"
    "tests/Browser/TournamentLandingTest.php tests/Browser/PlayerPickerTest.php tests/Browser/ShellNavigationWidthsTest.php tests/Browser/CasualLobbyCardTest.php tests/Browser/PlayerStatsTest.php tests/Browser/FairPlayAdminTest.php tests/Browser/CasualCupRegionsTest.php tests/Browser/LeagueSettingsAdminTest.php tests/Browser/BoardFollowsTest.php tests/Browser/BlockfillWeekTest.php"
    "tests/Browser/ShellNavigationTest.php tests/Browser/TournamentTimeTest.php tests/Browser/BunkerSessionTest.php tests/Browser/TournamentControlTest.php tests/Browser/NavigateRaceTest.php tests/Browser/CheckersTest.php tests/Browser/BoardMiningAdminTest.php tests/Browser/BoardFindabilityTest.php"
    "tests/Browser/TournamentTvTest.php tests/Browser/HomeHubTest.php tests/Browser/LiveChatTest.php tests/Browser/MeHubTest.php tests/Browser/SettingsTabsTest.php tests/Browser/NostrBarTest.php tests/Browser/BoardLeagueTest.php"
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

    # setsid makes the pest process its own process group leader (its PID
    # becomes the group's PID too), so everything it forks -- including the
    # playwright run-server several layers down -- lives in that group.
    # Killing the group, not just this one pid, is what keeps a kill or
    # timeout of this script from leaving that grandchild behind as an
    # orphan. Not `local`: the trap below runs when this function's process
    # actually exits (normal completion or a caught signal), which is after
    # the function would otherwise return, so a `local` would already be
    # out of scope by then -- see the identical note on `reverb_pid` in
    # run_shard.
    setsid vendor/bin/pest --group=browser --no-tia "$@" &
    pest_pid=$!

    cleanup() {
        local ec=$?
        trap - EXIT INT TERM
        kill -TERM -- -"$pest_pid" 2>/dev/null || true
        kill "$REVERB_PID" 2>/dev/null || true
        exit "$ec"
    }
    trap cleanup EXIT INT TERM

    status=0
    wait "$pest_pid" || status=$?
    return "$status"
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

    # Same reasoning as run_single: setsid puts pest (and everything it
    # forks, including a playwright run-server) in its own process group,
    # so this shard's cleanup trap can kill that whole group instead of
    # leaving a grandchild behind as an orphan if this shard is killed or
    # times out. Not `local`, for the same reason as `reverb_pid` above.
    setsid vendor/bin/pest --group=browser --no-tia "${files[@]}" &
    pest_pid=$!

    cleanup() {
        local ec=$?
        trap - EXIT INT TERM
        kill -TERM -- -"$pest_pid" 2>/dev/null || true
        kill "$reverb_pid" 2>/dev/null || true
        exit "$ec"
    }
    trap cleanup EXIT INT TERM

    status=0
    wait "$pest_pid" || status=$?
    return "$status"
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
