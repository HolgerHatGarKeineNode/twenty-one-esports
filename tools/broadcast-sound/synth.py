#!/usr/bin/env python3
"""Synthesize the broadcast's stinger sounds (plan "OBS-Broadcast-Overlays", P1) into public/broadcast/sound/.

Everything is generated from noise and oscillators here, no samples, so the files carry no third-party licence
(public/broadcast/sound/LICENSE.txt). Each sound is rendered at 48 kHz stereo, then loudness-normalised by ffmpeg's
loudnorm to -20 LUFS integrated with true peak <= -3 dBTP (quiet: it sits under a caster's voice) and encoded as Ogg
Vorbis. Usage: synth.py <outdir>

  whoosh   0.9 s  band-passed noise sweeping up and across left to right: the stinger's wall rising
  hit      1.4 s  sub drop, transient and a metallic ring, its attack at 0 ms: lands on the stinger's peak
  riser    1.2 s  noise and a saw sweeping up, cut at the end: leads into a pride moment
  shimmer  1.8 s  a bright inharmonic chord with slow tremolo: a pride moment landing
"""

import math, os, subprocess, sys, tempfile, wave
import numpy as np

SR = 48000
rng = np.random.default_rng(21)


def bandpass(x, centre, q):
    """Time-varying biquad band-pass (RBJ cookbook), `centre` an array of Hz per sample."""
    y = np.zeros_like(x)
    x1 = x2 = y1 = y2 = 0.0
    for i in range(len(x)):
        w0 = 2 * math.pi * centre[i] / SR
        alpha = math.sin(w0) / (2 * q)
        a0 = 1 + alpha
        b0, b2 = alpha / a0, -alpha / a0
        a1, a2 = -2 * math.cos(w0) / a0, (1 - alpha) / a0
        v = b0 * x[i] + b2 * x2 - a1 * y1 - a2 * y2
        x2, x1, y2, y1 = x1, x[i], y1, v
        y[i] = v
    return y


def env(n, attack, release, curve=2.0):
    t = np.arange(n) / SR
    a = np.clip(t / max(attack, 1e-4), 0, 1)
    r = np.clip((t[-1] - t) / max(release, 1e-4), 0, 1)
    return (a ** 0.7) * (r ** curve)


def reverb(x, taps=((0.031, 0.5), (0.047, 0.4), (0.071, 0.32), (0.113, 0.22), (0.167, 0.15), (0.229, 0.1))):
    y = x.copy()
    for delay, g in taps:
        d = int(delay * SR)
        y[d:] += x[:-d] * g
    return y


def whoosh():
    n = int(0.9 * SR)
    t = np.linspace(0, 1, n)
    centre = 250 + 2600 * np.sin(np.pi * np.clip(t * 1.1, 0, 1)) ** 2
    noise = rng.standard_normal(n)
    body = bandpass(noise, centre, 1.4) * env(n, 0.55, 0.32)
    pan = np.clip(t * 1.2 - 0.1, 0, 1)
    return np.stack([body * np.cos(pan * np.pi / 2), body * np.sin(pan * np.pi / 2)], 1)


def hit():
    n = int(1.4 * SR)
    t = np.arange(n) / SR
    freq = 48 + 70 * np.exp(-t * 18)
    sub = np.sin(2 * np.pi * np.cumsum(freq) / SR) * np.exp(-t * 3.2)
    click = bandpass(rng.standard_normal(n), np.full(n, 2400.0), 0.8) * np.exp(-t * 90) * 1.6
    ring = sum(np.sin(2 * np.pi * f * t + p) * a for f, a, p in ((823, 0.30, 0.0), (1311, 0.22, 1.1), (2143, 0.14, 2.3), (3377, 0.08, 0.7))) * np.exp(-t * 5.5)
    mono = reverb(sub * 1.1 + click + ring * 0.7)
    return np.stack([mono, np.roll(mono, 37)], 1)


def riser():
    n = int(1.2 * SR)
    t = np.linspace(0, 1, n)
    centre = 300 + 4200 * t ** 2.2
    noise = bandpass(rng.standard_normal(n), centre, 2.2)
    freq = 110 + 660 * t ** 2
    saw = 2 * ((np.cumsum(freq) / SR) % 1.0) - 1
    saw = bandpass(saw, centre * 0.6 + 200, 1.0)
    body = (noise * 0.8 + saw * 0.5) * (t ** 1.6) * env(n, 0.01, 0.015, 1.0)
    return np.stack([body, np.roll(body, 23)], 1)


def shimmer():
    n = int(1.8 * SR)
    t = np.arange(n) / SR
    chord = sum(np.sin(2 * np.pi * f * t) * a for f, a in ((1046.5, 0.3), (1318.5, 0.24), (1568.0, 0.22), (2093.0, 0.16), (2637.0, 0.1), (3951.0, 0.06)))
    trem = 0.75 + 0.25 * np.sin(2 * np.pi * 5.5 * t)
    mono = reverb(chord * trem * env(n, 0.02, 1.4, 2.4))
    return np.stack([mono, np.roll(mono, 61)], 1)


def write(path, stereo):
    pcm = stereo / max(1e-9, np.abs(stereo).max()) * 0.7
    with wave.open(path, 'wb') as w:
        w.setnchannels(2)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes((pcm * 32767).astype('<i2').tobytes())


def main():
    out = sys.argv[1]
    os.makedirs(out, exist_ok=True)
    with tempfile.TemporaryDirectory() as tmp:
        for name, fn in (('whoosh', whoosh), ('hit', hit), ('riser', riser), ('shimmer', shimmer)):
            wav = os.path.join(tmp, name + '.wav')
            write(wav, fn())
            subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-i', wav, '-af', 'loudnorm=I=-20:TP=-3:LRA=7', '-ar', '48000',
                            '-c:a', 'libvorbis', '-q:a', '5', os.path.join(out, name + '.ogg')], check=True)
            print('ok', name)


if __name__ == '__main__':
    main()
