<?php

namespace App\Support\Stacker;

/**
 * The difficulty of a Blockfill week (admin page "League weeks"): which of
 * the frozen engines its ranked runs are played and verified on. The ids are
 * the ones of resources/js/stacker/engine.js ENGINES (bf1 with only its
 * gravity changed); a run is issued on the running week's id
 * (StackerRuns::issue()), its replay names it, the verifier replays it on it,
 * and only a run of the week's id counts for the week (BlockfillWeeks).
 *
 * Gravity is the one rule exposed: the engine has no level curve, no
 * garbage and no per-run settings besides the player's own handling; the
 * preview count is drawn by the page and not part of a verified run, so a
 * week could not hold anyone to it; a shared seed would let a program search
 * the week's piece order offline; and the 40 lines are what makes the times
 * of all weeks comparable.
 */
final class BlockfillDifficulty
{
    /** Engine id => the English label and what it means, as the admin page shows them. */
    public const LEVELS = [
        'bf1' => ['Normal', 'Pieces fall about 1 row per second (the rules of every week so far).'],
        'bf1hard' => ['Hard', 'Pieces fall 3 rows per second.'],
        'bf1expert' => ['Expert', 'Pieces fall 10 rows per second.'],
        'bf1master' => ['Master', '20G: a new piece lands at once and can only slide along the stack.'],
    ];

    /** The difficulty of a week without one of its own (every week before 2026-10, and a game with no plan). */
    public static function default(): string
    {
        $engine = (string) config('esports.blockfill.engine');

        return self::isKnown($engine) ? $engine : 'bf1';
    }

    public static function isKnown(mixed $engine): bool
    {
        return is_string($engine) && array_key_exists($engine, self::LEVELS);
    }

    public static function label(string $engine): string
    {
        return __(self::LEVELS[$engine][0] ?? $engine);
    }

    public static function hint(string $engine): string
    {
        return isset(self::LEVELS[$engine]) ? __(self::LEVELS[$engine][1]) : '';
    }
}
