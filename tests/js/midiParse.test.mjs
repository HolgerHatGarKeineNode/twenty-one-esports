/**
 * The MIDI reader of the games' background music (resources/js/midi/parse.js) and the voice each note gets
 * (resources/js/midi/voices.js): the two generated fixtures in tests/js/fixtures/midi/
 * (tools/midi/make-placeholders.mjs) by their exact note counts, tempos and lengths, hand-built files for
 * running status, tempo changes, programs, the sustain pedal and broken input, and every track the manifest
 * of public/music/midi/ lists: it parses, runs longer than 30 s, and every note has a voice. Run by
 * tests/Feature/MidiMusicTest.php; alone with `node --test tests/js/midiParse.test.mjs`.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { parseMidi } from '../../resources/js/midi/parse.js';
import { assignVoices, drumOfKey, voiceOfProgram } from '../../resources/js/midi/voices.js';

const dir = new URL('../../public/music/midi/', import.meta.url);
const file = (name) => readFileSync(new URL(name, dir));
const fixture = (name) => readFileSync(new URL(`fixtures/midi/${name}`, import.meta.url));

const countBy = (notes, key) => notes.reduce((acc, n) => ({ ...acc, [key(n)]: (acc[key(n)] ?? 0) + 1 }), {});
const close = (actual, expected, label) => assert.ok(Math.abs(actual - expected) < 1e-3, `${label}: ${actual} vs ${expected}`);

/** A file from raw track bodies (without their end-of-track); ppq 480. */
function smf(format, tracks, division = 480) {
    const bytes = [0x4d, 0x54, 0x68, 0x64, 0, 0, 0, 6, 0, format, 0, tracks.length, division >> 8, division & 0xff];
    for (const body of tracks) {
        const data = [...body, 0, 0xff, 0x2f, 0];
        bytes.push(0x4d, 0x54, 0x72, 0x6b, (data.length >>> 24) & 0xff, (data.length >>> 16) & 0xff, (data.length >>> 8) & 0xff, data.length & 0xff, ...data);
    }

    return new Uint8Array(bytes);
}

test('placeholder 1: format 1, five tracks, 200 notes at 120 bpm over 16 s, drums on channel 10', () => {
    const song = parseMidi(fixture('placeholder-1.mid'));

    assert.equal(song.format, 1);
    assert.equal(song.tracks, 5);
    assert.equal(song.notes.length, 200);
    assert.deepEqual(song.tempos, [{ time: 0, bpm: 120 }]);
    close(song.duration, 16, 'duration');
    // bass 64 eighths, pad 8 triads, lead 16 quarters, drums 16 kicks + 16 snares + 64 hats
    assert.deepEqual(countBy(song.notes, (n) => `${n.channel}/${n.program}`), { '0/38': 64, '1/89': 24, '2/80': 16, '9/0': 96 });
    assert.deepEqual(countBy(song.notes.filter((n) => n.drum), (n) => n.note), { 36: 16, 38: 16, 42: 64 });
    assert.ok(song.notes.every((n) => n.drum === (n.channel === 9)));
    // the pad's CC 7 = 90 is its level
    close(song.notes.find((n) => n.channel === 1).volume, 90 / 127, 'pad volume');
    // a bar is 2 s: the lead starts in bar 5
    close(song.notes.find((n) => n.channel === 2).time, 8, 'lead start');
    // sorted by time
    assert.ok(song.notes.every((n, i) => i === 0 || song.notes[i - 1].time <= n.time));
});

test('placeholder 2: format 0 with running status, 225 notes, 96 bpm then 112 bpm from bar 5, 18.571 s', () => {
    const song = parseMidi(fixture('placeholder-2.mid'));

    assert.equal(song.format, 0);
    assert.equal(song.tracks, 1);
    assert.equal(song.notes.length, 225);
    assert.equal(song.tempos.length, 2);
    close(song.tempos[0].bpm, 96, 'first tempo');
    close(song.tempos[1].time, 10, 'tempo change at bar 5');
    assert.ok(Math.abs(song.tempos[1].bpm - 112) < 0.01);
    close(song.duration, 10 + (16 * 60) / 112, 'duration');
    assert.deepEqual(countBy(song.notes, (n) => `${n.channel}/${n.program}`), { '0/33': 32, '1/4': 48, '2/11': 32, '9/0': 113 });
    assert.deepEqual(countBy(song.notes.filter((n) => n.drum), (n) => n.note), { 36: 24, 38: 16, 39: 8, 42: 64, 49: 1 });
    // the vibraphone starts with the tempo change, and its eighths are shorter after it
    const vib = song.notes.filter((n) => n.channel === 2);
    close(vib[0].time, 10, 'vibraphone start');
    close(vib[0].duration, 30 / 112, 'an eighth at 112 bpm');
});

test('running status, note-on velocity 0 as off, sysex skipped, a tempo change mid-track', () => {
    const body = [
        0x00, 0xf0, 0x03, 0x7e, 0x09, 0xf7, // sysex
        0x00, 0xc3, 0x05, // program 5 on channel 4
        0x00, 0x93, 0x3c, 0x64, // note on 60
        0x00, 0x40, 0x50, // running status: note on 64
        0x83, 0x60, 0x3c, 0x00, // 480 ticks later: 60 off (velocity 0, running status)
        0x00, 0x40, 0x00, // 64 off
        0x00, 0xff, 0x51, 0x03, 0x0f, 0x42, 0x40, // tempo 1,000,000 us (60 bpm)
        0x00, 0x43, 0x7f, // running status kept across the meta event (lenient, as common readers are): note on 67
        0x83, 0x60, 0x83, 0x43, 0x40, // 480 ticks later: explicit note off
    ];
    const song = parseMidi(smf(0, [body]));

    assert.equal(song.notes.length, 3);
    assert.deepEqual(song.notes.map((n) => [n.note, n.channel, n.program, n.velocity]), [[60, 3, 5, 100], [64, 3, 5, 80], [67, 3, 5, 127]]);
    close(song.notes[0].duration, 0.5, 'a quarter at 120 bpm');
    close(song.notes[2].time, 0.5, 'third note start');
    close(song.notes[2].duration, 1, 'a quarter at 60 bpm');
    close(song.duration, 1.5, 'duration');
    assert.deepEqual(song.tempos, [{ time: 0, bpm: 120 }, { time: 0.5, bpm: 60 }]);
});

