<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Tournament times in the league's zone (config esports.preseason.display_timezone,
 * Europe/Berlin): the zone the league plays in, every admin types a time in,
 * and every tournament surface shows first. The database stores UTC.
 *
 * Reading a typed wall time is explicit about daylight saving instead of
 * trusting PHP: PHP silently moves a time that does not exist (the spring
 * gap, 2026-03-29 02:30) forward to 03:30 and silently picks the later
 * instant of a time that happens twice (the autumn change, 2026-10-25 02:30).
 * Both are refused here with a message; an unchanged value keeps its stored
 * instant, so an edit round trip never drifts.
 *
 * The client twin is resources/js/leagueTime.js (the admin's live preview),
 * tested in tests/js/leagueTime.test.mjs against the strings below.
 */
final class LeagueTime
{
    /** The value of an <input type="datetime-local">. */
    public const INPUT = 'Y-m-d\TH:i';

    /**
     * Date and clock patterns per locale (Carbon isoFormat); English is the fallback.
     *
     * @var array<string, array{long: string, short: string, clock: string, hour: string}>
     */
    private const PATTERNS = [
        'de' => ['long' => 'dd, D. MMMM YYYY', 'short' => 'dd, D. MMM YYYY', 'clock' => 'HH:mm', 'hour' => 'HH:mm [Uhr]'],
        'en' => ['long' => 'ddd, D MMMM YYYY', 'short' => 'ddd, D MMM YYYY', 'clock' => 'h:mm A', 'hour' => 'h:mm A'],
    ];

    public static function zone(): string
    {
        return (string) config('esports.preseason.display_timezone', 'Europe/Berlin');
    }

    /** The zone's city as players name it: "Berlin". */
    public static function city(): string
    {
        $zone = self::zone();
        $city = str_contains($zone, '/') ? substr($zone, (int) strrpos($zone, '/') + 1) : $zone;

        return __(str_replace('_', ' ', $city));
    }

    /** The zone's name in the reader's language: "Europa/Berlin". */
    public static function zoneName(): string
    {
        return __(self::zone());
    }

    /** A stored moment as the value of a datetime-local input in the league's zone; '' for none. */
    public static function input(?CarbonInterface $at): string
    {
        return $at === null ? '' : $at->toImmutable()->setTimezone(self::zone())->format(self::INPUT);
    }

    /**
     * A typed wall time in the league's zone, as UTC. When the value equals
     * `$current` shown as an input, `$current` itself comes back unchanged
     * (seconds included), whatever the rules would say about it now.
     *
     * @throws InvalidArgumentException with the reader's message when the time is malformed, does not exist or happens twice
     */
    public static function parse(string $local, ?CarbonInterface $current = null): CarbonImmutable
    {
        if ($current !== null && self::input($current) === $local) {
            return $current->toImmutable()->utc();
        }

        $candidates = self::candidates($local);

        if ($candidates === null) {
            throw new InvalidArgumentException(__('Enter a date and a time.'));
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        $wall = CarbonImmutable::createFromFormat('!'.self::INPUT, $local, 'UTC');
        $replace = ['time' => self::clockOf($wall), 'date' => self::localized($wall, 'short'), 'city' => self::city()];

        throw new InvalidArgumentException($candidates === []
            ? __(':time does not exist in :city on :date: the clocks jump forward an hour. Pick another time.', $replace)
            : __(':time happens twice in :city on :date: the clocks go back an hour. Pick another time.', $replace));
    }

    /**
     * The validation rule for a datetime-local field typed in the league's zone.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function rule(?CarbonInterface $current = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($current): void {
            try {
                self::parse(is_string($value) ? $value : '', $current);
            } catch (InvalidArgumentException $invalid) {
                $fail($invalid->getMessage());
            }
        };
    }

    /**
     * Every UTC instant whose wall time in the league's zone is `$local`: one
     * normally, none in the spring gap, two in the autumn hour; null when
     * `$local` is not a datetime-local value.
     *
     * @return list<CarbonImmutable>|null
     */
    public static function candidates(string $local): ?array
    {
        $wall = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $local) === 1 ? CarbonImmutable::createFromFormat('!'.self::INPUT, $local, 'UTC') : null;

        if (! $wall instanceof CarbonImmutable || $wall->format(self::INPUT) !== $local) {
            return null;
        }

        $zone = new DateTimeZone(self::zone());
        $offsets = array_unique([$zone->getOffset($wall->subDay()), $zone->getOffset($wall), $zone->getOffset($wall->addDay())]);
        sort($offsets);
        $found = [];

        // Largest offset first: the earlier instant of a repeated hour leads.
        foreach (array_reverse($offsets) as $offset) {
            $utc = $wall->subSeconds($offset);

            if ($zone->getOffset($utc) === $offset) {
                $found[] = $utc;
            }
        }

        return $found;
    }

    /** "MESZ" / "CEST": the zone's abbreviation at that moment, in the reader's language. */
    public static function abbreviation(CarbonInterface $at): string
    {
        return __($at->toImmutable()->setTimezone(self::zone())->format('T'));
    }

    /** "UTC+2" at that moment. */
    public static function offset(CarbonInterface $at): string
    {
        $seconds = $at->toImmutable()->setTimezone(self::zone())->getOffset();

        return self::offsetLabel($seconds);
    }

    public static function offsetLabel(int $seconds): string
    {
        $sign = $seconds < 0 ? '−' : '+';
        $seconds = abs($seconds);
        $minutes = intdiv($seconds % 3600, 60);

        return $seconds === 0 ? 'UTC' : 'UTC'.$sign.intdiv($seconds, 3600).($minutes > 0 ? sprintf(':%02d', $minutes) : '');
    }

    /** "Sa, 3. Oktober 2026" / "Sat, 3 October 2026". */
    public static function date(CarbonInterface $at): string
    {
        return self::localized($at->toImmutable()->setTimezone(self::zone()), 'long');
    }

    /** "20:00 Uhr" / "8:00 PM". */
    public static function hour(CarbonInterface $at): string
    {
        return self::localized($at->toImmutable()->setTimezone(self::zone()), 'hour');
    }

    /**
     * "Sa, 3. Okt 2026, 20:00 MESZ" / "Sat, 3 Oct 2026, 8:00 PM CEST": a card's one-line start.
     * In the league's zone and the app's language, or in a recipient's own
     * zone and language (a reminder: TournamentReminders).
     */
    public static function stamp(CarbonInterface $at, ?string $zone = null, ?string $locale = null): string
    {
        $local = $at->toImmutable()->setTimezone($zone ?? self::zone());
        $locale = $locale === null ? self::locale() : (array_key_exists($locale, self::PATTERNS) ? $locale : 'en');

        return self::iso($local, $locale, self::PATTERNS[$locale]['short']).', '.self::iso($local, $locale, self::PATTERNS[$locale]['clock']).' '.__($local->format('T'), [], $locale);
    }

    /** "18:00 UTC" / "6:00 PM UTC". */
    public static function utc(CarbonInterface $at): string
    {
        return self::clockOf($at->toImmutable()->utc()).' UTC';
    }

    /** "Zeitzone: Europa/Berlin (MESZ, UTC+2)" for a typed value, or for now when it is empty or invalid. */
    public static function zoneLabel(string $local = ''): string
    {
        $at = self::candidates($local)[0] ?? CarbonImmutable::now();

        return __('Time zone: :zone (:abbreviation, :offset)', ['zone' => self::zoneName(), 'abbreviation' => self::abbreviation($at), 'offset' => self::offset($at)]);
    }

    /**
     * What the admin's preview line says for a typed value, the same string
     * resources/js/leagueTime.js builds in the browser.
     *
     * @return array{state: 'ok'|'invalid'|'empty', text: string}
     */
    public static function preview(string $local): array
    {
        if ($local === '') {
            return ['state' => 'empty', 'text' => ''];
        }

        try {
            $at = self::parse($local);
        } catch (InvalidArgumentException $invalid) {
            return ['state' => 'invalid', 'text' => $invalid->getMessage()];
        }

        return ['state' => 'ok', 'text' => '= '.self::stamp($at).' · '.self::utc($at)];
    }

    /**
     * Everything the browser twin needs to build the same strings: the zone,
     * the translated abbreviations by offset, the names, the messages.
     *
     * @return array<string, mixed>
     */
    public static function client(): array
    {
        $locale = self::locale();
        $zone = new DateTimeZone(self::zone());
        $abbreviations = [];

        foreach ($zone->getTransitions(CarbonImmutable::now()->subYears(2)->getTimestamp(), CarbonImmutable::now()->addYears(10)->getTimestamp()) ?: [] as $transition) {
            $abbreviations[(string) $transition['offset']] = __($transition['abbr']);
        }

        $sunday = CarbonImmutable::parse('2026-01-04 12:00', 'UTC');

        return [
            'zone' => self::zone(),
            'locale' => $locale,
            'weekdays' => array_map(fn (int $day): string => self::iso($sunday->addDays($day), $locale, $locale === 'de' ? 'dd' : 'ddd'), range(0, 6)),
            'months' => array_map(fn (int $month): string => self::iso($sunday->setMonth($month), $locale, 'MMM'), range(1, 12)),
            'abbreviations' => $abbreviations,
            'city' => self::city(),
            'zoneName' => self::zoneName(),
            'messages' => [
                'zone' => __('Time zone: :zone (:abbreviation, :offset)'),
                'format' => __('Enter a date and a time.'),
                'gap' => __(':time does not exist in :city on :date: the clocks jump forward an hour. Pick another time.'),
                'twice' => __(':time happens twice in :city on :date: the clocks go back an hour. Pick another time.'),
            ],
        ];
    }

    private static function clockOf(CarbonInterface $local): string
    {
        return self::localized($local, 'clock');
    }

    private static function localized(CarbonInterface $at, string $pattern): string
    {
        $locale = self::locale();

        return self::iso($at, $locale, self::PATTERNS[$locale][$pattern]);
    }

    private static function iso(CarbonInterface $at, string $locale, string $format): string
    {
        return $at->locale($locale)->isoFormat($format);
    }

    private static function locale(): string
    {
        return array_key_exists(app()->getLocale(), self::PATTERNS) ? app()->getLocale() : 'en';
    }
}
