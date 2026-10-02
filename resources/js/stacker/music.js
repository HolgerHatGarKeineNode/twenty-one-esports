/**
 * Blockfill's own music (plan "Blockfill", P8): four short chiptune loops,
 * written for this game as note data, and the pure parts of the sequencer
 * that plays them (sound.js does the Web Audio side). No audio files and no
 * borrowed melody: every note is below, and docs/plans/…/sound.md lists each
 * piece's key, tempo and motif.
 *
 * A pattern is one token per sixteenth step, bars split by "|" for reading:
 * a note ("A4", "C#5", "Bb3"), "-" holds the note before it one step longer,
 * "." is a rest. A drum line is one character per step: "x" a hit, "X" an
 * accented hit, "." nothing. Nothing in here touches a clock or the browser,
 * so `node --test tests/js/stacker` checks all of it.
 */

export const STEPS_PER_BAR = 16;

/** Pitch classes by name, sharps and flats. */
const PITCH = { C: 0, 'C#': 1, Db: 1, D: 2, 'D#': 3, Eb: 3, E: 4, F: 5, 'F#': 6, Gb: 6, G: 7, 'G#': 8, Ab: 8, A: 9, 'A#': 10, Bb: 10, B: 11 };

/** The notes a piece may use, as pitch classes above its tonic. */
export const SCALES = Object.freeze({
    minor: [0, 2, 3, 5, 7, 8, 10],
    major: [0, 2, 4, 5, 7, 9, 11],
    phrygian: [0, 1, 3, 5, 7, 8, 10],
});

