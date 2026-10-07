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
# THE GATE. On every push the default suite runs the test files the diff reaches
# and this script runs `--changed`: only the browser files the diff reaches
# (scripts/changed-tests.php), in the same shards as the full run, only the ones
# that hold such a file. The FULL run (no arguments) is for the daily run and the
# release batch: `scripts/test-changed.sh --daily`, which also stamps the day
# (see the header of scripts/test-changed.sh for the trigger).
#   scripts/test-browser.sh                       the whole suite, sharded
#   scripts/test-browser.sh --changed             the files the diff to origin/master reaches
#   scripts/test-browser.sh --changed --dry-run   say which, run nothing
#   scripts/test-browser.sh <pest args>           one unsharded pest process, as before
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
#   - its own fake storage disks: TEST_TOKEN=browser-shard-<n> makes
#     Storage::fake() use storage/framework/testing/disks/<disk>_test_<token>
#     instead of one directory every shard would empty on its own beforeEach
#     (see run_shard).
#   - its own Playwright/Chromium launch and its own LaravelHttpServer port,
#     both already OS-assigned per *process* by the plugin itself
#     (Pest\Browser\Support\Port::find() asks the kernel for a free port);
#     running several of these processes at once needs nothing extra here.
# Ports are never hand-picked or precomputed: each shard's Reverb port comes
# from the kernel (stream_socket_server on port 0) right before that shard
# starts, so two shards can never collide even if they start in the same
# tick.
#
# The shards are balanced by measured seconds per test file (longest first,
# each into the lightest shard), not by topic: the timings come from
# TEST_PROFILE_FILE (tests/Support/TestProfile.php) and scripts/test-profile.php
# over one full run. Measured 2026-10-01 on this workstation (24 cores, 3.5
# cores busy on average): 9 hand-made shards took 447 s, the slowest one 442 s
# while the fastest was done after 100; ONE process is faster than its test
# seconds suggest because a browser test mostly waits for the page, so more
# shards of equal length is what shortens the run. Re-balance when a file is
# added or a run shows one shard far behind (the profile prints when each
# worker finished).
#
# A file that is longer than a shard's share is split by test name: an entry
# `file#=regex` runs only the tests of `file` whose description matches (Pest
# matches the words of the description, spaces and all; `~` stands for a space
# in the entry), `file#!regex` runs all the others (the complement is a
# negative lookahead, so a test added later lands in the `!` half instead of
# being dropped). Both halves must be
# present, the check after the array fails loudly otherwise. A split entry is
# its own `pest` process, started after the plain files of its shard.
#
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

# --- 0. --changed [--base REF] [--head REF] [--wide] [--dry-run] ---------------------------------
# Only the browser files the diff reaches (scripts/changed-tests.php), sharded
# like the full run: a shard that holds none of them is not started. Decided
# before the lock and the build, so a diff that reaches nothing costs
# milliseconds. `ALL` from the map (a layout, a config file, CSS ...) means the
# full run.
orig_args=("$@")
selection=""
if [ "${1:-}" = "--changed" ]; then
    shift
    changed_base=""
    changed_head="HEAD"
    changed_wide=""
    dry_run=""
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --base) changed_base="$2"; shift 2 ;;
            --head) changed_head="$2"; shift 2 ;;
            --wide) changed_wide="--wide"; shift ;;
            --dry-run) dry_run=1; shift ;;
            *) echo "test-browser: unknown option $1 after --changed" >&2; exit 2 ;;
        esac
    done
    if [ -n "$changed_base" ]; then
        selection=$(php scripts/changed-tests.php --suite=browser --base "$changed_base" --head "$changed_head" $changed_wide)
    else
        selection=$(php scripts/changed-tests.php --suite=browser --head "$changed_head" $changed_wide)
    fi
    if [ -z "$selection" ]; then
        echo "test-browser: no browser test is reached by this diff"
        exit 0
    fi
    if [ -n "$dry_run" ]; then
        echo "$selection"
        exit 0
    fi
    [ "$selection" = "ALL" ] && selection=""
    set -- # nothing is left for the single-process path below
