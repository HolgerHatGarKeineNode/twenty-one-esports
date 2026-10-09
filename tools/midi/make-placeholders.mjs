#!/usr/bin/env node
/**
 * Writes the two MIDI test fixtures (tests/js/fixtures/midi/placeholder-*.mid): short
 * original pieces made here, note by note, whose every note, tempo and length is known, so the reader's
 * tests can assert exact numbers. Deterministic: running it again writes the same bytes.
 *
 *   node tools/midi/make-placeholders.mjs [out-dir]   (default tests/js/fixtures/midi)
 *
 * placeholder-1.mid  format 1, 5 tracks, 120 bpm, 8 bars of 4/4 in A minor (Am F C G):
 *                    synth bass eighths, pad triads per bar, a square lead in bars 5-8, drums on channel 10.
 * placeholder-2.mid  format 0 (one track, every channel, running status), 96 bpm for 4 bars, then 112 bpm
 *                    for 4 bars: fingered bass quarters, electric-piano stabs, vibraphone arpeggios, drums.
 *
 * tests/js/midiParse.test.mjs counts their notes, tempos and lengths.
 */

import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const PPQ = 480;
const BAR = PPQ * 4;
const EIGHTH = PPQ / 2;
const DRUMS = 9;
const KICK = 36;
const SNARE = 38;
const CLAP = 39;
const HAT = 42;
const CRASH = 49;

const varLen = (value) => {
    const bytes = [value & 0x7f];
    let v = value >> 7;
    while (v > 0) {
        bytes.unshift((v & 0x7f) | 0x80);
        v >>= 7;
    }

    return bytes;
};

const text = (s) => [...Buffer.from(s, 'ascii')];

/** A track chunk from events {tick, data: [status, ...], meta?}; running status leaves out repeated status bytes. */
function track(events, { running = false } = {}) {
    const sorted = events.map((e, i) => ({ ...e, i })).sort((a, b) => a.tick - b.tick || (a.off === b.off ? a.i - b.i : a.off ? -1 : 1));
    const out = [];
    let tick = 0;
    let status = 0;
    for (const e of sorted) {
        out.push(...varLen(e.tick - tick));
        tick = e.tick;
        if (e.meta) {
            out.push(...e.data);
            status = 0;
            continue;
        }
        if (running && e.data[0] === status) {
            out.push(...e.data.slice(1));
        } else {
            out.push(...e.data);
            status = e.data[0];
        }
    }
    out.push(0, 0xff, 0x2f, 0x00);

    return [...text('MTrk'), ...u32(out.length), ...out];
}

const u32 = (v) => [(v >>> 24) & 0xff, (v >>> 16) & 0xff, (v >>> 8) & 0xff, v & 0xff];
const u16 = (v) => [(v >> 8) & 0xff, v & 0xff];
const header = (format, tracks) => [...text('MThd'), ...u32(6), ...u16(format), ...u16(tracks), ...u16(PPQ)];

const meta = (tick, type, bytes) => ({ tick, meta: true, data: [0xff, type, ...varLen(bytes.length), ...bytes] });
const tempo = (tick, bpm) => {
    const us = Math.round(60e6 / bpm);

    return meta(tick, 0x51, [(us >> 16) & 0xff, (us >> 8) & 0xff, us & 0xff]);
};
const name = (tick, s) => meta(tick, 0x03, text(s));
const program = (channel, value) => ({ tick: 0, data: [0xc0 | channel, value] });

/** A note as on and off; `offAsZero` writes the off as a note-on with velocity 0 (the running-status idiom). */
function note(channel, key, tick, length, velocity, offAsZero = false) {
    return [
        { tick, data: [0x90 | channel, key, velocity] },
        { tick: tick + length, off: true, data: offAsZero ? [0x90 | channel, key, 0] : [0x80 | channel, key, 64] },
    ];
}

// ---- placeholder 1 --------------------------------------------------------

