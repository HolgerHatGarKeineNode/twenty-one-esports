"""Key a magenta background by hue: a pixel goes transparent where red and blue both clearly exceed green;
edge pixels with a magenta tint are despilled (green raised towards red/blue mean) and faded. With argv[3] == 'enclosed'
a purple part inside the figure (Ghosty's Ethereum logo) stays opaque: keyed pixels not connected to the border are kept
unless they are clearly the background's magenta."""
import sys
import numpy as np
from PIL import Image, ImageDraw, ImageFilter
src, dst = sys.argv[1], sys.argv[2]
im = np.asarray(Image.open(src).convert('RGB')).astype(np.float32)
r, g, b = im[..., 0], im[..., 1], im[..., 2]
# Key colour: magenta by default, green for assets whose own colour is pink (argv[3] == 'green').
spill = (g - np.maximum(r, b)) if len(sys.argv) > 3 and sys.argv[3] == 'green' else (np.minimum(r, b) - g)
# Thresholds follow the background actually painted: some images come with a dull magenta.
h, w = spill.shape; corners = np.concatenate([spill[:8, :8].ravel(), spill[:8, -8:].ravel(), spill[-8:, :8].ravel(), spill[-8:, -8:].ravel()])
bg = float(np.median(corners)); hi = min(90.0, bg * 0.8); lo = hi * 0.45
alpha = np.clip(1.0 - (spill - lo) / max(1.0, hi - lo), 0, 1)
enclosed = np.zeros_like(alpha, dtype=bool)
if len(sys.argv) > 3 and sys.argv[3] == 'enclosed':
    mask = Image.fromarray(np.pad((alpha < 0.5).astype(np.uint8) * 255, 1, constant_values=255))
    ImageDraw.floodfill(mask, (0, 0), 128)
    enclosed = (alpha < 0.95) & (np.asarray(mask)[1:-1, 1:-1] != 128) & (spill < 0.6 * bg)
    alpha = np.where(enclosed, 1.0, alpha)
a_img = Image.fromarray((alpha * 255).astype(np.uint8)).filter(ImageFilter.MinFilter(3)).filter(ImageFilter.GaussianBlur(0.7))
# despill: pull green up where some magenta remains
fix = np.where(enclosed, 0, np.clip(spill, 0, None) * 0.85)
if len(sys.argv) > 3 and sys.argv[3] == 'green':
    rgb = np.stack([r, np.clip(g - fix, 0, 255), b], -1).astype(np.uint8)
else:
    rgb = np.stack([r, np.clip(g + fix, 0, 255), b], -1).astype(np.uint8)
out = Image.fromarray(rgb).convert('RGBA'); out.putalpha(a_img)
bbox = out.getchannel('A').point(lambda v: 255 if v > 12 else 0).getbbox()
if bbox: out = out.crop(bbox)
out.thumbnail((1024, 1024))
out.save(dst, 'WEBP', quality=88)
