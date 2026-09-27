/**
 * resources/js/leagueTime.js: the admin's live preview reads a typed time in
 * Europe/Berlin exactly like App\Support\LeagueTime, including both 2026 DST
 * changes. Run by tests/Feature/Tournaments/TournamentTimeZoneTest.php, which
 * passes LEAGUE_TIME_FIXTURE: the server's own strings for the same inputs,
 * so the twins are compared, not copied. Runnable alone with `node --test`
 * (the parity test then checks the embedded cases only).
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { candidates, describe } from '../../resources/js/leagueTime.js';
import { startsInText } from '../../resources/js/tournamentLanding.js';

const zone = 'Europe/Berlin';
const iso = (ms) => new Date(ms).toISOString().slice(0, 16);

const en = {
    zone, locale: 'en', city: 'Berlin', zoneName: 'Europe/Berlin',
    weekdays: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
    months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    abbreviations: { 7200: 'CEST', 3600: 'CET' },
    messages: {
        zone: 'Time zone: :zone (:abbreviation, :offset)',
        format: 'Enter a date and a time.',
        gap: ':time does not exist in :city on :date: the clocks jump forward an hour. Pick another time.',
        twice: ':time happens twice in :city on :date: the clocks go back an hour. Pick another time.',
    },
};

test('none in the spring gap, two in the autumn hour (earlier first), one otherwise, null for garbage', () => {
    assert.deepEqual(candidates(zone, '2026-03-29T02:30'), []);
    assert.deepEqual(candidates(zone, '2026-10-25T02:30').map(iso), ['2026-10-25T00:30', '2026-10-25T01:30']);
    assert.deepEqual(candidates(zone, '2026-10-03T20:00').map(iso), ['2026-10-03T18:00']);
    assert.deepEqual(candidates(zone, '2026-12-05T20:00').map(iso), ['2026-12-05T19:00']);
    assert.equal(candidates(zone, '2026-02-30T20:00'), null);
    assert.equal(candidates(zone, '2026-10-03T25:00'), null);
    assert.equal(candidates(zone, ''), null);
});

test('the preview and the zone label follow the typed date', () => {
    assert.deepEqual(describe(en, '2026-10-03T20:00'), {
        state: 'ok', text: '= Sat, 3 Oct 2026, 8:00 PM CEST · 6:00 PM UTC', zone: 'Time zone: Europe/Berlin (CEST, UTC+2)',
    });
    assert.deepEqual(describe(en, '2026-12-05T20:00'), {
        state: 'ok', text: '= Sat, 5 Dec 2026, 8:00 PM CET · 7:00 PM UTC', zone: 'Time zone: Europe/Berlin (CET, UTC+1)',
    });
    assert.equal(describe(en, '2026-03-29T02:30').text, '2:30 AM does not exist in Berlin on Sun, 29 Mar 2026: the clocks jump forward an hour. Pick another time.');
    assert.equal(describe(en, '2026-10-25T02:30').state, 'invalid');
});

test('the browser builds the server\'s strings for every fixture case', () => {
    const path = process.env.LEAGUE_TIME_FIXTURE;
    const fixture = path ? JSON.parse(readFileSync(path, 'utf8')) : { now: Date.parse('2026-09-27T12:00:00Z'), sets: [{ config: en, cases: [
        { input: '2026-10-03T20:00', state: 'ok', text: '= Sat, 3 Oct 2026, 8:00 PM CEST · 6:00 PM UTC', zone: 'Time zone: Europe/Berlin (CEST, UTC+2)' },
    ] }] };

    let compared = 0;
    for (const { config, cases } of fixture.sets) {
        for (const { input, state, text, zone: label } of cases) {
            assert.deepEqual(describe(config, input, fixture.now), { state, text, zone: label }, `${config.locale} ${input}`);
            compared += 1;
        }
    }
    assert.ok(compared > 0);
});

test('starts in: days and hours, then hours and minutes, then minutes', () => {
    const labels = { dh: 'startet in :d T :h Std', hm: 'startet in :h Std :m Min', m: 'startet in :m Min', soon: 'startet in weniger als einer Minute' };
    assert.equal(startsInText(5 * 86400 + 3 * 3600 + 59 * 60, labels), 'startet in 5 T 3 Std');
    assert.equal(startsInText(3 * 3600 + 20 * 60 + 59, labels), 'startet in 3 Std 20 Min');
    assert.equal(startsInText(12 * 60, labels), 'startet in 12 Min');
    assert.equal(startsInText(59, labels), 'startet in weniger als einer Minute');
    assert.equal(startsInText(-5, labels), 'startet in weniger als einer Minute');
});
