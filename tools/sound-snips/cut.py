#!/usr/bin/env python3
"""Cut the soundboard (public/hyper/s/*.mp3) into a library of short snippets (plan "Proof of Pong", P7; P8 reuses it
for Hyperbitcoinization), written to public/sounds/snips/ with a manifest (public/sounds/snips/manifest.json).

For each clip:
- its loudness is measured (ffmpeg loudnorm, integrated LUFS) and every snippet of it gets the same gain towards
  TARGET_LUFS (clamped), then a limiter, so all snippets play at about one level;
- pauses are found with silencedetect (threshold relative to the clip's own loudness), speech is what lies between;
- a clip of at most WHOLE_S seconds of speech stays whole (its silent ends trimmed); a longer one is cut in the middle of
  pauses into phrases of MIN_S..MAX_S seconds (joined only while shorter than TARGET_S). A stretch of speech longer than MAX_S without a pause is searched again
  with a finer threshold; if it still has none, it is dropped (a cut there would split a word), and so is a stretch
  shorter than MIN_S that has no neighbour to join;
- each snippet fades in and out over FADE_S and is encoded as mono MP3 at a small bitrate.

The manifest names, per snippet: its file, the source clip, the person (the clip's prefix before "_", or a known
speaker for unprefixed names), its duration and its tags: the Hyperbitcoinization POOLS categories
(resources/js/hyper/data.js) its source clip belongs to. Usage: tools/sound-snips/cut.py [--dry]
"""

import json
import os
import re
import shutil
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SRC = os.path.join(ROOT, 'public', 'hyper', 's')
OUT = os.path.join(ROOT, 'public', 'sounds', 'snips')
DATA = os.path.join(ROOT, 'resources', 'js', 'hyper', 'data.js')

MIN_S = 0.8
MAX_S = 4.0
# A clip up to this long stays whole (a four-second line is not cut for a tenth of a second).
WHOLE_S = 4.6
# A snippet takes the next stretch only while it is shorter than this: phrases, not packed four-second blocks.
TARGET_S = 1.6
FADE_S = 0.015
PAD_S = 0.03
TARGET_LUFS = -16.0
MAX_GAIN_DB = 15.0
BITRATE = '48k'

# Unprefixed clip names whose speaker is known (the cast's figures, resources/js/pong/cast.json).
SPEAKERS = {
    'gigi-pos': 'gigi', 'cerca-wette': 'cerca', 'dennis-traum': 'dennis', 'ole-sekte': 'ole',
    'saylor-nosecondbest': 'saylor', 'schnabl-cbdc': 'schnabel', 'herr-turm-impulsiv': 'turm', 'kinski': 'kinski',
}


def run(args):
    return subprocess.run(args, capture_output=True, text=True, check=True)


def duration(path):
    out = run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', path]).stdout
    return float(out.strip())


def loudness(path):
    err = run(['ffmpeg', '-hide_banner', '-nostats', '-i', path, '-af', 'loudnorm=print_format=json', '-f', 'null', '-']).stderr
    data = json.loads(err[err.rindex('{'):err.rindex('}') + 1])
    value = float(data['input_i'])
    return value if value > -70 else -40.0


def silences(path, noise_db, min_s, total):
    err = run(['ffmpeg', '-hide_banner', '-nostats', '-i', path, '-af', f'silencedetect=noise={noise_db}dB:d={min_s}', '-f', 'null', '-']).stderr
    starts = [float(x) for x in re.findall(r'silence_start: (-?[\d.]+)', err)]
    ends = [float(x) for x in re.findall(r'silence_end: ([\d.]+)', err)]
    if len(ends) < len(starts):
        ends.append(total)
    return [(max(0.0, a), min(total, b)) for a, b in zip(starts, ends)]


def speech(total, quiet):
    """The stretches between the pauses, as [start, end]."""
    out, at = [], 0.0
    for a, b in quiet:
        if a - at > 0.05:
            out.append([at, a])
        at = b
    if total - at > 0.05:
        out.append([at, total])
    return out


def split_long(path, seg, noise_db, total):
    """A stretch longer than MAX_S searched again with finer thresholds (a dip under music counts as a pause); the
    parts, or [] when no threshold splits it into parts of at most MAX_S."""
    for lift, min_s in ((6, 0.07), (10, 0.05)):
        quiet = [(a, b) for a, b in silences(path, noise_db + lift, min_s, total) if a > seg[0] + 0.2 and b < seg[1] - 0.2]
        if not quiet:
            continue
        parts, at = [], seg[0]
        for a, b in quiet:
            parts.append([at, a])
            at = b
        parts.append([at, seg[1]])
        if all(p[1] - p[0] <= MAX_S for p in parts):
            return parts
    return []