test('format 1: the tempo of the first track times the notes of the others; overlapping notes end in order; the pedal holds', () => {
    const conductor = [0x00, 0xff, 0x51, 0x03, 0x07, 0xa1, 0x20]; // 500,000 us
    const notes = [
        0x00, 0x90, 0x3c, 0x64, // 60 on
        0x81, 0x70, 0x90, 0x3c, 0x64, // 240: 60 on again (overlapping)
        0x81, 0x70, 0x80, 0x3c, 0x40, // 480: first 60 off
        0x81, 0x70, 0x80, 0x3c, 0x40, // 720: second 60 off
        0x00, 0xb0, 0x40, 0x7f, // pedal down
        0x00, 0x90, 0x40, 0x64, // 64 on at 720
        0x81, 0x70, 0x80, 0x40, 0x40, // 960: 64 released, held by the pedal
        0x83, 0x60, 0xb0, 0x40, 0x00, // 1440: pedal up
        0x00, 0x99, 0x24, 0x7f, // a kick on channel 10, never released
    ];
    const song = parseMidi(smf(1, [conductor, notes]));
    const [first, second, held, kick] = song.notes;

    close(first.duration, 0.5, 'first 60');
    close(second.time, 0.25, 'second 60 start');
    close(second.duration, 0.5, 'second 60');
    close(held.duration, 0.75, 'held to the pedal');
    assert.equal(kick.drum, true);
    close(kick.time, 1.5, 'kick');
    assert.ok(kick.duration > 0);
});

test('broken input throws a clear error instead of returning half a song', () => {
    assert.throws(() => parseMidi(new Uint8Array([1, 2, 3, 4])), /not a Standard MIDI File/);
    assert.throws(() => parseMidi(smf(2, [[0x00, 0x90, 0x3c, 0x64]])), /format 2/);
    assert.throws(() => parseMidi(smf(0, [[0x00, 0x3c, 0x64]])), /data byte without a status/);
    const truncated = smf(0, [[0x00, 0x90, 0x3c, 0x64, 0x83, 0x60, 0x80, 0x3c, 0x40]]).slice(0, 26);
    assert.throws(() => parseMidi(truncated), /end of the file|unexpected end/);
});

test('every track in the manifest parses, runs longer than 30 s, carries its credits, and every note has a voice', () => {
    const manifest = JSON.parse(readFileSync(new URL('manifest.json', dir), 'utf8'));

    assert.equal(manifest.tracks.length, 10);
    const report = [];
    for (const track of manifest.tracks) {
        assert.match(track.file, /^[a-z0-9-]+\.mid$/);
        assert.ok(track.title && track.author && track.license, `${track.file}: credits`);
        assert.match(track.source, /^https:\/\/opengameart\.org\/content\//);
        assert.ok(['synthwave', 'trance'].includes(track.genre), `${track.file}: genre`);
        const song = parseMidi(file(track.file));
        const voices = assignVoices(song);
        assert.ok(song.duration > 30, `${track.file}: ${song.duration} s`);
        assert.ok(song.notes.length > 500, `${track.file}: ${song.notes.length} notes`);
        // nothing falls silent, no note without a voice, and every track has drums
        assert.equal(voices.silent, undefined, `${track.file}: silent notes`);
        assert.ok(song.notes.every((n) => typeof n.voice === 'string'), track.file);
        assert.ok(song.notes.some((n) => n.drum), `${track.file}: drums`);
        report.push(`${track.file} ${song.notes.length} notes ${song.duration.toFixed(1)} s ${JSON.stringify(voices)}`);
    }
    process.stdout.write(report.map((line) => `# ${line}`).join('\n') + '\n');
});

test('the General MIDI programs of the playlist get the voice of their family, the drum keys their drum', () => {
    const expected = {
        0: 'keys', 14: 'bell', 18: 'organ', 27: 'pluck', 29: 'drive', 30: 'drive', 34: 'bass', 36: 'bass', 37: 'bass', 38: 'bass',
        42: 'strings', 46: 'pluck', 47: 'timpani', 48: 'strings', 50: 'strings', 52: 'pad', 62: 'brass', 80: 'lead', 81: 'saw',
        82: 'lead', 87: 'saw', 93: 'pad', 99: 'pad', 100: 'pad', 120: null, 127: null,
    };
    assert.deepEqual(Object.fromEntries(Object.keys(expected).map((p) => [p, voiceOfProgram(Number(p))])), expected);
    assert.deepEqual([35, 36, 37, 38, 39, 40, 42, 44, 46, 49, 52, 53, 57, 59, 63, 64].map(drumOfKey), [
        'kick', 'kick', 'tick', 'snare', 'clap', 'snare', 'hat', 'hat', 'openhat', 'crash', 'crash', 'ride', 'crash', 'ride', 'tom', 'tom',
    ]);
});
