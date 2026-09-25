/**
 * resources/js/signing.js: signing a template with the player's signer and
 * telling apart why it failed. The error texts are the ones the signers in use
 * throw (nos2x, nos2x-fox, mill's NIP-46 and key signers). Run by
 * tests/Nostr/SigningTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { finalizeEvent, generateSecretKey, getPublicKey, verifyEvent } from 'nostr-tools/pure';
import { DECLINED, FAILED, plainDraft, SignerError, signerFailure, signerMessage, signTemplate, UNREACHABLE, WRONG_KEY } from '../../resources/js/signing.js';

const secret = generateSecretKey();
const pubkey = getPublicKey(secret);

const template = {
    kind: 64,
    created_at: 1_790_372_228,
    tags: [['p', 'a'.repeat(64), '', 'white'], ['p', 'b'.repeat(64), '', 'black'], ['alt', 'Daily chess game #4 (NIP-64 PGN)']],
    content: '1. d4 *\n',
};

/** Like Alpine state: every object and array behind a proxy. */
function reactive(value) {
    if (value === null || typeof value !== 'object') return value;

    return new Proxy(value, { get: (target, key) => reactive(target[key]) });
}

/** A NIP-07 extension: the draft crosses window.postMessage, i.e. a structured clone. */
function extension({ key = secret, fail = null } = {}) {
    return {
        signEvent: async (draft) => {
            const copy = structuredClone(draft);
            if (fail) throw fail;

            return finalizeEvent(copy, key);
        },
    };
}

const messages = {
    rejected: 'declined',
    unreachable: 'no answer',
    wrongKey: 'other key',
    signerFailed: 'could not sign (:reason)',
};

async function quietly(fn) {
    const warned = [];
    const original = console.warn;
    console.warn = (...args) => warned.push(args);
    try {
        return { result: await fn(), warned };
    } catch (error) {
        return { error, warned };
    } finally {
        console.warn = original;
    }
}

test('a template held in reactive state is signed: the signer gets a plain copy', async () => {
    const state = reactive(structuredClone(template));
    assert.throws(() => structuredClone(state.tags), { name: 'DataCloneError' }, 'positive control: a proxy does not clone');

    const draft = plainDraft(state, 1_790_372_000_000);
    assert.doesNotThrow(() => structuredClone(draft));
    assert.deepEqual(draft, template);

    const { result: event, warned } = await quietly(() => signTemplate(state, { pubkey, signer: extension() }));

    assert.equal(event.pubkey, pubkey);
    assert.equal(event.kind, 64);
    assert.deepEqual(event.tags, template.tags);
    assert.ok(verifyEvent(event));
    assert.deepEqual(warned, []);
});

test('created_at never goes back behind the clock', () => {
    assert.equal(plainDraft({ ...template, created_at: 100 }, 5_000_000).created_at, 5000);
    assert.equal(plainDraft({ ...template, created_at: 9_999 }, 5_000_000).created_at, 9_999);
});

test('the signers\' own errors fall into declined, unreachable and failed', () => {
    const cases = [
        ['nos2x: denied', DECLINED],
        ['nos2x-fox: Insufficient permissions, required signEvent', DECLINED],
        ['nos2x-fox: PIN prompt window closed', DECLINED],
        ['Signing rejected: Game Record', DECLINED],
        ['Blocked by your permissions: Game Record', DECLINED],
        ['User rejected', DECLINED],
        ['Prompt was closed', DECLINED],
        ['Password required', DECLINED],
        ['NIP-46 sign_event timed out', UNREACHABLE],
        ['NIP-46 authorization timed out', UNREACHABLE],
        ['Failed to publish NIP-46 request to any relay', UNREACHABLE],
        ['NIP-46 disconnected', UNREACHABLE],
        ['NIP-46 client closed', UNREACHABLE],
        ['Failed to fetch', UNREACHABLE],
        ['nos2x: no private key found', FAILED],
        ['nos2x-fox: invalid event', FAILED],
    ];

    for (const [text, kind] of cases) {
        assert.equal(signerFailure(new Error(text)), kind, text);
    }

    // Some signers reject with a bare string or a plain object.
    assert.equal(signerFailure('denied'), DECLINED);
    assert.equal(signerFailure({ message: 'NIP-46 sign_event timed out' }), UNREACHABLE);
    assert.equal(signerFailure(new DOMException('The object could not be cloned.', 'DataCloneError')), FAILED);
});

test('each failure reaches the player as its own message, and the console gets the real error', async () => {
    const run = (options) => quietly(() => signTemplate(template, { pubkey, ...options }));

    const denied = await run({ signer: extension({ fail: new Error('nos2x-fox: Insufficient permissions, required signEvent') }) });
    const timeout = await run({ signer: extension({ fail: new Error('NIP-46 sign_event timed out') }) });
    const otherKey = await run({ signer: extension({ key: generateSecretKey() }) });
    const broken = await run({ signer: extension({ fail: new Error('nos2x: no private key found') }) });
    const empty = await run({ signer: { signEvent: async () => undefined } });
    const clone = await run({ signer: { signEvent: async () => { throw new DOMException('The object could not be cloned.', 'DataCloneError'); } } });

    for (const outcome of [denied, timeout, otherKey, broken, empty, clone]) {
        assert.ok(outcome.error instanceof SignerError);
        assert.equal(outcome.warned.length, 1, 'one console.warn per failure');
    }

    assert.equal(signerMessage(messages, denied.error), 'declined');
    assert.equal(signerMessage(messages, timeout.error), 'no answer');
    assert.equal(signerMessage(messages, otherKey.error), 'other key');
    assert.equal(signerMessage(messages, broken.error), 'could not sign (nos2x: no private key found)');
    assert.equal(signerMessage(messages, empty.error), 'could not sign (The signer returned no signed event)');
    assert.equal(signerMessage(messages, clone.error), 'could not sign (DataCloneError: The object could not be cloned.)');

    assert.ok(timeout.warned[0].includes(timeout.error.cause), 'the warning carries the signer\'s own error');
    assert.match(String(denied.warned[0][0]), /kind 64 failed \(declined\)/);
});

test('an older page without the new messages still says "not confirmed", and a long reason is cut', () => {
    const old = { rejected: 'declined', wrongKey: 'other key' };
    assert.equal(signerMessage(old, new SignerError(UNREACHABLE, 'x')), 'declined');
    assert.equal(signerMessage(old, new SignerError(FAILED, 'x')), 'declined');
    assert.equal(signerMessage(old, new SignerError(WRONG_KEY, 'x')), 'other key');

    const long = signerMessage(messages, new SignerError(FAILED, new Error('x'.repeat(500))));
    assert.ok(long.length < 200, long.length);
    assert.ok(long.endsWith('…)'));
});
