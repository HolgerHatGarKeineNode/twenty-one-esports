/**
 * A small Standard MIDI File reader for the games' background music (Blockfill, Hyperbitcoinization).
 * Nothing in here touches the browser, so `node --test tests/js/midiParse.test.mjs` checks all of it.
 *
 * Reads format 0 and 1 (format 2, independent sequences, is refused), ticks per quarter note or SMPTE
 * timing, running status, every tempo change of every track, note on/off (a note-on with velocity 0 is an
 * off), program changes, channel volume and expression (CC 7, CC 11) and the sustain pedal (CC 64, which
 * holds released notes until the pedal comes up). Sysex and meta events other than tempo are skipped.
 *
 * The result is a flat, time-sorted list of notes in seconds, each with the program of its channel at
 * the moment it started; channel 10 (index 9) is the General MIDI drum channel and marked `drum`.
 */

export const DRUM_CHANNEL = 9;

/** Default tempo of a file without a tempo event: 120 bpm. */
const DEFAULT_TEMPO = 500000;

/**
 * @param {ArrayBuffer|Uint8Array} input
 * @returns {{format: number, tracks: number, division: number, tempos: Array<{time: number, bpm: number}>, duration: number,
 *     notes: Array<{time: number, duration: number, note: number, velocity: number, channel: number, program: number, volume: number, drum: boolean}>}}
 */
