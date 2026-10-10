/**
 * Stinger sounds (public/broadcast/sound/*.ogg, synthesized by tools/broadcast-sound/synth.py, loudness-matched):
 * whoosh (the wall rising), hit (the peak), riser (before a pride moment), shimmer (a pride moment landing).
 * Silent until enabled: a browser plays audio only after a gesture; an OBS browser source may autoplay, so overlays
 * enable it from their preset (`?sound=1` on the styleguide stage).
 */

const NAMES = ['whoosh', 'hit', 'riser', 'shimmer'];

export function createSound(base = '/broadcast/sound/') {
    let ctx = null;
    let gain = null;
    const buffers = new Map();
    let enabled = false;

    async function load() {
        await Promise.all(NAMES.map(async (n) => {
            const res = await fetch(`${base}${n}.ogg`);
            if (!res.ok) return;
            buffers.set(n, await ctx.decodeAudioData(await res.arrayBuffer()));
        }));
    }

    return {
        get enabled() { return enabled; },
        async enable(volume = 0.8) {
            if (!ctx) {
                ctx = new AudioContext();
                gain = ctx.createGain();
                gain.gain.value = volume;
                gain.connect(ctx.destination);
                await load();
            }
            await ctx.resume();
            enabled = ctx.state === 'running';

            return enabled;
        },
        /** Play `name` so that its marked moment lands `inMs` from now (0 = start now). */
        play(name, inMs = 0) {
            if (!enabled || !buffers.has(name)) return;
            const src = ctx.createBufferSource();
            src.buffer = buffers.get(name);
            src.connect(gain);
            src.start(ctx.currentTime + Math.max(0, inMs) / 1000);
        },
    };
}
