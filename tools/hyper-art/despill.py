"""Usage: despill.py <in.webp> <out.webp>. Turns the magenta-to-red spill a magenta background left on a gold or orange sprite into gold: saturated pixels whose hue lies
between magenta and red get the hue of gold; brightness, saturation and alpha stay."""
import sys
import numpy as np
from PIL import Image
im = Image.open(sys.argv[1]).convert('RGBA')
alpha = im.getchannel('A')
hsv = np.asarray(im.convert('RGB').convert('HSV')).astype(np.int32)
h, s = hsv[..., 0], hsv[..., 1]
mask = ((h > 205) | (h < 6)) & (s > 70)
hsv[..., 0] = np.where(mask, 26, h)
out = Image.fromarray(hsv.astype(np.uint8), 'HSV').convert('RGB').convert('RGBA')
out.putalpha(alpha)
out.save(sys.argv[2], 'WEBP', quality=86)
