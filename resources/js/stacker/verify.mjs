/**
 * Blockfill verifier: replays a submitted run on the engine it was issued on.
 *
 * Run by App\Support\Stacker\NodeVerifier as
 *   node --max-old-space-size=64 resources/js/stacker/verify.mjs
 * with one JSON object on stdin:
 *   {replay, seed, engine, claimed: {ticks, hash}, limits: {ticks, inputs, bytes, inputsPerTick, inputSlack}}
 * and answers one JSON line on stdout, exit code 0:
 *   {ok: true, ticks, lines, pieces, hash, settings, replay}
 *   {ok: false, reason}  reason: malformed | oversize | engine | seed | unfinished | trailing | mismatch | crash
 * `replay` is the run re-encoded from its decoded inputs (for a valid run the same bytes
 * as submitted). What bounds its size is not the encoding but the checks: inputs after
 * the run ended (`trailing`), more inputs than the played time allows (`oversize`: at
 * most ceil(ticks * inputsPerTick) + inputSlack, so no-op inputs cannot pad a run) and
 * non-canonical varints (`malformed`, refused by the decoder).
 * `crash` is the engine throwing on this replay, caught here: the only crash that
 * rejects a run. No answer at all (a non-zero exit, a missing or broken script,
 * a signal) says nothing about the run, so the caller leaves it pending.
 */
import { realpathSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { decodeReplay, encodeReplay } from './replay.js';

/** Frozen engines by version; a new version adds a line, an old one never goes. */
const ENGINES = {
    bf1: () => import('./engine.js'),
};

const MAX_STDIN = 1 << 20;

function answer(verdict) {
    process.stdout.write(`${JSON.stringify(verdict)}\n`);
}

async function readStdin() {
    const chunks = [];
    let size = 0;
    for await (const chunk of process.stdin) {
        size += chunk.length;
        if (size > MAX_STDIN) {
            return null;
        }
        chunks.push(chunk);
    }

    return Buffer.concat(chunks).toString('utf8');
}

/**
 * The verdict for one request object.
 *
 * @param {any} request
 */
export async function verify(request, engines = ENGINES) {
    if (request === null || typeof request !== 'object') {
        return { ok: false, reason: 'malformed' };
    }
    const { replay, seed, engine, claimed, limits } = request;
    if (typeof replay !== 'string' || typeof seed !== 'string' || typeof engine !== 'string'
        || !claimed || !Number.isInteger(claimed.ticks) || typeof claimed.hash !== 'string'
        || !limits || !Number.isInteger(limits.ticks) || !Number.isInteger(limits.inputs) || !Number.isInteger(limits.bytes)
        || typeof limits.inputsPerTick !== 'number' || !(limits.inputsPerTick > 0) || !Number.isInteger(limits.inputSlack)) {
        return { ok: false, reason: 'malformed' };
    }
    if (replay.length > limits.bytes) {
        return { ok: false, reason: 'oversize' };
    }
    if (!Object.hasOwn(engines, engine)) {
        return { ok: false, reason: 'engine' };
    }

    let decoded;
    try {
        decoded = decodeReplay(replay);
    } catch {
        return { ok: false, reason: 'malformed' };
    }
    const { header, inputs } = decoded;
    if (header.engine !== engine) {
        return { ok: false, reason: 'engine' };
    }
    if (header.seed !== seed) {
        return { ok: false, reason: 'seed' };
    }
    if (inputs.length > limits.inputs || (inputs.length > 0 && inputs[inputs.length - 1][0] > limits.ticks)) {
        return { ok: false, reason: 'oversize' };
    }

    let result;
    try {
        const { run } = await engines[engine]();
        result = run(header.seed, header.settings, inputs, { maxTicks: limits.ticks });
    } catch {
        return { ok: false, reason: 'crash' };
    }
    if (!result.finished) {
        return { ok: false, reason: 'unfinished' };
    }
    // the last tick played is result.ticks - 1: an input at result.ticks or later was never used
    if (inputs.some(([tick]) => tick >= result.ticks)) {
        return { ok: false, reason: 'trailing' };
    }
    if (inputs.length > Math.ceil(result.ticks * limits.inputsPerTick) + limits.inputSlack) {
        return { ok: false, reason: 'oversize' };
    }
    if (result.ticks !== claimed.ticks || result.stateHash !== claimed.hash) {
        return { ok: false, reason: 'mismatch' };
    }

    return {
        ok: true,
        ticks: result.ticks,
        lines: result.lines,
        pieces: result.pieces,
        hash: result.stateHash,
        settings: header.settings,
        replay: encodeReplay(header, inputs),
    };
}

/**
 * Reads the request from stdin and writes the verdict to stdout.
 *
 * @param {Record<string, () => Promise<{run: Function}>>} [engines]
 */
export async function main(engines = ENGINES) {
    const text = await readStdin();
    let request = null;
    try {
        request = text === null ? null : JSON.parse(text);
    } catch {
        request = null;
    }
    answer(text === null ? { ok: false, reason: 'oversize' } : await verify(request, engines));
}

/** True when this file is the script Node was started with, also through a symlink (a release directory). */
function isMainModule() {
    if (!process.argv[1]) {
        return false;
    }
    try {
        return realpathSync(fileURLToPath(import.meta.url)) === realpathSync(process.argv[1]);
    } catch {
        return false;
    }
}

if (isMainModule()) {
    await main();
}