fi

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
    exec flock -o "$LOCKFILE" "$0" ${orig_args[@]+"${orig_args[@]}"}
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
    "tests/Browser/BoardLeagueTest.php#!two~players~meet|a~guest~watching"
    "tests/Browser/LivePlayerTest.php#=badge~fits~the~shell~at~320 tests/Browser/BoardCorrespondenceTest.php tests/Browser/NavigateRaceTest.php tests/Browser/NavigateSpikeTest.php tests/Browser/NavigateDeploySkewTest.php tests/Browser/LadderDefaultTest.php tests/Browser/CasualLobbyCardTest.php tests/Browser/ClanRosterTest.php"
    "tests/Browser/LiveChatTest.php tests/Browser/BunkerSessionTest.php tests/Browser/GameChannelTest.php tests/Browser/NineMensMorrisTest.php tests/Browser/MeHubTest.php tests/Browser/LivewireTrafficTest.php"
    "tests/Browser/AoeLobbyTest.php tests/Browser/BlitzGameTest.php tests/Browser/EngagementTest.php tests/Browser/ClanPrideTest.php tests/Browser/GamePageTest.php tests/Browser/TournamentChooserTest.php tests/Browser/ClanApplicationsTest.php tests/Browser/RocketLeagueStartTest.php"
    "tests/Browser/LivePlayerTest.php#!badge~fits~the~shell~at~320 tests/Browser/NostrInvitesZapsTest.php tests/Browser/BlockfillShellTest.php tests/Browser/GameCoversTest.php tests/Browser/OpponentRatedTest.php tests/Browser/PlayerStatsTest.php"
    "tests/Browser/NotificationDmPagesTest.php tests/Browser/NavigationMenusTest.php tests/Browser/CheckersTest.php tests/Browser/CasualPlayTest.php tests/Browser/BoardGameTest.php tests/Browser/RulesProtocolTest.php"
    "tests/Browser/RouteSweepTest.php tests/Browser/NostrCommentsTest.php tests/Browser/VisualPassTest.php tests/Browser/PlayerPickerTest.php tests/Browser/LoginTest.php tests/Browser/AccountMenuTest.php tests/Browser/UpcomingEventsTest.php"
    "tests/Browser/ShellNavigationTest.php tests/Browser/TournamentFlowTest.php tests/Browser/TournamentLandingTest.php tests/Browser/BoardLeagueTest.php#=two~players~meet|a~guest~watching tests/Browser/TournamentTimeTest.php tests/Browser/FairPlayAdminTest.php"
    "tests/Browser/SeasonChainTest.php tests/Browser/ChatAndDailyTest.php tests/Browser/StackerSoundTest.php tests/Browser/ChessCorrespondenceQuietTest.php tests/Browser/ClanEditTest.php tests/Browser/TournamentHonestDurationTest.php tests/Browser/CupFindabilityTest.php"
    "tests/Browser/ShellNavigationWidthsTest.php tests/Browser/NostrBarTest.php tests/Browser/SettingsTabsTest.php tests/Browser/ChessLobbyTest.php tests/Browser/ChessRapidLobbyTest.php tests/Browser/OpponentRequestsTest.php tests/Browser/LeagueSettingsAdminTest.php tests/Browser/LeagueWeeksAdminTest.php"
    "tests/Browser/ShareTest.php tests/Browser/TournamentTvTest.php tests/Browser/BoardFindabilityTest.php tests/Browser/InvitePlacementTest.php tests/Browser/TournamentEditTest.php tests/Browser/BlockfillReplayTest.php tests/Browser/BlockfillShareTest.php tests/Browser/ScoreMiningAdminTest.php"
    "tests/Browser/LiveCountTest.php tests/Browser/StackerTest.php tests/Browser/BoardMiningAdminTest.php tests/Browser/StrongestListTest.php tests/Browser/MempoolStripTest.php tests/Browser/InviteContextTest.php tests/Browser/TournamentGameBannerTest.php tests/Browser/ChampionMomentTest.php"
    "tests/Browser/NavigationCrawlStaffTest.php tests/Browser/BodylessResponseFramingTest.php tests/Browser/BoardFollowsTest.php tests/Browser/TournamentControlTest.php tests/Browser/TournamentLiveTest.php tests/Browser/BlockfillWeekTest.php tests/Browser/ClanLogoTest.php tests/Browser/ClanMeetupMapTest.php tests/Browser/TmnfWeekTest.php"
    "tests/Browser/NavigationCrawlTest.php tests/Browser/CasualCupRegionsTest.php tests/Browser/HomeHubTest.php tests/Browser/NotificationsTest.php tests/Browser/SeriesResultTest.php tests/Browser/ShellStickyHeaderTest.php tests/Browser/TournamentGameEndTest.php tests/Browser/TournamentNowTest.php tests/Browser/GameEndBoardVisibleTest.php tests/Browser/TournamentDeskTest.php"
    "tests/Browser/BlockliTest.php tests/Browser/BoardGamesCorrespondenceOnlyTest.php tests/Browser/MatchRoomFlowTest.php tests/Browser/PlayerPrideTest.php tests/Browser/RenderScopeTest.php tests/Browser/UiTogglesTrafficTest.php tests/Browser/TeamLineupTest.php tests/Browser/TeamMatchLiveTest.php tests/Browser/TeamMatchSurfacesTest.php"
)

# Guard against a new tests/Browser/*Test.php file that nobody assigned to a
# shard: without this, it would just never run, and no exit code would say so.
assigned=$(printf '%s\n' "${SHARD_FILES[@]}" | tr ' ' '\n' | sed 's/#.*//' | sort -u)
present=$(cd tests/Browser && ls -- *.php 2>/dev/null | sed 's#^#tests/Browser/#' | sort)
if [ "$assigned" != "$present" ]; then
    echo "test-browser: SHARD_FILES in $0 does not match tests/Browser/*.php." >&2
    echo "--- assigned ---" >&2
    echo "$assigned" >&2
    echo "--- present ---" >&2
    echo "$present" >&2
    exit 1
