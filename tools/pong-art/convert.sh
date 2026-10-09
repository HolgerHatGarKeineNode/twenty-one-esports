#!/usr/bin/env bash
# Proof of Pong's web art (plan "Proof of Pong", P3) from the generator's PNGs (tools/hyper-art/gen.py output dirs).
# Usage: convert.sh <dir> [<override dir> ...]: a later dir's file wins over an earlier one of the same name.
# Portraits and victory poses are keyed off their magenta background (tools/hyper-art/key.py), arenas and the
# title art stay plates. Writes public/pong/art and prints the file count and total size.
set -u
R=$(cd "$(dirname "$0")/../.." && pwd); A=$R/public/pong/art; K=$R/tools/hyper-art/key.py
mkdir -p "$A"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
for d in "$@"; do for f in "$d"/*.png; do [ -f "$f" ] && ln -sf "$(realpath "$f")" "$T/$(basename "$f")"; done; done
rm -f "$T"/por-nocoiner.table.png
key() { python3 "$K" "$1" "$T/k.webp" && magick "$T/k.webp" -resize "$2" -quality "$3" "$4"; }
for f in "$T"/por-*.png; do key "$f" '320x320>' 84 "$A/$(basename "${f%.png}").webp"; done
for f in "$T"/win-*.png; do key "$f" '720x720>' 82 "$A/$(basename "${f%.png}").webp"; done
for f in "$T"/ev-*.png; do key "$f" '320x320>' 86 "$A/$(basename "${f%.png}").webp"; done
for f in "$T"/arena-*.png; do magick "$f" -strip -resize '1920x1080>' -quality 78 "$A/$(basename "${f%.png}").webp"; done
magick "$T/key-title.png" -strip -resize '1600x900>' -quality 80 "$A/key-title.webp"
ls "$A" | wc -l; du -sh "$A" | cut -f1
