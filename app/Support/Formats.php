<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Numbers and dates as people read them on the page (plan "Canvas and live alignment", P1, comparison G5/B2):
 * German pages group thousands with a dot ("2.100", Elo "1.116") and write dates day first ("Mo. 5.10., 13:02",
 * "3.10."); English pages keep English ("2,100", "Mon, Oct 5, 1:02 PM", "Oct 3"). The year shows only when it is
 * not the current one. Never ISO dates in visible text.
 */
final class Formats
{
    /** Thousands grouped in the page's language: "2.100" in German, "2,100" in English. */
    public static function number(int|float $value, int $decimals = 0): string
    {
        return self::german()
            ? number_format($value, $decimals, ',', '.')
            : number_format($value, $decimals);
    }

    /**
     * A day and, by default, its time in the league's (or the given) time zone: "Mo. 5.10., 13:02" /
     * "Mon, Oct 5, 1:02 PM"; another year adds it ("Mo. 5.10.2025, 13:02" / "Mon, Oct 5, 2025, 1:02 PM").
     * `$locale`: a text built for someone else (a notification in its recipient's language); the page's by default.
     */
    public static function date(?CarbonInterface $at, bool $time = true, ?string $zone = null, ?string $locale = null): string
    {
        if ($at === null) {
            return '';
        }

        $local = self::local($at, $zone, $locale);
        $thisYear = $local->year === now($zone ?? LeagueTime::zone())->year;

        if (self::german($locale)) {
            return $local->isoFormat(($thisYear ? 'ddd D.M.' : 'ddd D.M.YYYY').($time ? ', HH:mm' : ''));
        }

        return $local->isoFormat(($thisYear ? 'ddd, MMM D' : 'ddd, MMM D, YYYY').($time ? ', h:mm A' : ''));
    }

    /** A day without weekday and time: "3.10." / "Oct 3", another year "3.10.2025" / "Oct 3, 2025". */
    public static function day(?CarbonInterface $at, ?string $zone = null): string
    {
        if ($at === null) {
            return '';
        }

        $local = self::local($at, $zone);
        $thisYear = $local->year === now($zone ?? LeagueTime::zone())->year;

        if (self::german()) {
            return $local->isoFormat($thisYear ? 'D.M.' : 'D.M.YYYY');
        }

        return $local->isoFormat($thisYear ? 'MMM D' : 'MMM D, YYYY');
    }

    /** The moment in the zone, speaking the page's language. */
    private static function local(CarbonInterface $at, ?string $zone, ?string $locale = null): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone($zone ?? LeagueTime::zone())->settings(['locale' => $locale ?? app()->getLocale()]);
    }

    private static function german(?string $locale = null): bool
    {
        return str_starts_with($locale ?? app()->getLocale(), 'de');
    }
}
