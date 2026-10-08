#!/usr/bin/env bash
# Web versions of the generated art into hyper/art; cut-outs keyed off their magenta background.
set -u
# Usage: convert.sh <generator output dir> (gen.py's outdir); writes into public/hyper/art.
G=${1:?generator output dir}
A=$(cd "$(dirname "$0")/../.." && pwd)/public/hyper/art; mkdir -p "$A"
conv() { [ -f "$1" ] && magick "$1" -strip $2 "$3"; }
for f in $G/por-*.png; do conv "$f" "-resize 512x512 -quality 86" "$A/$(basename "${f%.png}").jpg"; done
for f in $G/card-*.png; do conv "$f" "-resize 600x800 -quality 84" "$A/$(basename "${f%.png}").jpg"; done
conv $G/key-title.png "-resize 1920x1080 -quality 84" "$A/key-title.jpg"
for t in tex-panel tex-metal; do conv "$G/$t.png" "-resize 512x512 -quality 82" "$A/$t.jpg"; done
magick $G/ui-banner.png -bordercolor black -border 1 -fuzz 7% -fill none -draw "color 0,0 floodfill" -shave 1x1 -trim +repage -resize 1600x -quality 88 "$A/ui-banner.webp"
for f in $G/bd-*.png; do conv "$f" "-resize 2048x -quality 84" "$A/$(basename "${f%.png}").jpg"; done
conv $G/note.png "-resize 512x288! -quality 84" "$A/note.jpg"
# Magenta key: transparent where the colour is near #FF00FF, then shave the halo and despill the edge.
for f in $G/bank-*.png $G/unit-*.png; do
  [ -f "$f" ] || continue
  magick "$f" -fuzz 22% -transparent '#ff00ff' -channel A -morphology Erode Disk:1.2 -blur 0x0.6 +channel \
    \( +clone -alpha extract \) -compose copy-opacity -composite -trim +repage -resize 1024x1024\> -quality 88 "$A/$(basename "${f%.png}").webp"
done
# End-of-match statistics (manifest-stats.json): plates and defeat poses as JPG, medals and sprites keyed by hue (key.py).
for f in $G/plate-*.png $G/lose-*.png; do conv "$f" "-resize 1600x -quality 80" "$A/$(basename "${f%.png}").jpg"; done
for f in $G/medal-*.png $G/meme-*.png $G/fx-*.png; do
  [ -f "$f" ] || continue
  o="$A/$(basename "${f%.png}").webp"
  python3 "$(dirname "$0")/key.py" "$f" "$o" && magick "$o" -resize 480x480\> -quality 86 "$o"
done
# Gold and orange sprites keep a pink cast from the magenta background: their spill turns gold.
for n in medal-finale medal-dice meme-salvador; do [ -f "$A/$n.webp" ] && python3 "$(dirname "$0")/despill.py" "$A/$n.webp" "$A/$n.webp"; done
ls "$A" | wc -l; du -sh "$A" | cut -f1
