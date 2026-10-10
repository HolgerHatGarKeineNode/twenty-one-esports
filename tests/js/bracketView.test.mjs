/**
 * The tournament page's 2D/3D switch (resources/js/bracketView.js): a remembered 3D that cannot run (no WebGL,
 * three.js not loading) is stored back as 2D, so the next visit does not try again. Run by
 * tests/Feature/Broadcast/BreakAndBracketTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import bracketView from '../../resources/js/bracketView.js';

/** A browser with `stored` in localStorage and WebGL on or off; `three` decides whether three.js loads. */
function browser(stored, { webgl, three }) {
    const storage = new Map(stored === null ? [] : [['bracket-view', stored]]);
    globalThis.localStorage = { getItem: (k) => storage.get(k) ?? null, setItem: (k, v) => storage.set(k, String(v)) };
    globalThis.window = webgl ? { WebGLRenderingContext: function () {} } : {};
    globalThis.document = {
        createElement: (tag) => (tag === 'canvas'
            ? { getContext: () => (webgl ? {} : null) }
            : { set onerror(fn) { if (!three) queueMicrotask(fn); }, set onload(fn) { if (three) queueMicrotask(fn); } }),
        head: { append: () => {} },
    };
    console.warn = () => {};

    return storage;
}

function mounted() {
    const view = bracketView({ three: '/three.js', url: '/x', id: 1, words: {}, art: {} });
    view.$root = { dataset: {}, querySelector: () => null };
    view.init();

    return view;
}

test('a remembered 3D without WebGL shows 2D and is stored back as 2D', () => {
    const storage = browser('3d', { webgl: false, three: true });
    const view = mounted();

    assert.equal(view.view, '2d');
    assert.equal(storage.get('bracket-view'), '2d');
});

test('a remembered 3D whose three.js fails to load falls back to 2D and is stored back as 2D', async () => {
    const storage = browser('3d', { webgl: true, three: false });
    const view = mounted();
    await new Promise((resolve) => setTimeout(resolve, 10));

    assert.equal(view.state, 'failed');
    assert.equal(view.view, '2d');
    assert.equal(storage.get('bracket-view'), '2d');
});

test('a 2D viewer keeps 2D and nothing is written', () => {
    const storage = browser(null, { webgl: true, three: true });
    const view = mounted();

    assert.equal(view.view, '2d');
    assert.equal(storage.has('bracket-view'), false);
});
