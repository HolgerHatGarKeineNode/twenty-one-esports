/**
 * Which synth voice plays each note of a parsed MIDI file (parse.js), decided once when the file loads, so
 * the player only looks the voice up. Pure: `node --test tests/js/midiParse.test.mjs` checks it.
 *
 * Channel 10 (index 9) is a General MIDI drum kit, the key picks the drum; every other channel plays the
 * General MIDI program family of its program at the note's start. The files want General MIDI programs: a
 * DAW export that leaves every track on program 0 plays as keys throughout (such files were dropped from the
 * playlist on 2026-10-09 for sounding poor).
 *
 * Pitched voices: keys, bell, organ, pluck, drive, bass, strings, pad, brass, lead, saw, timpani.
 * Drums: kick, snare, clap, hat, openhat, crash, ride, tom, cowbell, tick. null: not played (the
 * sound-effect programs 120-127).
 */

/** General MIDI program (0-127) to voice, by range. */
const PROGRAMS = [
    [7, 'keys'], // pianos, electric pianos, harpsichord, clavinet
    [15, 'bell'], // celesta, glockenspiel, music box, vibraphone, marimba, xylophone, tubular bells, dulcimer
    [23, 'organ'],
    [28, 'pluck'], // nylon, steel, jazz, clean and muted guitars
    [31, 'drive'], // overdriven and distortion guitars, harmonics
    [39, 'bass'],
    [45, 'strings'],
    [46, 'pluck'], // harp
    [47, 'timpani'],
    [51, 'strings'], // ensembles and synth strings
    [54, 'pad'], // choir, voice, synth voice
    [63, 'brass'], // orchestra hit, brass, synth brass
    [79, 'lead'], // reeds and pipes
    [80, 'lead'], // square lead
    [81, 'saw'], // sawtooth lead
    [82, 'lead'], // calliope
    [87, 'saw'], // chiff, charang, voice, fifths, bass + lead
    [103, 'pad'], // pads and synth effects
    [111, 'pluck'], // sitar, banjo, shamisen, koto, kalimba, bagpipe, fiddle, shanai
    [114, 'bell'], // tinkle bell, agogo, steel drums
    [119, 'timpani'], // woodblock, taiko, melodic tom, synth drum, reverse cymbal
    [127, null], // sound effects: fret noise, breath, seashore, birds, telephone, helicopter, applause, gunshot
];

export function voiceOfProgram(program) {
    return PROGRAMS.find(([last]) => program <= last)[1];
}

/** A General MIDI drum key (35-81) to a drum voice. */
export function drumOfKey(key) {
    if (key === 35 || key === 36) {
        return 'kick';
    }
    if (key === 38 || key === 40) {
        return 'snare';
    }
    if (key === 39) {
        return 'clap';
    }
    if (key === 42 || key === 44) {
        return 'hat';
    }
    if (key === 46) {
        return 'openhat';
    }
    if (key === 49 || key === 52 || key === 55 || key === 57) {
        return 'crash';
    }
    if (key === 51 || key === 53 || key === 59) {
        return 'ride';
    }
    if ([41, 43, 45, 47, 48, 50, 60, 61, 62, 63, 64].includes(key)) {
        return 'tom';
    }
    if (key === 56) {
        return 'cowbell';
    }

    return 'tick';
}

const DRUMS = new Set(['kick', 'snare', 'clap', 'hat', 'openhat', 'crash', 'ride', 'tom', 'cowbell', 'tick']);

export function isDrum(voice) {
    return DRUMS.has(voice);
}

/**
 * Sets `voice` on every note of `song` and returns how many notes each voice got (for tests and reports).
 *
 * @returns {Record<string, number>}
 */
export function assignVoices(song) {
    const counts = {};
    for (const n of song.notes) {
        n.voice = n.drum ? drumOfKey(n.note) : voiceOfProgram(n.program);
        const key = n.voice ?? 'silent';
        counts[key] = (counts[key] ?? 0) + 1;
    }

    return counts;
}
