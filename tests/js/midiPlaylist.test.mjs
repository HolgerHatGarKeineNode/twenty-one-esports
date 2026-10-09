/**
 * The order of the games' background music (resources/js/midi/playlist.js): every track once per cycle, never
 * the same track twice in a row (also across the seam of two cycles), a different start per page load, and the
 * manifest read leniently. Plus the player (resources/js/midi/player.js) against a stand-in AudioContext: it
 * schedules notes ahead, stops, moves on to the next track, and reports "nothing to play" for an empty or broken
 * manifest so the game keeps its own music. Run by tests/Feature/MidiMusicTest.php; alone with
 * `node --test tests/js/midiPlaylist.test.mjs`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createMidiPlayer } from '../../resources/js/midi/player.js';
import { createPlaylist, readManifest, shuffle } from '../../resources/js/midi/playlist.js';

/** mulberry32: a seeded random in [0, 1). */
function seeded(seed) {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) >>> 0;
        let t = a;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

test('every track once per cycle and never twice in a row, over 1000 cycles, for 2 to 9 tracks', () => {
    for (const size of [2, 3, 4, 5, 9]) {
        for (const random of [seeded(size * 7919), Math.random]) {
            const tracks = Array.from({ length: size }, (_, i) => `t${i}`);
            const playlist = createPlaylist(tracks, random);
            let last = null;
            let seams = 0;
            for (let cycle = 0; cycle < 1000; cycle++) {
                const played = Array.from({ length: size }, () => playlist.next());
                assert.deepEqual([...played].sort(), [...tracks].sort(), `size ${size}, cycle ${cycle}: each track once`);
                played.forEach((track, i) => assert.notEqual(track, i === 0 ? last : played[i - 1], `size ${size}, cycle ${cycle}, place ${i}: a repeat`));
                if (last !== null && played[0] !== last) {
                    seams++;
                }
                last = played[size - 1];
            }
            assert.equal(seams, 999);
        }
    }
});

test('the seam rule is exercised: without it a fresh shuffle often opens with the track that just ended', () => {
    // a plain reshuffle of two tracks opens with the last one in about half the cycles
    const random = seeded(42);
    let clashes = 0;
    let last = null;
    for (let cycle = 0; cycle < 400; cycle++) {
        const order = shuffle(['a', 'b'], random);
        if (order[0] === last) {
            clashes++;
        }
        last = order[1];
    }
    assert.ok(clashes > 100, `plain shuffle clashed ${clashes} times`);
});

test('a new page load is a new random start; one track repeats, none is null', () => {
    const tracks = ['a', 'b', 'c', 'd', 'e'];
    const openers = new Set(Array.from({ length: 200 }, (_, seed) => createPlaylist(tracks, seeded(seed + 1)).next()));
    assert.deepEqual([...openers].sort(), tracks);

    const one = createPlaylist(['only']);
    assert.deepEqual([one.next(), one.next(), one.next()], ['only', 'only', 'only']);
    assert.equal(createPlaylist([]).next(), null);
});

test('the manifest: plain .mid file names relative to it, duplicates once, anything else skipped', () => {
    assert.deepEqual(
        readManifest({ tracks: [{ file: 'a.mid', title: 'A', source: 'javascript:alert(1)' }, { file: 'b.MIDI' }, { file: 'a.mid' }, { file: '../x.mid' }, { file: 'c.mp3' }, null, { title: 'no file' }] }, '/music/midi/manifest.json'),
        [
            { url: '/music/midi/a.mid', title: 'A', author: '', license: '', source: null, genre: '' },
            { url: '/music/midi/b.MIDI', title: 'b.MIDI', author: '', license: '', source: null, genre: '' },
        ],
    );
    assert.deepEqual(readManifest(null), []);
    assert.deepEqual(readManifest({ tracks: 'x' }), []);

    const real = readManifest(JSON.parse(readFileSync(new URL('../../public/music/midi/manifest.json', import.meta.url), 'utf8')));
    assert.equal(real.length, 10);
    assert.ok(real.every((t) => t.url.startsWith('/music/midi/') && t.source.startsWith('https://') && t.author !== '' && t.license !== ''));
    assert.deepEqual(new Set(real.map((t) => t.genre)), new Set(['synthwave', 'trance']));
});

// ---- the player against a stand-in AudioContext ---------------------------

