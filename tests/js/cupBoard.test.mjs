/**
 * The cup board's start in the browser (resources/js/tournamentLanding.js):
 * the same day, clock and city App\Support\Tournaments\CupBoard::start()
 * writes on the server, and no rewrite for a zone a privacy browser reports
 * instead of the real one. Run by tests/Feature/Tournaments/CupBoardTest.php.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { boardStart, countdown, cupStart, SPOOFED_ZONES } from '../../resources/js/tournamentLanding.js';

// Saturday 10 October 2026, 18:00 UTC: 20:00 in Berlin, 14:00 in New York.
const at = Date.UTC(2026, 9, 10, 18, 0);

test('the start reads like the server writes it, in English and German', () => {
    assert.deepEqual(boardStart(at, 'America/New_York', 'en'), { day: 'Sat, Oct 10', clock: '2:00 PM', city: 'New York' });
    assert.deepEqual(boardStart(at, 'Europe/Berlin', 'de'), { day: 'Sa, 10. Okt', clock: '20:00', city: 'Berlin' });
    assert.deepEqual(boardStart(Date.UTC(2026, 9, 11, 0, 0), 'Europe/Berlin', 'de'), { day: 'So, 11. Okt', clock: '02:00', city: 'Berlin' });
});

test('the spoofed zones of a fingerprint-resisting browser are named', () => {
    for (const zone of ['UTC', 'Atlantic/Reykjavik', 'Etc/UTC']) {
        assert.ok(SPOOFED_ZONES.includes(zone), zone);
    }
    assert.ok(!SPOOFED_ZONES.includes('Europe/London'));
});

/** cupStart's words after init() in a browser whose zone is `own`. */
function rewrite(own, zone) {
    const original = Intl.DateTimeFormat;
    globalThis.document = { documentElement: { lang: 'en' } };
    Intl.DateTimeFormat = function (...args) {
        const formatter = new original(...args);

        return args.length === 0 ? { resolvedOptions: () => ({ ...formatter.resolvedOptions(), timeZone: own }) } : formatter;
    };

    try {
        const row = cupStart({ at, zone });
        row.init();

        return { day: row.day, clock: row.clock, city: row.city };
    } finally {
        Intl.DateTimeFormat = original;
    }
}

test('a real zone on another offset rewrites the row; one on the same offset keeps it', () => {
    assert.deepEqual(rewrite('America/Chicago', 'Europe/Berlin'), { day: 'Sat, Oct 10', clock: '1:00 PM', city: 'Chicago' });
    assert.deepEqual(rewrite('Europe/Vienna', 'Europe/Berlin'), { day: '', clock: '', city: '' });
});

test('a spoofed zone keeps the region clock the server wrote (the "GMT+0" of 2026-09-28)', () => {
    assert.deepEqual(rewrite('Atlantic/Reykjavik', 'Europe/Berlin'), { day: '', clock: '', city: '' });
    assert.deepEqual(rewrite('UTC', 'America/New_York'), { day: '', clock: '', city: '' });
});

test('the countdown at zero tells the page around it once, then asks the server', () => {
    const events = [];
    let refreshed = 0;
    globalThis.CustomEvent ??= class extends Event { constructor(type, init) { super(type, init); this.detail = init?.detail; } };
    const clock = countdown({ at: Date.now() - 1000 });
    Object.assign(clock, {
        $el: { textContent: '00:00:01', dispatchEvent: (event) => events.push([event.type, event.bubbles]) },
        $wire: { $refresh: () => { refreshed += 1; } },
    });
    clock.init();
    clock.destroy();

    assert.equal(clock.text, '00:00:00');
    assert.deepEqual(events, [['countdown-zero', true]]);
    assert.equal(refreshed, 1);
});
