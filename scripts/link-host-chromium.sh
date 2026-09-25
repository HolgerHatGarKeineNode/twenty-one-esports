#!/usr/bin/env bash
#
# Point pest-plugin-browser's Playwright at the Chromium already installed on this
# host, instead of letting it download its own copy. Idempotent.
#
# The Browser suite runs ON THE HOST, not in the container — that is what makes this
# possible, and it is the reason phpunit.xml keeps Browser out of the default run.
# Everything else about this project runs in Sail; the browser tests are the one
# thing that does not, because the container has no Chromium that Playwright would
# accept and the host has one already.
#
# Playwright resolves a browser by joining PLAYWRIGHT_BROWSERS_PATH with a directory
# named "{name}-{revision}" from node_modules/playwright-core/browsers.json and a
# platform path, then only checks that the file exists. So a symlink registry is
# enough. There is no executable-path option to set: Pest sends a fixed launch
# options object with no executablePath and no channel.
#
# Both variants are needed. Pest launches headless by default, which resolves to
# chromium-headless-shell, so wiring only plain chromium still fails with
# "Executable doesn't exist".
#
# The revision moves with playwright-core, so it is read from browsers.json rather
# than hardcoded.
#
set -euo pipefail

cd "$(dirname "$0")/.."

BROWSERS_JSON=node_modules/playwright-core/browsers.json

if [ ! -f "$BROWSERS_JSON" ]; then
    echo "link-host-chromium: $BROWSERS_JSON is missing — run yarn install first." >&2
    exit 1
fi

CHROMIUM_BIN=$(command -v chromium || command -v chromium-browser || command -v google-chrome-stable || true)

if [ -z "$CHROMIUM_BIN" ]; then
    echo "link-host-chromium: no Chromium found on this host. Install one (Arch: pacman -S chromium)." >&2
    exit 1
fi

REV=$(php -r '
    $d = json_decode(file_get_contents($argv[1]), true);
    foreach ($d["browsers"] as $b) {
        if ($b["name"] === "chromium") { echo $b["revision"]; return; }
    }
    exit(1);
' "$BROWSERS_JSON")

ROOT=${PLAYWRIGHT_BROWSERS_PATH:-$HOME/.cache/ms-playwright}

# UNWRAP THE DISTRO LAUNCHER. On Fedora (and Debian, and openSUSE) `chromium` on $PATH is not
# the browser but a bash wrapper beside it. Fedora's `chromium-browser.sh` sources
# /etc/chromium/chromium.conf and then appends
#
#     --enable-plugins --enable-extensions --enable-user-scripts --enable-printing
#     --enable-sync --auto-ssl-client-auth
#
# to EVERY launch, and rewires std{in,out,err} through `cat` process substitutions on the way.
# None of that belongs in a browser a test suite is driving: extensions and sync are state the
# measurement did not ask for, and the stdio juggling puts two extra processes between
# Playwright and the browser it thinks it owns.
#
# Playwright never sees this, because it only checks that the path it resolved exists. So the
# check has to happen here: if the resolved path is not an ELF executable, look for the real
# binary in the same directory and use that instead.
unwrap() {
    local candidate real
    candidate=$(readlink -f "$1")

    if head -c 4 "$candidate" 2>/dev/null | grep -q $'\x7fELF'; then
        printf '%s' "$candidate"
        return
    fi

    # `chromium-browser.sh` -> `chromium-browser`, `chromium.sh` -> `chromium`, and the plain
    # basename of the directory as a last resort (that is how Fedora lays it out).
    for real in "${candidate%.sh}" "$(dirname "$candidate")/$(basename "$(dirname "$candidate")")"; do
        if [ -x "$real" ] && head -c 4 "$real" 2>/dev/null | grep -q $'\x7fELF'; then
            printf '%s' "$real"
            return
        fi
    done

    # No binary found next to the wrapper: use the wrapper rather than fail. It launches, and a
    # noisy launch beats no launch at all.
    printf '%s' "$candidate"
}

REAL_BIN=$(unwrap "$CHROMIUM_BIN")

# FLAGS THIS HOST NEEDS, AND ONLY THIS HOST. A virtual machine has no GPU. Without
# `--disable-gpu` Chromium still starts a GPU process, falls back to SwiftShader and takes the
# renderer down with it: measured 2026-09-11 in a Qubes AppVM, where
# `tests/Browser/Dashboard/DarkWorldRoutesTest.php` died with a bare `Page crashed` at the
# fourteenth route, and stopped doing so the moment these flags were in place. On bare metal the
# flags would throw away real hardware acceleration, so the detection decides — the same call
# Fedora's own chromium.conf makes for the same reason.
LAUNCH_FLAGS=""

if [ "$(systemd-detect-virt 2>/dev/null || echo none)" != "none" ]; then
    LAUNCH_FLAGS="--disable-gpu --disable-software-rasterizer --disable-dev-shm-usage"
fi

# A SHIM, NOT A SYMLINK, whenever flags are involved: Pest sends Playwright a fixed launch
# options object with no `args` and no `executablePath`, so there is no other place to put them.
# Where no flags are needed this still writes a shim — one shape to reason about, and `exec`
# means no extra process survives it either way.
link() {
    local dir="$ROOT/$1-$REV/$2"
    local target="$dir/$3"

    mkdir -p "$dir"

    # `rm -f` first: `target` may be a symlink from an earlier version of this script, and a
    # redirect would follow it and try to write the browser binary itself (root-owned, and the
    # attempt fails with a bare "permission denied" that names the wrong file).
    rm -f "$target"

    cat > "$target" <<SHIM
#!/usr/bin/env bash
# Generated by scripts/link-host-chromium.sh — do not edit, it is rewritten on every run.
exec "$REAL_BIN" $LAUNCH_FLAGS "\$@"
SHIM

    chmod +x "$target"
    touch "$ROOT/$1-$REV/INSTALLATION_COMPLETE"
}

link chromium chrome-linux64 chrome
link chromium_headless_shell chrome-headless-shell-linux64 chrome-headless-shell

echo "link-host-chromium: $ROOT/{chromium,chromium_headless_shell}-$REV -> $REAL_BIN ($("$REAL_BIN" --version 2>/dev/null | head -1))"

if [ "$REAL_BIN" != "$(readlink -f "$CHROMIUM_BIN")" ]; then
    echo "link-host-chromium: unwrapped the distro launcher at $CHROMIUM_BIN"
fi

if [ -n "$LAUNCH_FLAGS" ]; then
    echo "link-host-chromium: virtualised host ($(systemd-detect-virt)), launching with $LAUNCH_FLAGS"
fi