def plan(path, total, level):
    """The snippets of one clip as [start, end] and how many stretches were dropped."""
    noise = max(-50.0, min(-26.0, level - 16.0))
    segs = speech(total, silences(path, noise, 0.15, total))
    if not segs:
        return [], 0
    # Short enough as a whole: one snippet without the silent ends.
    if segs[-1][1] - segs[0][0] <= WHOLE_S:
        return [[segs[0][0], segs[-1][1]]], 0
    dropped = 0
    fine = []
    for seg in segs:
        if seg[1] - seg[0] <= MAX_S:
            fine.append(seg)
            continue
        parts = split_long(path, seg, noise, total)
        if parts:
            fine.extend(parts)
        else:
            dropped += 1
            fine.append(None)  # a hole: nothing joins across it
    # Greedy: join neighbouring stretches while the snippet is shorter than TARGET_S and stays within MAX_S; a cut lies
    # between two stretches.
    out, cur = [], None
    for seg in fine + [None]:
        if seg is not None and cur is not None and cur[1] - cur[0] < TARGET_S and seg[1] - cur[0] <= MAX_S:
            cur = [cur[0], seg[1]]
            continue
        if cur is not None:
            if cur[1] - cur[0] >= MIN_S:
                out.append(cur)
            else:
                dropped += 1
        cur = list(seg) if seg is not None else None
    return out, dropped


def pools():
    text = open(DATA, encoding='utf-8').read()
    match = re.search(r'export const POOLS = (\{.*?\});\n', text)
    return json.loads(match.group(1))


def person(name):
    return name.split('_', 1)[0] if '_' in name else SPEAKERS.get(name, '')


def main():
    dry = '--dry' in sys.argv
    tags = {}
    for pool, clips in pools().items():
        for clip in clips:
            tags.setdefault(clip, []).append(pool)
    names = sorted(f[:-4] for f in os.listdir(SRC) if f.endswith('.mp3'))
    if not dry:
        shutil.rmtree(OUT, ignore_errors=True)
        os.makedirs(OUT)
    snips, dropped = [], 0
    for name in names:
        path = os.path.join(SRC, name + '.mp3')
        total = duration(path)
        level = loudness(path)
        gain = max(-MAX_GAIN_DB, min(MAX_GAIN_DB, TARGET_LUFS - level))
        cuts, lost = plan(path, total, level)
        dropped += lost
        for n, (a, b) in enumerate(cuts, start=1):
            a, b = max(0.0, a - PAD_S), min(total, b + PAD_S)
            file = f'{name}--{n}.mp3'
            if not dry:
                fade_out = max(0.0, (b - a) - FADE_S)
                af = f'volume={gain:.2f}dB,alimiter=limit=0.89:level=false,afade=t=in:d={FADE_S},afade=t=out:st={fade_out:.3f}:d={FADE_S}'
                run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-ss', f'{a:.3f}', '-to', f'{b:.3f}', '-i', path, '-af', af,
                     '-ac', '1', '-ar', '44100', '-c:a', 'libmp3lame', '-b:a', BITRATE, os.path.join(OUT, file)])
                dur = duration(os.path.join(OUT, file))
            else:
                dur = b - a
            snips.append({'id': f'{name}~{n}', 'file': file, 'source': name, 'person': person(name), 'dur': round(dur, 2), 'tags': sorted(tags.get(name, []))})
    manifest = {'base': '/sounds/snips/', 'min': MIN_S, 'max': MAX_S, 'snips': snips}
    if not dry:
        with open(os.path.join(OUT, 'manifest.json'), 'w', encoding='utf-8') as fh:
            json.dump(manifest, fh, ensure_ascii=False, separators=(',', ':'))
            fh.write('\n')
    durs = sorted(s['dur'] for s in snips)
    pick = lambda q: durs[min(len(durs) - 1, int(q * len(durs)))]
    print(f'clips {len(names)}  snippets {len(snips)}  dropped {dropped}  dur min {durs[0]} p25 {pick(0.25)} median {pick(0.5)} p75 {pick(0.75)} max {durs[-1]}  total {sum(durs):.1f}s')


if __name__ == '__main__':
    main()
