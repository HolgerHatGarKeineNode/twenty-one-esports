<?php

use App\Support\Formats;
use Carbon\CarbonImmutable;

/*
| Plan "Canvas and live alignment", P1 (G5, B2): German pages group thousands with a dot and write dates day first;
| English pages keep English formats. No ISO dates in visible text.
*/

beforeEach(function () {
    config(['esports.preseason.display_timezone' => 'Europe/Berlin']);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 20:00', 'Europe/Berlin'));
});

test('numbers group thousands with a dot in German and a comma in English, Elo included', function () {
    app()->setLocale('de');
    expect(Formats::number(2100))->toBe('2.100')
        ->and(Formats::number(1116))->toBe('1.116')
        ->and(Formats::number(23310))->toBe('23.310')
        ->and(Formats::number(999))->toBe('999')
        ->and(Formats::number(1.5, 1))->toBe('1,5');

    app()->setLocale('en');
    expect(Formats::number(2100))->toBe('2,100')
        ->and(Formats::number(1.5, 1))->toBe('1.5');
});

test('dates are day first in German and month first in English, the year only when it is not this one', function () {
    $monday = CarbonImmutable::parse('2026-10-05 13:02', 'Europe/Berlin');
    $lastYear = CarbonImmutable::parse('2025-10-03 09:00', 'Europe/Berlin');

    app()->setLocale('de');
    expect(Formats::date($monday))->toBe('Mo. 5.10., 13:02')
        ->and(Formats::date($monday, false))->toBe('Mo. 5.10.')
        ->and(Formats::date($lastYear))->toBe('Fr. 3.10.2025, 09:00')
        ->and(Formats::day($monday))->toBe('5.10.')
        ->and(Formats::day($lastYear))->toBe('3.10.2025')
        ->and(Formats::date(null))->toBe('');

    app()->setLocale('en');
    expect(Formats::date($monday))->toBe('Mon, Oct 5, 1:02 PM')
        ->and(Formats::day($monday))->toBe('Oct 5')
        ->and(Formats::day($lastYear))->toBe('Oct 3, 2025');
});

test('a UTC instant is shown in the league time zone', function () {
    app()->setLocale('de');
    expect(Formats::date(CarbonImmutable::parse('2026-11-08 20:56', 'UTC')))->toBe('So. 8.11., 21:56');
});

test('a text for someone else takes their language, not the page\'s (a notification, P1 gate M3)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-07 20:00', 'Europe/Berlin'));
    app()->setLocale('en');
    $at = CarbonImmutable::parse('2026-10-05 13:02', 'Europe/Berlin');

    expect(Formats::date($at, true, 'Europe/Berlin', 'de'))->toBe('Mo. 5.10., 13:02')
        ->and(Formats::date($at, true, 'Europe/Berlin'))->toBe('Mon, Oct 5, 1:02 PM');
});
