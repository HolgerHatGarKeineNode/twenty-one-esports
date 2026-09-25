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
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/link-host-chromium.sh

npm run build

exec vendor/bin/pest --group=browser --no-tia "$@"