function fakeAudio() {
    const param = () => ({ value: 0, setValueAtTime() {}, exponentialRampToValueAtTime() {}, linearRampToValueAtTime() {}, setTargetAtTime() {}, cancelScheduledValues() {} });
    const node = (extra = {}) => ({ connect: (next) => next, disconnect() {}, start() {}, stop() {}, gain: param(), frequency: param(), detune: param(), Q: param(), ...extra });
    const ctx = {
        currentTime: 0,
        sampleRate: 8000,
        createGain: () => node(),
        createOscillator: () => node(),
        createBiquadFilter: () => node(),
        createBufferSource: () => node(),
        createDynamicsCompressor: () => node({ threshold: param(), knee: param(), ratio: param(), attack: param(), release: param() }),
        createBuffer: (channels, length) => ({ getChannelData: () => new Float32Array(length) }),
    };
    const timers = new Map();
    let id = 0;
    const env = {
        setInterval: (fn) => {
            timers.set(++id, fn);

            return id;
        },
        clearInterval: (t) => timers.delete(t),
        setTimeout: () => 0,
    };
    // moves the audio clock and runs the scheduler's timer as often as it would have fired
    const advance = async (seconds) => {
        for (let t = 0; t < seconds; t += 0.08) {
            ctx.currentTime += 0.08;
            await Promise.resolve();
            [...timers.values()].forEach((fn) => fn());
        }
    };

    return { ctx, env, timers, advance };
}

const midiFile = (name) => readFileSync(new URL(`fixtures/midi/${name}`, import.meta.url));

function fetcher(routes) {
    return async (url) => {
        const body = routes[url];
        if (body === undefined) {
            return { ok: false, status: 404, json: async () => ({}), arrayBuffer: async () => new ArrayBuffer(0) };
        }

        return { ok: true, status: 200, json: async () => body, arrayBuffer: async () => body };
    };
}

test('the player schedules the notes ahead, stops on stop(), and goes on to the other track after the first ends', async () => {
    const audio = fakeAudio();
    const manifest = { tracks: [{ file: 'placeholder-1.mid', title: 'One' }, { file: 'placeholder-2.mid', title: 'Two' }] };
    const player = createMidiPlayer({
        ctx: audio.ctx,
        output: {},
        env: audio.env,
        random: seeded(3),
        fetch: fetcher({ '/music/midi/manifest.json': manifest, '/music/midi/placeholder-1.mid': midiFile('placeholder-1.mid'), '/music/midi/placeholder-2.mid': midiFile('placeholder-2.mid') }),
    });

    assert.equal(await player.start(), true);
    const first = player.debug().track;
    assert.ok(player.debug().playing);
    await audio.advance(2);
    const early = player.debug().scheduled;
    assert.ok(early > 5, `scheduled ${early}`);

    // past the end of the first track and the gap: the other one plays
    await audio.advance(21);
    assert.notEqual(player.debug().track, first);
    assert.ok(player.debug().scheduled > early);

    player.stop();
    const stopped = player.debug().scheduled;
    await audio.advance(2);
    assert.equal(player.debug().scheduled, stopped);
    assert.equal(player.debug().playing, false);
    assert.equal(audio.timers.size, 0);

    // started again: it plays on
    assert.equal(await player.start(), true);
    await audio.advance(1);
    assert.ok(player.debug().scheduled > stopped);
    player.stop();
});

test('nothing to play is false: no manifest, an empty one, or files that do not load; a stop during the start wins', async () => {
    const make = (routes) => {
        const audio = fakeAudio();

        return { audio, player: createMidiPlayer({ ctx: audio.ctx, output: {}, env: audio.env, fetch: fetcher(routes) }) };
    };

    assert.equal(await make({}).player.start(), false);
    assert.equal(await make({ '/music/midi/manifest.json': { tracks: [] } }).player.start(), false);
    const broken = make({ '/music/midi/manifest.json': { tracks: [{ file: 'gone.mid' }, { file: 'junk.mid' }] }, '/music/midi/junk.mid': new Uint8Array([1, 2, 3]).buffer });
    assert.equal(await broken.player.start(), false);
    assert.equal(broken.player.debug().failed, 2);
    assert.equal(broken.audio.timers.size, 0);

    const raced = make({ '/music/midi/manifest.json': { tracks: [{ file: 'placeholder-1.mid' }] }, '/music/midi/placeholder-1.mid': midiFile('placeholder-1.mid') });
    const starting = raced.player.start();
    raced.player.stop();
    assert.equal(await starting, false);
    assert.equal(raced.audio.timers.size, 0);
    assert.equal(raced.player.debug().playing, false);
});
