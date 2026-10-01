#!/usr/bin/env bash
#
# The push gate: runs what the diff reaches, not the whole suite.
#
#   scripts/test-changed.sh [--base REF] [--head REF] [--wide] [--no-browser] [--tia] [--explain] [--dry-run]
#   scripts/test-changed.sh --daily
#
# EVERY PUSH (no flag): against `git diff <base>...HEAD` (default base:
# origin/master, plus uncommitted and untracked files),
#   1. pint --test and phpstan on the changed PHP files,
#   2. the default-suite test files the diff reaches, in parallel
#      (scripts/changed-tests.php --suite=default),
#   3. the Browser test files the diff reaches (scripts/test-browser.sh
#      --changed): sharded exactly like the full run, only the shards that hold
#      an affected file, each with only those files.
# A diff that touches config, migrations, composer files, phpunit.xml,
# tests/Pest.php or something too wide to follow (a layout, a base model) makes
# step 2 or 3 run its whole suite; a diff that reaches no test runs none. See
# scripts/changed-tests.php for the map and its limits: it is static, a heuristic.
#
# ONCE A DAY: the full default suite (`vendor/bin/pest --parallel`) and the
# full Browser run (`scripts/test-browser.sh`), because a heuristic needs a net.
# TRIGGER: `scripts/test-changed.sh --daily`, run by the first session of the
# day, a cron entry or a systemd timer, e.g.
#     30 6 * * *  cd <repo> && scripts/test-changed.sh --daily
# and before every release batch. A green --daily stamps
# ${TMPDIR:-/tmp}/claude-<uid>/esports-daily.stamp; the per-push gate prints a
# warning (it never blocks) when that stamp is older than 26 hours.
#
# --tia   use Pest TIA for step 2 instead of the path map. Needs a recorded graph
#         for this checkout (the first run records one, a full coverage run) and
#         does not look at --base.
# --head  look at another commit instead of HEAD (a past commit as a sample diff);
#         the working tree is then not part of the diff.
# --wide  follow every changed file up through the views, routes and classes that use it
#         (the default picks the tests that name the file or quote its diff, and only
#         follows a file nobody names). More tests, fewer holes.
# --explain       say on stderr why each test was picked.
# --dry-run       print the plan (tests and browser files), run nothing.
# --no-browser    skip step 3.
#
# Exit code: 0 only if every step ran green.
set -euo pipefail

cd "$(dirname "$0")/.."

base=""
head="HEAD"
explain=""
wide=""
dry=""
tia=""
browser=1
daily=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        --base) base="$2"; shift 2 ;;
        --head) head="$2"; shift 2 ;;
        --explain) explain="--explain"; shift ;;
        --wide) wide="--wide"; shift ;;
        --dry-run) dry=1; shift ;;
        --tia) tia=1; shift ;;
        --no-browser) browser=""; shift ;;
        --daily) daily=1; shift ;;
        *) echo "test-changed: unknown option $1" >&2; exit 2 ;;
    esac
done

if [ -z "$base" ]; then
    if git rev-parse --verify -q origin/master >/dev/null; then base=origin/master; else base=master; fi
fi

stamp_dir="${TMPDIR:-/tmp}/claude-$(id -u)"
stamp="$stamp_dir/esports-daily.stamp"

if [ -n "$daily" ]; then
    echo "== test-changed --daily: full default suite"
    vendor/bin/pest --parallel
    echo "== test-changed --daily: full Browser run"
    bash scripts/test-browser.sh
    mkdir -p "$stamp_dir"
    date +%s >"$stamp"
    echo "== test-changed --daily: green, stamped $stamp"
    exit 0
fi

if [ -f "$stamp" ]; then
    age=$(( $(date +%s) - $(cat "$stamp") ))
    if [ "$age" -gt 93600 ]; then
        echo "test-changed: WARNING the last green full run is $(( age / 3600 )) h old; run scripts/test-changed.sh --daily" >&2
    fi
else
    echo "test-changed: WARNING no green full run on record; run scripts/test-changed.sh --daily" >&2
fi

# --- 1. style and types on the changed PHP files -----------------------------------
changed_php=$(
    {
        git diff --name-only --diff-filter=ACMR "$base"..."$head"
        if [ "$head" = "HEAD" ]; then
            git diff --name-only --diff-filter=ACMR HEAD
            git ls-files --others --exclude-standard
        fi
    } | grep -E '\.php$' | grep -vE '\.blade\.php$' | sort -u || true
)
existing_php=""
for f in $changed_php; do
    [ -f "$f" ] && existing_php="$existing_php $f"
done
analysable=""
for f in $existing_php; do
    case "$f" in
        app/* | bootstrap/app.php | config/* | database/* | routes/*) analysable="$analysable $f" ;;
    esac
done

# --- 2. the default suite ----------------------------------------------------------
selection=$(php scripts/changed-tests.php --suite=default --base "$base" --head "$head" $explain $wide || true)

if [ -n "$dry" ]; then
    echo "== plan: pint --test on:${existing_php:- (none)}"
    echo "== plan: phpstan on:${analysable:- (none)}"
    if [ -z "$selection" ]; then echo "== plan: default suite: no test reached";
    elif [ "$selection" = "ALL" ]; then echo "== plan: default suite: ALL";
    else echo "== plan: default suite: $(echo "$selection" | wc -l) files"; echo "$selection"; fi
    if [ -n "$browser" ]; then
        echo "== plan: browser:"
        bash scripts/test-browser.sh --changed --base "$base" --head "$head" $wide --dry-run
    fi
    exit 0
fi

if [ -n "$existing_php" ]; then
    echo "== pint --test"
    # shellcheck disable=SC2086
    vendor/bin/pint --test $existing_php
fi
if [ -n "$analysable" ]; then
    echo "== phpstan"
    # shellcheck disable=SC2086
    vendor/bin/phpstan analyse --no-progress --memory-limit=1G $analysable
fi

echo "== default suite"
if [ -n "$tia" ]; then
    vendor/bin/pest --parallel --tia
elif [ -z "$selection" ]; then
    echo "no default test is reached by this diff"
elif [ "$selection" = "ALL" ]; then
    vendor/bin/pest --parallel
else
    # Pest takes a single path, so the files go in as one --filter on the class names
    # (tests/Feature/Foo/BarTest.php -> Tests.Feature.Foo.BarTest::).
    pattern=$(echo "$selection" | sed -E 's#^tests/##; s#\.php$##; s#/#.#g; s#^#Tests.#; s#$#::#' | paste -sd'|' -)
    count=$(echo "$selection" | wc -l)
    echo "$count test files"
    if [ "$count" -le 2 ]; then
        vendor/bin/pest --filter="($pattern)"
    else
        vendor/bin/pest --parallel --filter="($pattern)"
    fi
fi

# --- 3. the Browser suite ----------------------------------------------------------
if [ -n "$browser" ]; then
    echo "== browser suite (changed files)"
    bash scripts/test-browser.sh --changed --base "$base" --head "$head" $wide
fi