/** "A4" → 69 (MIDI), null for anything else. */
export function midi(name) {
    const match = /^([A-G](?:#|b)?)(-?\d)$/.exec(name);
    if (!match || PITCH[match[1]] === undefined) {
        return null;
    }

    return (Number(match[2]) + 1) * 12 + PITCH[match[1]];
}

export function frequency(note) {
    return 440 * 2 ** ((note - 69) / 12);
}

/**
 * A pitched pattern as notes: [{step, note, length}] (length in steps).
 * Throws on a token it does not know or a hold with nothing to hold.
 */
export function parsePattern(pattern) {
    const tokens = pattern.split(/\s+/).filter((token) => token !== '' && token !== '|');
    const notes = [];
    let last = null;
    tokens.forEach((token, step) => {
        if (token === '.') {
            last = null;

            return;
        }
        if (token === '-') {
            if (last === null) {
                throw new Error(`hold without a note at step ${step}`);
            }
            last.length++;

            return;
        }
        const note = midi(token);
        if (note === null) {
            throw new Error(`unknown token "${token}" at step ${step}`);
        }
        last = { step, note, length: 1 };
        notes.push(last);
    });

    return { steps: tokens.length, notes };
}

/** A drum line as hits: [{step, accent}]. */
export function parseDrums(line) {
    const chars = line.replace(/[\s|]/g, '');
    if (!/^[xX.]*$/.test(chars)) {
        throw new Error(`unknown drum character in "${line}"`);
    }
    const hits = [];
    [...chars].forEach((char, step) => {
        if (char !== '.') {
            hits.push({ step, accent: char === 'X' });
        }
    });

    return { steps: chars.length, hits };
}

/*
 * The pieces. `voices`: pitched parts (wave, level 0-1); `drums`: kick,
 * snare, hat, and tick/tock (the clock of the practice piece). `drive`: how
 * much faster the piece gets from 40 rows left to none (0.1 = 10 %).
 * `climb`: semitones up per loop, back to the start after `climbLoops` loops.
 */
export const PIECES = Object.freeze({
    'number-go-up': {
        title: 'Number Go Up',
        tonic: 'A',
        scale: 'minor',
        bpm: 148,
        bars: 4,
        drive: 0.12,
        swing: 0,
        climb: 1,
        climbLoops: 6,
        voices: {
            lead: {
                wave: 'pulse25',
                level: 0.5,
                pattern: 'A4 . . C5 . . E5 . D5 . . E5 . . G5 . | A5 - - G5 . . E5 . D5 . . C5 . . D5 . | E5 . . G5 . . A5 . B5 . . A5 . . G5 . | A5 - - - - - E5 . A5 - - - . . . .',
            },
            bass: {
                wave: 'triangle',
                level: 0.9,
                pattern: 'A2 . A3 . A2 . A3 . A2 . A3 . A2 . A3 . | F2 . F3 . F2 . F3 . F2 . F3 . F2 . F3 . | G2 . G3 . G2 . G3 . G2 . G3 . G2 . G3 . | A2 . A3 . A2 . A3 . E2 . E3 . G2 . G3 .',
            },
        },
        drums: {
            kick: 'x...x...x...x... | x...x...x...x... | x...x...x...x... | x...x...x..xx...',
            snare: '....x.......x... | ....x.......x... | ....x.......x... | ....x.......X.xx',
            hat: '..x...x...x...x. | ..x...x...x...x. | ..x...x...x...x. | ..x...x...x...x.',
        },
    },
    'tick-tock': {
        title: 'Tick Tock Next Block',
        tonic: 'F',
        scale: 'major',
        bpm: 92,
        bars: 4,
        drive: 0,
        swing: 0,
        climb: 0,
        climbLoops: 1,
        voices: {
            lead: {
                wave: 'triangle',
                level: 0.55,
                pattern: 'C5 - - - . . . . A4 - - - . . . . | Bb4 - - - . . D5 . C5 - - - - - - - | F5 - - - . . . . E5 - - - . . D5 . | C5 - - - - - - - . . . . . . . .',
            },
            bass: {
                wave: 'triangle',
                level: 0.8,
                pattern: 'F2 - - - - - - - C3 - - - - - - - | Bb2 - - - - - - - F2 - - - - - - - | D2 - - - - - - - A2 - - - - - - - | C2 - - - - - - - G2 - - - - - - -',
            },
        },
        drums: {
            tick: 'x.......x....... | x.......x....... | x.......x....... | x.......x.......',
            tock: '....x.......x... | ....x.......x... | ....x.......x... | ....x.......x...',
        },
    },
    'stay-humble': {
        title: 'Stay Humble, Stack Sats',
        tonic: 'Eb',
        scale: 'major',
        bpm: 84,
        bars: 4,
        drive: 0,
        swing: 0.3,
        climb: 0,
        climbLoops: 1,
        voices: {
            lead: {
                wave: 'triangle',
                level: 0.55,
                pattern: 'G5 - - - F5 . Eb5 . F5 - - - . . . . | G5 - - - Bb5 . G5 . F5 - - - Eb5 - - - | C5 - - - Eb5 . F5 . G5 - - - F5 . Eb5 . | D5 - - - - - - - Eb5 - - - - - - -',
            },
            arp: {
                wave: 'pulse12',
                level: 0.28,
                pattern: 'Eb4 . G4 . Bb4 . G4 . Eb4 . G4 . Bb4 . G4 . | C4 . Eb4 . G4 . Eb4 . C4 . Eb4 . G4 . Eb4 . | Ab3 . C4 . Eb4 . C4 . Ab3 . C4 . Eb4 . C4 . | Bb3 . D4 . F4 . D4 . Bb3 . D4 . F4 . D4 .',
            },
            bass: {
                wave: 'triangle',
                level: 0.8,
                pattern: 'Eb2 - - - . . . . Bb2 - - - . . . . | C2 - - - . . . . G2 - - - . . . . | Ab2 - - - . . . . Eb3 - - - . . . . | Bb1 - - - . . . . F2 - - - . . . .',
            },
        },
        drums: {
            kick: 'x.......x....... | x.......x....... | x.......x....... | x.......x.......',
            snare: '....x.......x... | ....x.......x... | ....x.......x... | ....x.......x...',
        },
    },
    'few-understand': {
        title: 'Few Understand',
        tonic: 'E',
        scale: 'phrygian',
        bpm: 168,
        bars: 2,
        drive: 0.1,
        swing: 0,
        climb: 0,
        climbLoops: 1,
        voices: {
            lead: {
                wave: 'pulse25',
                level: 0.45,
                pattern: 'E5 . F5 . . E5 . . B4 . . . C5 . B4 . | E5 . F5 . . G5 . F5 E5 . . . D5 . C5 .',
            },
            bass: {
                wave: 'sawtooth',
                level: 0.5,
                pattern: 'E2 E2 E3 E2 F2 E2 E3 E2 E2 E2 E3 E2 G2 F2 E3 E2 | E2 E2 E3 E2 F2 E2 E3 E2 C3 C3 B2 B2 F2 F2 E2 E2',
            },
        },
        drums: {
            kick: 'x..x..x...x..x.. | x..x..x...x..xx.',
            snare: '....x.......x... | ....x.......x.xx',
            hat: 'xxXxxxXxxxXxxxXx | xxXxxxXxxxXxxxXx',
        },
    },
});

export const PIECE_IDS = Object.freeze(Object.keys(PIECES));

/** Rows left at which the last-stretch piece takes over during a run. */
export const FINAL_ROWS = 10;

export const GOAL_ROWS = 40;

/**
 * Beats per minute of a piece with `remaining` rows left to mine of `goal`
 * (the week's lines, 40 by default): its own tempo with all rows left, up to
 * `drive` faster with none. The fewer rows left, never slower.
 */
export function tempo(id, remaining, goal = GOAL_ROWS) {
    const piece = PIECES[id];
    const all = Number.isInteger(goal) && goal > 0 ? goal : GOAL_ROWS;
    const left = Math.max(0, Math.min(all, Number.isFinite(remaining) ? remaining : all));

    return piece.bpm * (1 + piece.drive * (1 - left / all));
}

/** Seconds per sixteenth step at a tempo. */
export function stepSeconds(bpm) {
    return 60 / bpm / 4;
}

/**
 * Which piece fits the page: the cosy one on the menu and the result, during
 * a run the driving one (ranked) or the calm one (practice), and the dark one
 * for the last ten rows.
 *
 * @param {{mode: string, kind: string, remaining: number}} scene
 */
export function pieceFor({ mode, kind, remaining }) {
    if (mode !== 'playing' && mode !== 'countdown') {
        return 'stay-humble';
    }
    if (mode === 'playing' && remaining <= FINAL_ROWS) {
        return 'few-understand';
    }

    return kind === 'ranked' ? 'number-go-up' : 'tick-tock';
}

/** Semitones a piece is shifted by in loop `loop` (0 first). */
export function transposeFor(id, loop) {
    const piece = PIECES[id];

    return piece.climb === 0 ? 0 : (loop % piece.climbLoops) * piece.climb;
}

/**
 * A piece compiled for playing: per step, the notes that start there.
 * Built once per piece; the scheduler only indexes into it.
 *
 * @returns {{steps: number, at: Array<Array<{voice: string, note?: number, length?: number, drum?: string, accent?: boolean}>>}}
 */
export function compile(id) {
    const piece = PIECES[id];
    const steps = piece.bars * STEPS_PER_BAR;
    const at = Array.from({ length: steps }, () => []);
    for (const [voice, part] of Object.entries(piece.voices)) {
        const parsed = parsePattern(part.pattern);
        if (parsed.steps !== steps) {
            throw new Error(`${id}/${voice}: ${parsed.steps} steps, expected ${steps}`);
        }
        for (const { step, note, length } of parsed.notes) {
            at[step].push({ voice, note, length });
        }
    }
    for (const [drum, line] of Object.entries(piece.drums)) {
        const parsed = parseDrums(line);
        if (parsed.steps !== steps) {
            throw new Error(`${id}/${drum}: ${parsed.steps} steps, expected ${steps}`);
        }
        for (const { step, accent } of parsed.hits) {
            at[step].push({ voice: 'drums', drum, accent });
        }
    }

    return { steps, at };
}

/**
 * When step `index` of a piece sounds, from the start of its bar: swing
 * pushes every second eighth (steps 2, 6, 10, 14) later by `swing` of a step.
 */
export function swingOffset(id, index, seconds) {
    const piece = PIECES[id];

    return index % 4 === 2 ? piece.swing * seconds : 0;
}
