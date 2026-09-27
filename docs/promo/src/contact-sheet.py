"""Contact sheet of rendered posters: one overview PNG, grouped by motif.
Usage: python3 docs/promo/src/contact-sheet.py [out.png] [motif ...]
Default out: docs/promo/posters/contact-sheet.png (output stays outside src)."""
import glob, json, os, sys
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '..'))
out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(ROOT, 'posters', 'contact-sheet.png')
only = sys.argv[2:]
FORMATS = [('mobile', 'mobile-9x16'), ('x', 'x-16x9'), ('nostr', 'nostr-square'), ('nostr', 'nostr-wide'), ('stream', 'stream-banner')]
H = 360  # row height per poster in the sheet
font = ImageFont.truetype(os.path.join(ROOT, '..', '..', 'resources', 'fonts', 'og', 'Unbounded-Bold.ttf'), 22) if os.path.exists(os.path.join(ROOT, '..', '..', 'resources', 'fonts', 'og', 'Unbounded-Bold.ttf')) else ImageFont.load_default()
small = ImageFont.load_default()
motifs = []
for f in sorted(glob.glob(os.path.join(ROOT, 'posters', 'mobile', '*-mobile-9x16-de.png'))):
    m = os.path.basename(f).replace('-mobile-9x16-de.png', '')
    if not only or m in only:
        motifs.append(m)
order = ['login', 'blitz', 'watch', 'daily', 'clans', 'tournaments', 'invite', 'opensource']
motifs.sort(key=lambda m: order.index(m) if m in order else 99)
rows = []
for m in motifs:
    for lang in ('de', 'en'):
        tiles = []
        for d, fid in FORMATS:
            p = os.path.join(ROOT, 'posters', d, f'{m}-{fid}-{lang}.png')
            if os.path.exists(p):
                im = Image.open(p).convert('RGB')
                w = round(im.width * H / im.height) if fid != 'stream-banner' else round(im.width * (H / 3) / im.height)
                h = H if fid != 'stream-banner' else H // 3
                tiles.append(im.resize((w, h), Image.LANCZOS))
        rows.append((f'{m} {lang}', tiles))
pad = 16
W = max(sum(t.width for t in tiles) + pad * (len(tiles) + 1) + 220 for _, tiles in rows)
sheet = Image.new('RGB', (W, len(rows) * (H + pad) + pad), (38, 38, 42))
dr = ImageDraw.Draw(sheet)
y = pad
for label, tiles in rows:
    dr.text((pad, y + 8), label, fill=(247, 147, 26), font=font)
    x = 220
    for t in tiles:
        sheet.paste(t, (x, y))
        x += t.width + pad
    y += H + pad
sheet.save(out)
print(out, sheet.size, len(rows), 'rows')
