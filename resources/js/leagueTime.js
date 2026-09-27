/**
 * The browser twin of App\Support\LeagueTime: reads a datetime-local value as
 * a wall time in the league's zone (Europe/Berlin) and says what will be
 * saved, live, while the admin types. Same rules as the server: a time in the
 * spring gap does not exist, a time in the autumn hour happens twice, and
 * both are refused rather than silently moved. The server stays the judge;
 * this only shows its verdict early. Tested in tests/js/leagueTime.test.mjs.
 *
 *   leagueTimeInput(config) — Alpine data for <x-berlin-datetime-input>;
 *     `config` is LeagueTime::client().
 */

const pad = (value) => String(value).padStart(2, '0');

/** The zone's UTC offset in seconds at a UTC instant (ms), from the browser's tz database. */
export function offsetAt(zone, ms) {
    const parts = {};
    for (const { type, value } of new Intl.DateTimeFormat('en-US', {
        timeZone: zone, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
    }).formatToParts(new Date(ms))) {
        parts[type] = value;
    }
    const wall = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour) % 24, Number(parts.minute), Number(parts.second));

    return Math.round((wall - Math.floor(ms / 1000) * 1000) / 1000);
}

/**
 * Every UTC instant (ms) whose wall time in `zone` is `local` ("2026-10-03T20:00"):
 * one normally, none in the spring gap, two in the autumn hour (earlier first);
 * null when `local` is not a datetime-local value.
 */
export function candidates(zone, local) {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(String(local ?? ''));
    if (!match) {
        return null;
    }
    const [, y, mo, d, h, mi] = match.map(Number);
    const wall = Date.UTC(y, mo - 1, d, h, mi);
    const check = new Date(wall);
    if (check.getUTCFullYear() !== y || check.getUTCMonth() !== mo - 1 || check.getUTCDate() !== d || h > 23 || mi > 59) {
        return null;
    }
    const day = 86_400_000;
    const offsets = [...new Set([offsetAt(zone, wall - day), offsetAt(zone, wall), offsetAt(zone, wall + day)])].sort((a, b) => b - a);

    return offsets.map((offset) => wall - offset * 1000).filter((utc, i) => offsetAt(zone, utc) === offsets[i]);
}

/** Wall-clock parts of a UTC instant shifted by `offset` seconds. */
function wallParts(ms, offset) {
    const at = new Date(ms + offset * 1000);

    return { y: at.getUTCFullYear(), mo: at.getUTCMonth(), d: at.getUTCDate(), wd: at.getUTCDay(), h: at.getUTCHours(), mi: at.getUTCMinutes() };
}

function clock(parts, locale) {
    if (locale === 'de') {
        return `${pad(parts.h)}:${pad(parts.mi)}`;
    }

    return `${parts.h % 12 === 0 ? 12 : parts.h % 12}:${pad(parts.mi)} ${parts.h < 12 ? 'AM' : 'PM'}`;
}

function shortDate(parts, config) {
    const weekday = config.weekdays[parts.wd];
    const month = config.months[parts.mo];

    return config.locale === 'de' ? `${weekday}, ${parts.d}. ${month} ${parts.y}` : `${weekday}, ${parts.d} ${month} ${parts.y}`;
}

const fill = (template, replace) => Object.entries(replace).reduce((text, [key, value]) => text.replaceAll(`:${key}`, value), template);

/** "UTC+2", as LeagueTime::offsetLabel(). */
export function offsetLabel(seconds) {
    if (seconds === 0) {
        return 'UTC';
    }
    const abs = Math.abs(seconds);
    const minutes = Math.floor((abs % 3600) / 60);

    return `UTC${seconds < 0 ? '−' : '+'}${Math.floor(abs / 3600)}${minutes > 0 ? `:${pad(minutes)}` : ''}`;
}

/** The preview line and the zone label for a typed value, the same strings the server renders. */
export function describe(config, local, now = Date.now()) {
    const found = candidates(config.zone, local);
    const labelAt = found?.[0] ?? now;
    const labelOffset = offsetAt(config.zone, labelAt);
    const zone = fill(config.messages.zone, {
        zone: config.zoneName,
        abbreviation: config.abbreviations[String(labelOffset)] ?? offsetLabel(labelOffset),
        offset: offsetLabel(labelOffset),
    });

    if (local === '' || local === null || local === undefined) {
        return { state: 'empty', text: '', zone };
    }
    if (found === null) {
        return { state: 'invalid', text: config.messages.format, zone };
    }
    if (found.length !== 1) {
        const wall = wallParts(Date.parse(`${local}:00Z`), 0);
        const replace = { time: clock(wall, config.locale), date: shortDate(wall, config), city: config.city };

        return { state: 'invalid', text: fill(found.length === 0 ? config.messages.gap : config.messages.twice, replace), zone };
    }

    const offset = offsetAt(config.zone, found[0]);
    const localParts = wallParts(found[0], offset);
    const abbreviation = config.abbreviations[String(offset)] ?? offsetLabel(offset);

    return {
        state: 'ok',
        text: `= ${shortDate(localParts, config)}, ${clock(localParts, config.locale)} ${abbreviation} · ${clock(wallParts(found[0], 0), config.locale)} UTC`,
        zone,
    };
}

export function leagueTimeInput(config) {
    return {
        state: 'empty',
        text: '',
        zone: '',

        init() {
            this.update(this.$refs.input.value);
        },

        update(value) {
            Object.assign(this, describe(config, value));
        },
    };
}

if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('leagueTimeInput', leagueTimeInput);
    });
}
