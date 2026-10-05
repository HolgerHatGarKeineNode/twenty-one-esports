/**
 * The site-wide hide of muted and banned keys (resources/js/siteHidden.js):
 * the config's list cleaned and without the viewer's own key, a push
 * applied once and only for a real key, a hidden author's messages and
 * zaps left out while everyone else's stay, and every chat of a page
 * sharing one list. Run by tests/Feature/SiteModerationTest.php; runnable
 * alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { MAX_HIDDEN, applyChange, hiddenSet, hideOnPage, visible, watchSiteHidden } from '../../resources/js/siteHidden.js';

const anna = 'a'.repeat(64);
const bert = 'b'.repeat(64);
const spam = 'c'.repeat(64);

test('the list is cleaned: hex keys only, bounded, never the viewer', () => {
    assert.deepEqual([...hiddenSet([spam, 'nope', 42, spam.toUpperCase(), anna], anna)], [spam]);
    assert.deepEqual([...hiddenSet(null)], []);
    const many = Array.from({ length: MAX_HIDDEN + 5 }, (_, i) => i.toString(16).padStart(64, '0'));
    assert.equal(hiddenSet(many).size, MAX_HIDDEN);
});

test('a push hides and shows a key once, and never the viewer or junk', () => {
    const set = new Set();
    assert.equal(applyChange(set, { pubkey: spam, hidden: true }), true);
    assert.equal(applyChange(set, { pubkey: spam, hidden: true }), false);
    assert.equal(applyChange(set, { pubkey: anna, hidden: true }, anna), false);
    assert.equal(applyChange(set, { pubkey: 'x', hidden: true }), false);
    assert.equal(applyChange(set, { pubkey: bert, hidden: 'yes' }), false);
    assert.deepEqual([...set], [spam]);
    assert.equal(applyChange(set, { pubkey: spam, hidden: false }), true);
    assert.deepEqual([...set], []);
});

test('a hidden author\'s messages and zaps are left out, everyone else\'s stay in order', () => {
    const items = [
        { id: '1', type: 'message', pubkey: anna },
        { id: '2', type: 'message', pubkey: spam },
        { id: '3', type: 'zap', pubkey: spam },
        { id: '4', type: 'message', pubkey: bert },
    ];
    assert.deepEqual(visible(items, new Set([spam])).map((item) => item.id), ['1', '4']);
    assert.equal(visible(items, new Set()), items);
});

test('the chats of a page share one list: a key hidden by one menu leaves every chat, the viewer\'s own never', () => {
    globalThis.window = {};
    const seen = { a: [], b: [] };
    const stopA = watchSiteHidden([spam], anna, (set) => { seen.a = [...set]; });
    const stopB = watchSiteHidden([], bert, (set) => { seen.b = [...set]; });
    assert.deepEqual(seen.a, [spam]);
    assert.deepEqual(seen.b, [spam]);

    hideOnPage(anna);
    assert.deepEqual(seen.a, [spam]);
    assert.deepEqual(seen.b.sort(), [anna, spam].sort());

    stopA();
    stopB();
    hideOnPage(bert);
    assert.equal(seen.b.includes(bert), false);
});
