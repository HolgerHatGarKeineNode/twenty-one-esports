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
# Reverb: a throwaway Reverb server is started here, on a free port with
# throwaway app credentials, and stopped when the run ends. The same values
# are exported to the test process, so the app broadcasts to it and the pages
# (which read the Reverb settings at runtime, partials/head.blade.php)
# connect to it. Exported variables win over phpunit.xml's non-forced <env>
# entries, which is how BROADCAST_CONNECTION=reverb reaches the tests.
#
set -euo pipefail

cd "$(dirname "$0")/.."

./scripts/link-host-chromium.sh

npm run build

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
