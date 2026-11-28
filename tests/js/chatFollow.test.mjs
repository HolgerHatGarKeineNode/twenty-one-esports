/**
 * A chat's message list keeps to its newest line (resources/js/chatFollow.js; 2026-11-27, Pixel 6a: the game
 * chat's history did not scroll up, as justify-content: flex-end left it out of reach): at the end while the
 * reader is there, where the reader scrolled to while they read older lines, at the end again for their own
 * message and whenever the closed sheet opens.
 *
 * Run by tests/Feature/GameChatScrollTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

const { followEnd } = await import('../../resources/js/chatFollow.js');

/** A scroll box of 300 px holding 1000 px of lines, scrolled to the top, as the browser leaves a list that just showed. */
function list({ clientHeight = 300, scrollHeight = 1000 } = {}) {
    const listeners = {};

    return {
        clientHeight,
        scrollHeight,
        _top: 0,
        get scrollTop() {
            return this._top;
        },
        set scrollTop(value) {
            this._top = Math.max(0, Math.min(value, this.scrollHeight - this.clientHeight));
        },
        addEventListener: (type, fn) => (listeners[type] = fn),
        removeEventListener: (type) => delete listeners[type],
        scrollBy(delta) {
            this.scrollTop = this._top + delta;
            listeners.scroll?.();
        },
        listeners,
    };
}

/** A ResizeObserver whose callback the test calls. */
function observer() {
    const made = { callback: null, disconnected: false };

    return {
        made,
        Observer: class {
            constructor(callback) {
                made.callback = callback;
            }

            observe() {}

            disconnect() {
                made.disconnected = true;
            }
        },
    };
}

test('a long chat shows its newest line, and every older line can be scrolled to', () => {
    const box = list();
    const { Observer } = observer();
    const follower = followEnd(box, { Observer });

    follower.update();
    assert.equal(box.scrollTop, 700, 'at the end');

    box.scrollBy(-700);
    assert.equal(box.scrollTop, 0, 'the first line is in reach');
});

test('a message that comes in follows the reader at the end, not the reader who scrolled up to read', () => {
    const box = list();
    const { Observer } = observer();
    const follower = followEnd(box, { Observer });
    follower.update();

    box.scrollHeight = 1100;
    follower.update();
    assert.equal(box.scrollTop, 800, 'at the end: on to the new line');

    box.scrollBy(-400);
    box.scrollHeight = 1200;
    follower.update();
    assert.equal(box.scrollTop, 400, 'scrolled up: stays where the reader reads');

    box.scrollBy(500);
    box.scrollHeight = 1300;
    follower.update();
    assert.equal(box.scrollTop, 1000, 'back at the end: follows again');
});

test('the reader\'s own message brings the list to the end, also from older lines', () => {
    const box = list();
    const { Observer } = observer();
    const follower = followEnd(box, { Observer });
    follower.update();
    box.scrollBy(-600);

    box.scrollHeight = 1100;
    follower.update(true);
    assert.equal(box.scrollTop, 800);
});

test('the closed sheet opens at the newest line, wherever the reader left it', () => {
    const box = list({ clientHeight: 0, scrollHeight: 0 });
    const { made, Observer } = observer();
    const follower = followEnd(box, { Observer });

    // The sheet opens: the list gets its height, the browser shows it from the top.
    Object.assign(box, { clientHeight: 300, scrollHeight: 1000 });
    made.callback();
    assert.equal(box.scrollTop, 700);

    // Read up, close, open again.
    box.scrollBy(-500);
    Object.assign(box, { clientHeight: 0, scrollHeight: 0, _top: 0 });
    made.callback();
    Object.assign(box, { clientHeight: 300, scrollHeight: 1000 });
    made.callback();
    assert.equal(box.scrollTop, 700);

    follower.disconnect();
    assert.equal(made.disconnected, true);
    assert.equal(box.listeners.scroll, undefined);
});
