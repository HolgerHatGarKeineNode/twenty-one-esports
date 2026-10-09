#!/usr/bin/env bash
# The pictures of the Hyperbitcoinization stream slide (h1) into resources/stream/hyper, small enough to sit in
# every frame as data URIs: the generated plate, header ribbon and clash icon (manifest-stream.json, gen.py's
# output dir as the argument) and the faction portraits of the game page (public/hyper/art/por-*.jpg).
# Usage: stream-art.sh <generator output dir>
set -u
G=${1:?generator output dir}
R=$(cd "$(dirname "$0")/../.." && pwd)
O="$R/resources/stream/hyper"; mkdir -p "$O"
magick "$G/stream-plate.png" -strip -resize 1280x720^ -gravity center -extent 1280x720 -quality 62 "$O/plate.jpg"
for n in stream-ribbon stream-clash; do
  magick "$G/$n.png" -fuzz 22% -transparent '#ff00ff' -channel A -morphology Erode Disk:1.2 -blur 0x0.6 +channel -trim +repage -strip \
    -resize "$([ $n = stream-clash ] && echo 128x128 || echo 640x)" -colors 160 PNG32:"$O/${n#stream-}.png"
done
# Faction => portrait (resources/js/hyper/data.js FACTIONS por).
for pair in bitcoiner:you fed:fed ezb:ezb goldbug:goldbug shitcoiner:shit nocoiner:no; do
  magick "$R/public/hyper/art/por-${pair#*:}.jpg" -strip -resize 112x112 -quality 78 "$O/por-${pair%%:*}.jpg"
done
ls -la "$O"
