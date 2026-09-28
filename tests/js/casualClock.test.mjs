/**
 * resources/js/casualClock.js: the casual room countdown counts to the
 * server's deadline, whatever the device clock says. Run by
 * tests/Feature/Series/CasualClockTest.php; runnable alone with `node --test`.
 */
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { casualClock, clockText } from '../../resources/js/casualClock.js';

const realNow = Date.now;

afterEach(() => {
    Date.now = realNow;
});

/** A device whose clock runs `skewMs` off the server's. */
function deviceAt(serverMs, skewMs) {
    Date.now = () => serverMs + skewMs;
}

const server = 1_790_000_000_000;
const deadline = server / 1000 + 5 * 60; // five minutes after the render

for (const skew of [0, 7 * 60_000, -7 * 60_000, 3 * 3_600_000]) {
    test(`a device ${skew / 60_000} min off still shows 05:00 at the render and 04:00 a minute later`, () => {
        deviceAt(server, skew);
        const clock = casualClock(deadline, server);
        clock.tick();
        assert.equal(clock.left, '05:00');

        deviceAt(server + 60_000, skew);
        clock.tick();
        assert.equal(clock.left, '04:00');

        deviceAt(server + 6 * 60_000, skew);
        clock.tick();
        assert.equal(clock.left, '00:00');
    });
}

test('without the server time it counts on the device clock, as before', () => {
    deviceAt(server, 7 * 60_000);
    const clock = casualClock(deadline);
    clock.tick();
    assert.equal(clock.left, '00:00');
});

test('hours show beyond an hour', () => {
    assert.equal(clockText(3 * 3600 + 4 * 60 + 5), '3:04:05');
    assert.equal(clockText(59), '00:59');
    assert.equal(clockText(-3), '00:00');
});
