/**
 * Proof of Pong's background music (plan "Proof of Pong", P8): Blockfill's MIDI playlist on the manifest the page
 * loads (public/music/midi/manifest.json, resources/js/midi/playlist.js) deals every track once per cycle and never
 * the same track twice in a row; the music bus's level (resources/js/hyper/sounds.js musicGain(), used by audio.js
 * duck() and startMusic()) follows the switch and the music volume and drops while a snippet ducks it; the music
 * volume is read from `pong-sound` and checked. Run by tests/Feature/Pong/PongSnipsTest.php; alone with
 * `node --test tests/js/pongMusic.test.mjs`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { MUSIC_DUCKED, MUSIC_FULL, musicGain, readSettings } from '../../resources/js/hyper/sounds.js';
import { createPlaylist, readManifest } from '../../resources/js/midi/playlist.js';

const manifest = JSON.parse(readFileSync(new URL('../../public/music/midi/manifest.json', import.meta.url), 'utf8'));

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

test('the shipped playlist deals every track once per cycle and never one twice in a row, a new start per load', () => {
    const tracks = readManifest(manifest);
    assert.equal(tracks.length, 10);
    const firsts = new Set();
    for (let seed = 1; seed <= 60; seed++) {
        const playlist = createPlaylist(tracks, seeded(seed));
        const played = Array.from({ length: tracks.length * 8 }, () => playlist.next().url);
        played.forEach((url, i) => assert.notEqual(url, played[i - 1], `seed ${seed}: ${url} twice at ${i}`));
        for (let cycle = 0; cycle < 8; cycle++) {
            assert.equal(new Set(played.slice(cycle * 10, cycle * 10 + 10)).size, 10, `seed ${seed} cycle ${cycle}`);
        }
        firsts.add(played[0]);
    }
    assert.ok(firsts.size >= 6, `only ${firsts.size} different first tracks`);
});

test('the music bus follows the switch and the music volume, and ducks under a snippet', () => {
    assert.equal(musicGain({ music: false }), 0);
    assert.equal(musicGain({ music: false, ducked: true, musicVol: 1 }), 0);
    assert.equal(musicGain({ music: true }), MUSIC_FULL);
    assert.equal(musicGain({ music: true, ducked: true }), MUSIC_DUCKED);
    assert.ok(MUSIC_DUCKED < MUSIC_FULL / 3, 'a duck drops by more than 9 dB');
    for (const vol of [0.05, 0.25, 0.5, 0.75, 1]) {
        const full = musicGain({ music: true, musicVol: vol });
        const ducked = musicGain({ music: true, musicVol: vol, ducked: true });
        assert.ok(Math.abs(full - MUSIC_FULL * vol) < 1e-12, `vol ${vol}`);
        assert.ok(ducked < full, `vol ${vol}: ducked ${ducked} >= ${full}`);
    }
    assert.equal(musicGain({ music: true, musicVol: 0 }), 0);
    // out of range or garbage: clamped, or full
    assert.equal(musicGain({ music: true, musicVol: 4 }), MUSIC_FULL);
    assert.equal(musicGain({ music: true, musicVol: -1 }), 0);
    assert.equal(musicGain({ music: true, musicVol: Number.NaN }), MUSIC_FULL);
});

test('the music volume is kept in `pong-sound`, checked, and full when missing or broken', () => {
    const store = (value) => ({ getItem: (key) => (key === 'pong-sound' ? value : null) });
    assert.equal(readSettings(store(null), 'pong-sound').musicVol, 1);
    assert.equal(readSettings(store('{not json'), 'pong-sound').musicVol, 1);
    assert.equal(readSettings(store(JSON.stringify({ musicVol: 0.35 })), 'pong-sound').musicVol, 0.35);
    assert.equal(readSettings(store(JSON.stringify({ musicVol: 3 })), 'pong-sound').musicVol, 1);
    assert.equal(readSettings(store(JSON.stringify({ musicVol: -2 })), 'pong-sound').musicVol, 0);
    assert.equal(readSettings(store(JSON.stringify({ musicVol: '0.2' })), 'pong-sound').musicVol, 1);
});