fi
# A split file needs both halves: `file#=regex` and `file#!regex`.
unpaired=$(printf '%s\n' "${SHARD_FILES[@]}" | tr ' ' '\n' | grep '#' | sed -E 's/#[=!]/ /' | sort | uniq -c | awk '$1 != 2' || true)
if [ -n "$unpaired" ]; then
    echo "test-browser: a split entry in SHARD_FILES has no matching other half:" >&2
    echo "$unpaired" >&2
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

# One pest process in its own process group (see the note in run_shard); sets
# pest_pid for the cleanup trap and returns pest's exit status.
run_pest() {
    setsid vendor/bin/pest --group=browser --no-tia "$@" &
    pest_pid=$!
    local st=0
    wait "$pest_pid" || st=$?
    return "$st"
}

run_shard() {
    local idx=$1
    shift
    local -a entries=("$@")

    local port
    port=$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1];')

    export BROADCAST_CONNECTION=reverb
    export REVERB_APP_ID="browser-tests-$idx"
    export REVERB_APP_KEY="browser-tests-$idx-$(php -r 'echo bin2hex(random_bytes(6));')"
    export REVERB_APP_SECRET="$(php -r 'echo bin2hex(random_bytes(16));')"
    export REVERB_HOST=127.0.0.1
    export REVERB_PORT="$port"
    export REVERB_SCHEME=http

    # The one piece of state the shards DO share is the filesystem, and
    # Storage::fake() is where it bit: it cleans a fixed directory,
    # storage/framework/testing/disks/<disk>, so a shard's beforeEach
    # (ShareTest, NostrCommentsTest) emptied the disk another shard (AoeLobbyTest)
    # had just written its lobby end screen to, and the director's fetch of it
    # came back 404 (measured: ShareTest + AoeLobbyTest side by side, 404 at
    # tests/Browser/AoeLobbyTest.php:262). Laravel keys that directory by
    # TEST_TOKEN (Storage::fake: "{$root}_test_{$token}") — the variable paratest
    # sets per worker. Setting it per shard gives every shard its own fake disks
    # and nothing else: the parallel-testing hooks for databases, caches and
    # views only run when LARAVEL_PARALLEL_TESTING is set too, which it is not.
    # Guarded by tests/Feature/BrowserShardIsolationTest.php.
    export TEST_TOKEN="browser-shard-$idx"

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
    pest_pid=""

    cleanup() {
        local ec=$?
        trap - EXIT INT TERM
        [ -n "$pest_pid" ] && kill -TERM -- -"$pest_pid" 2>/dev/null || true
        kill "$reverb_pid" 2>/dev/null || true
        exit "$ec"
    }
    trap cleanup EXIT INT TERM

    # The plain files go into one pest process, each split entry (file#=re,
    # file#!re) into one of its own: --filter applies to everything on its
    # command line.
    local -a plain=() filtered=()
    local entry
    for entry in "${entries[@]}"; do
        case "$entry" in
            *'#'*) filtered+=("$entry") ;;
            *) plain+=("$entry") ;;
        esac
    done

    # A shard that ran nothing must not pass: no files, no verdict.
    if [ "${#plain[@]}" -eq 0 ] && [ "${#filtered[@]}" -eq 0 ]; then
        echo "test-browser: shard $idx has no test files to run" >&2
        return 1
    fi

    status=0
    if [ "${#plain[@]}" -gt 0 ]; then
        run_pest "${plain[@]}" || status=$?
    fi
    for entry in ${filtered[@]+"${filtered[@]}"}; do
        local file=${entry%%#*} spec=${entry#*#}
        local mode=${spec:0:1} regex=${spec:1}
        regex=${regex//\~/ } # entries are space-separated words: `~` stands for a space in the test's description
        if [ "$mode" = "=" ]; then
            run_pest "$file" --filter="$regex" || status=$?
        else
            run_pest "$file" --filter="^(?!.*($regex))" || status=$?
        fi
    done
    return "$status"
}

if [ "$#" -gt 0 ]; then
    run_single "$@"
    exit $?
fi

# --changed: keep only the entries of the affected files, and only the shards
# that still hold one.
if [ -n "$selection" ]; then
    declare -A wanted=()
    for file in $selection; do
        wanted[$file]=1
    done
    kept_shards=()
    for shard in "${SHARD_FILES[@]}"; do
        kept=()
        for entry in $shard; do
            [ -n "${wanted[${entry%%#*}]:-}" ] && kept+=("$entry")
        done
        [ "${#kept[@]}" -gt 0 ] && kept_shards+=("${kept[*]}")
    done
    SHARD_FILES=("${kept_shards[@]}")
    echo "test-browser: --changed: ${#wanted[@]} files in ${#SHARD_FILES[@]} shards"
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
