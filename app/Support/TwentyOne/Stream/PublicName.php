<?php

namespace App\Support\TwentyOne\Stream;

/**
 * A player's public name made safe for the stream: the scene picture and the
 * 30311 title/summary both go through here.
 *
 * Other code points (\p{C}: control characters, bidi overrides such as
 * U+202E, zero-width marks, unassigned) are removed; any run of whitespace,
 * newlines included, becomes one space. Nothing is escaped here: that is the
 * job of the output (Blade for the SVG, JSON for the event).
 */
final class PublicName
{
    public static function clean(string $name): string
    {
        $name = preg_replace('/\p{C}+/u', ' ', $name) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    /**
     * clean(), then at most `$max` code points: longer names keep `$max - 1`
     * and end in "…".
     */
    public static function limit(string $name, int $max): string
    {
        $name = self::clean($name);

        return mb_strlen($name) <= $max ? $name : rtrim(mb_substr($name, 0, $max - 1)).'…';
    }
}