export function parseMidi(input) {
    const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    let pos = 0;

    const need = (n) => {
        if (pos + n > bytes.length) {
            throw new Error(`MIDI: unexpected end of file at byte ${pos}`);
        }
    };
    const u8 = () => {
        need(1);

        return bytes[pos++];
    };
    const u16 = () => {
        need(2);
        const v = view.getUint16(pos);
        pos += 2;

        return v;
    };
    const u32 = () => {
        need(4);
        const v = view.getUint32(pos);
        pos += 4;

        return v;
    };
    const tag = () => {
        need(4);
        const s = String.fromCharCode(bytes[pos], bytes[pos + 1], bytes[pos + 2], bytes[pos + 3]);
        pos += 4;

        return s;
    };
    const varLen = () => {
        let v = 0;
        for (let i = 0; i < 4; i++) {
            const b = u8();
            v = (v << 7) | (b & 0x7f);
            if ((b & 0x80) === 0) {
                return v;
            }
        }
        throw new Error(`MIDI: variable-length number too long at byte ${pos}`);
    };

    if (tag() !== 'MThd') {
        throw new Error('MIDI: not a Standard MIDI File (no MThd)');
    }
    const headerLength = u32();
    const headerEnd = pos + headerLength;
    const format = u16();
    const trackCount = u16();
    const division = u16();
    if (format > 1) {
        throw new Error(`MIDI: format ${format} is not supported (0 and 1 are)`);
    }
    if (division === 0) {
        throw new Error('MIDI: division 0');
    }
    pos = headerEnd;

    // Every event of every track with its absolute tick; `order` keeps the file's order for equal ticks.
    const events = [];
    let order = 0;
    for (let track = 0; track < trackCount && pos < bytes.length; track++) {
        const id = tag();
        const length = u32();
        const end = pos + length;
        if (end > bytes.length) {
            throw new Error(`MIDI: track ${track} runs past the end of the file`);
        }
        if (id !== 'MTrk') {
            // an unknown chunk: skipped, as the standard says
            pos = end;
            track--;
            continue;
        }
        let tick = 0;
        let status = 0;
        while (pos < end) {
            tick += varLen();
            const first = u8();
            if (first === 0xff) {
                const type = u8();
                const len = varLen();
                need(len);
                if (type === 0x51 && len === 3) {
                    events.push({ tick, order: order++, kind: 'tempo', value: (bytes[pos] << 16) | (bytes[pos + 1] << 8) | bytes[pos + 2] });
                }
                pos += len;
                if (type === 0x2f) {
                    break;
                }
                continue;
            }
            if (first === 0xf0 || first === 0xf7) {
                const len = varLen();
                need(len);
                pos += len;
                continue;
            }
            let data1;
            if (first & 0x80) {
                status = first;
                data1 = u8();
            } else {
                // running status: the byte read is already the first data byte
                if (status === 0) {
                    throw new Error(`MIDI: data byte without a status at byte ${pos - 1}`);
                }
                data1 = first;
            }
            const type = status & 0xf0;
            const channel = status & 0x0f;
            if (type === 0xc0 || type === 0xd0) {
                if (type === 0xc0) {
                    events.push({ tick, order: order++, kind: 'program', channel, value: data1 });
                }
                continue;
            }
            const data2 = u8();
            if (type === 0x90 && data2 > 0) {
                events.push({ tick, order: order++, kind: 'on', channel, note: data1, velocity: data2 });
            } else if (type === 0x80 || type === 0x90) {
                events.push({ tick, order: order++, kind: 'off', channel, note: data1 });
            } else if (type === 0xb0) {
                events.push({ tick, order: order++, kind: 'cc', channel, controller: data1, value: data2 });
            }
            // 0xa0 aftertouch and 0xe0 pitch bend: read and ignored
        }
        pos = end;
    }

    events.sort((a, b) => a.tick - b.tick || a.order - b.order);

    // Ticks to seconds over the merged tempo map.
    const smpte = (division & 0x8000) !== 0;
    const smpteSecondsPerTick = smpte ? 1 / ((256 - (division >> 8)) * (division & 0xff)) : 0;
    let tempo = DEFAULT_TEMPO;
    let lastTick = 0;
    let lastSeconds = 0;
    const seconds = (tick) => (smpte ? tick * smpteSecondsPerTick : lastSeconds + ((tick - lastTick) * tempo) / 1e6 / division);

    const tempos = [{ time: 0, bpm: 60e6 / DEFAULT_TEMPO }];
    const program = new Array(16).fill(0);
    const volume = new Array(16).fill(100);
    const expression = new Array(16).fill(127);
    const pedal = new Array(16).fill(false);
    const sounding = new Map();
    const held = Array.from({ length: 16 }, () => []);
    const notes = [];
    let endSeconds = 0;

    const close = (entry, at) => {
        entry.duration = Math.max(0.01, at - entry.time);
    };

    for (const event of events) {
        const now = seconds(event.tick);
        endSeconds = Math.max(endSeconds, now);
        if (event.kind === 'tempo') {
            lastSeconds = now;
            lastTick = event.tick;
            tempo = event.value;
            const bpm = 60e6 / tempo;
            if (now === 0) {
                tempos[0].bpm = bpm;
            } else {
                tempos.push({ time: now, bpm });
            }
            continue;
        }
        const ch = event.channel;
        if (event.kind === 'program') {
            program[ch] = event.value;
        } else if (event.kind === 'cc') {
            if (event.controller === 121) {
                // reset all controllers
                volume[ch] = 100;
                expression[ch] = 127;
            } else if (event.controller === 7) {
                volume[ch] = event.value;
            } else if (event.controller === 11) {
                expression[ch] = event.value;
            } else if (event.controller === 64) {
                pedal[ch] = event.value >= 64;
                if (!pedal[ch]) {
                    held[ch].forEach((entry) => close(entry, now));
                    held[ch] = [];
                }
            } else if (event.controller === 123 || event.controller === 120) {
                // all notes off / all sound off
                for (const [key, list] of sounding) {
                    if (key >> 7 === ch) {
                        list.forEach((entry) => close(entry, now));
                        sounding.delete(key);
                    }
                }
            }
        } else if (event.kind === 'on') {
            const key = (ch << 7) | event.note;
            const entry = {
                time: now,
                duration: 0,
                note: event.note,
                velocity: event.velocity,
                channel: ch,
                program: program[ch],
                volume: (volume[ch] / 127) * (expression[ch] / 127),
                drum: ch === DRUM_CHANNEL,
            };
            notes.push(entry);
            if (!sounding.has(key)) {
                sounding.set(key, []);
            }
            sounding.get(key).push(entry);
        } else if (event.kind === 'off') {
            const key = (ch << 7) | event.note;
            const list = sounding.get(key);
            if (!list || list.length === 0) {
                continue;
            }
            // first on, first off: overlapping notes of one pitch end in the order they began
            const entry = list.shift();
            if (pedal[ch]) {
                held[ch].push(entry);
            } else {
                close(entry, now);
            }
        }
    }

    // Notes never released end with the file (and a held pedal with them).
    for (const list of sounding.values()) {
        list.forEach((entry) => close(entry, endSeconds));
    }
    held.forEach((list) => list.forEach((entry) => close(entry, endSeconds)));

    notes.sort((a, b) => a.time - b.time);
    const duration = notes.reduce((max, n) => Math.max(max, n.time + n.duration), endSeconds);

    return { format, tracks: trackCount, division, tempos, duration, notes };
}
