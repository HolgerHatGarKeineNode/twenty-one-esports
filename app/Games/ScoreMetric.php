<?php

namespace App\Games;

use InvalidArgumentException;

/**
 * What a score game measures on one mode (plan "AoE2 und Trackmania", P4):
 * the unit of the stored integer and which way is better.
 *
 * - `ms`: a time in milliseconds, lower is better (a time attack).
 * - `points`: a score in whole points, higher is better (a highscore).
 *
 * A unit fixes its direction by default, but a game may name the other
 * one (a race where the longest distance wins is `ms` + higher). Values
 * are whole numbers: a time is never stored as a float, so two equal times
 * compare equal.
 */
final readonly class ScoreMetric
{
    public const UNITS = ['ms', 'points'];

    public const LOWER = 'lower';

    public const HIGHER = 'higher';

    /**
     * @param  string  $unit  `ms` or `points`
     * @param  string  $better  `lower` or `higher`
     */
    public function __construct(public string $unit, public string $better)
    {
        if (! in_array($unit, self::UNITS, true)) {
            throw new InvalidArgumentException("Unknown score unit [{$unit}].");
        }

        if (! in_array($better, [self::LOWER, self::HIGHER], true)) {
            throw new InvalidArgumentException("Unknown score direction [{$better}].");
        }
    }

    /** A time attack: milliseconds, the lowest time wins. */
    public static function time(): self
    {
        return new self('ms', self::LOWER);
    }

    /** A highscore: points, the highest score wins. */
    public static function points(): self
    {
        return new self('points', self::HIGHER);
    }

    public function lowerIsBetter(): bool
    {
        return $this->better === self::LOWER;
    }

    /**
     * Negative when `$a` is better than `$b`, zero when equal: sorts best first.
     */
    public function compare(int $a, int $b): int
    {
        return $this->lowerIsBetter() ? $a <=> $b : $b <=> $a;
    }

    public function isBetter(int $a, int $b): bool
    {
        return $this->compare($a, $b) < 0;
    }

    /**
     * A value for people: a time as `m:ss.mmm` (`h:mm:ss.mmm` from an hour),
     * points in groups of three digits with a narrow no-break space, which
     * reads the same in English and German.
     */
    public function format(int $value): string
    {
        if ($this->unit === 'points') {
            return ($value < 0 ? '-' : '').number_format(abs($value), 0, '', "\u{202F}");
        }

        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        $ms = $value % 1000;
        $seconds = intdiv($value, 1000) % 60;
        $minutes = intdiv($value, 60_000) % 60;
        $hours = intdiv($value, 3_600_000);

        return $hours > 0
            ? sprintf('%s%d:%02d:%02d.%03d', $sign, $hours, $minutes, $seconds, $ms)
            : sprintf('%s%d:%02d.%03d', $sign, $minutes, $seconds, $ms);
    }

    /**
     * Read a value as people type it: a time as `m:ss.mmm`, `ss.mmm`,
     * `h:mm:ss.mmm` or whole milliseconds; points as a whole number (spaces,
     * dots and commas between digit groups are dropped). Null when it is
     * no valid value of this unit.
     */
    public function parse(string $input): ?int
    {
        $input = trim(str_replace(["\u{202F}", "\u{00A0}", ' '], '', $input));

        if ($this->unit === 'points') {
            $digits = str_replace([',', '.', "'"], '', $input);

            return preg_match('/^\d{1,15}$/', $digits) === 1 ? (int) $digits : null;
        }

        if (preg_match('/^\d{1,12}$/', $input) === 1) {
            return (int) $input;
        }

        if (preg_match('/^(?:(?:(\d+):)?(\d{1,2}):)?(\d{1,2})[.,](\d{1,3})$/', $input, $match) !== 1) {
            return null;
        }

        [, $hours, $minutes, $seconds, $fraction] = $match;

        if (($minutes !== '' && (int) $seconds > 59) || ($hours !== '' && (int) $minutes > 59)) {
            return null;
        }

        return ((int) $hours * 3600 + (int) $minutes * 60 + (int) $seconds) * 1000 + (int) str_pad($fraction, 3, '0');
    }
}