function placeholderOne() {
    // A minor: Am Am F F C C G G, one chord per bar
    const roots = [45, 45, 41, 41, 48, 48, 43, 43];
    const triads = { 45: [57, 60, 64], 41: [53, 57, 60], 48: [55, 60, 64], 43: [55, 59, 62] };
    const conductor = [name(0, 'Placeholder 1'), meta(0, 0x58, [4, 2, 24, 8]), tempo(0, 120)];

    const bass = [name(0, 'Bass'), program(0, 38)];
    roots.forEach((root, bar) => {
        for (let i = 0; i < 8; i++) {
            const key = root - 12 + (i % 4 === 2 ? 12 : 0);
            bass.push(...note(0, key, bar * BAR + i * EIGHTH, EIGHTH - 40, i % 2 === 0 ? 100 : 80));
        }
    });

    const pad = [name(0, 'Pad'), program(1, 89), { tick: 0, data: [0xb1, 7, 90] }];
    roots.forEach((root, bar) => triads[root].forEach((key) => pad.push(...note(1, key, bar * BAR, BAR, 70))));

    // bars 5-8: a four-note phrase per bar
    const melody = [[69, 72, 76, 74], [72, 69, 65, 67], [72, 76, 79, 76], [74, 71, 67, 71]];
    const lead = [name(0, 'Lead'), program(2, 80)];
    melody.forEach((bar, b) => bar.forEach((key, beat) => lead.push(...note(2, key, (4 + b) * BAR + beat * PPQ, PPQ - 60, 90))));

    const drums = [name(0, 'Drums')];
    for (let bar = 0; bar < 8; bar++) {
        const at = bar * BAR;
        [0, 2].forEach((beat) => drums.push(...note(DRUMS, KICK, at + beat * PPQ, 60, 110, true)));
        [1, 3].forEach((beat) => drums.push(...note(DRUMS, SNARE, at + beat * PPQ, 60, 100, true)));
        for (let i = 0; i < 8; i++) {
            drums.push(...note(DRUMS, HAT, at + i * EIGHTH, 30, i % 2 === 0 ? 80 : 55, true));
        }
    }

    return Buffer.from([...header(1, 5), ...track(conductor), ...track(bass), ...track(pad), ...track(lead), ...track(drums, { running: true })]);
}

// ---- placeholder 2 --------------------------------------------------------

function placeholderTwo() {
    // D dorian: Dm7 G Dm7 G | Bb C Dm A, the second half faster
    const roots = [38, 43, 38, 43, 46, 48, 38, 45];
    const chords = { 38: [62, 65, 69], 43: [62, 67, 71], 46: [62, 65, 70], 48: [64, 67, 72], 45: [61, 64, 69] };
    const events = [name(0, 'Placeholder 2'), meta(0, 0x58, [4, 2, 24, 8]), tempo(0, 96), tempo(4 * BAR, 112), program(0, 33), program(1, 4), program(2, 11)];

    roots.forEach((root, bar) => {
        const at = bar * BAR;
        // bass: root, fifth, octave, fifth
        [0, 7, 12, 7].forEach((step, beat) => events.push(...note(0, root - 12 + step, at + beat * PPQ, PPQ - 50, beat === 0 ? 105 : 85, true)));
        // electric piano: stabs on beats 1 and 3
        [0, 2].forEach((beat) => chords[root].forEach((key) => events.push(...note(1, key, at + beat * PPQ, PPQ / 2, 75, true))));
        if (bar >= 4) {
            // vibraphone: the chord up and down in eighths
            const [a, b, c] = chords[root];
            [a, b, c, a + 12, c, b, a, b].forEach((key, i) => events.push(...note(2, key + 12, at + i * EIGHTH, EIGHTH, 60, true)));
            // claps on the snare
            [1, 3].forEach((beat) => events.push(...note(DRUMS, CLAP, at + beat * PPQ, 60, 90, true)));
        }
        [0, 1.5, 2].forEach((beat) => events.push(...note(DRUMS, KICK, at + beat * PPQ, 60, 110, true)));
        [1, 3].forEach((beat) => events.push(...note(DRUMS, SNARE, at + beat * PPQ, 60, 100, true)));
        for (let i = 0; i < 8; i++) {
            events.push(...note(DRUMS, HAT, at + i * EIGHTH, 30, i % 2 === 0 ? 75 : 50, true));
        }
    });
    events.push(...note(DRUMS, CRASH, 4 * BAR, 120, 100, true));

    return Buffer.from([...header(0, 1), ...track(events, { running: true })]);
}

const dir = process.argv[2] ?? join(import.meta.dirname, '..', '..', 'tests', 'js', 'fixtures', 'midi');
mkdirSync(dir, { recursive: true });
writeFileSync(join(dir, 'placeholder-1.mid'), placeholderOne());
writeFileSync(join(dir, 'placeholder-2.mid'), placeholderTwo());
process.stdout.write(`wrote placeholder-1.mid and placeholder-2.mid to ${dir}\n`);
