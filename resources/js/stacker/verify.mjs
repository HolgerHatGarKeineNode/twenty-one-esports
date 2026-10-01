/**
 * Blockfill verifier: replays a submitted run on the engine it was issued on.
 *
 * Run by App\Support\Stacker\NodeVerifier as
 *   node --max-old-space-size=64 resources/js/stacker/verify.mjs
 * with one JSON object on stdin:
 *   {replay, seed, engine, claimed: {ticks, hash}, limits: {ticks, inputs, bytes}}
 * and answers one JSON line on stdout, exit code 0:
 *   {ok: true, ticks, lines, pieces, hash, settings}
 *   {ok: false, reason}  reason: malformed | oversize | engine | seed | unfinished | mismatch
 * Anything else (a non-zero exit, no JSON) is a crash; the caller rejects the run.
 */
import { decodeReplay } from './replay.js';

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
export async function verify(request) {
    if (request === null || typeof request !== 'object') {
        return { ok: false, reason: 'malformed' };
    }
    const { replay, seed, engine, claimed, limits } = request;
    if (typeof replay !== 'string' || typeof seed !== 'string' || typeof engine !== 'string'
        || !claimed || !Number.isInteger(claimed.ticks) || typeof claimed.hash !== 'string'
        || !limits || !Number.isInteger(limits.ticks) || !Number.isInteger(limits.inputs) || !Number.isInteger(limits.bytes)) {
        return { ok: false, reason: 'malformed' };
    }
    if (replay.length > limits.bytes) {
        return { ok: false, reason: 'oversize' };
    }
    if (!Object.hasOwn(ENGINES, engine)) {
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

    const { run } = await ENGINES[engine]();
    const result = run(header.seed, header.settings, inputs, { maxTicks: limits.ticks });
    if (!result.finished) {
        return { ok: false, reason: 'unfinished' };
    }
    if (result.ticks !== claimed.ticks || result.stateHash !== claimed.hash) {
        return { ok: false, reason: 'mismatch' };
    }

    return { ok: true, ticks: result.ticks, lines: result.lines, pieces: result.pieces, hash: result.stateHash, settings: header.settings };
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    const text = await readStdin();
    let request = null;
    try {
        request = text === null ? null : JSON.parse(text);
    } catch {
        request = null;
    }
    answer(text === null ? { ok: false, reason: 'oversize' } : await verify(request));
}
