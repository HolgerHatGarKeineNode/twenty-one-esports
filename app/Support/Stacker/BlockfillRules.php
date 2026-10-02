<?php

namespace App\Support\Stacker;

use App\Models\Tournament;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The rules of a Blockfill week (admin page "League weeks", user 2026-10-02:
 * "einstellen können, wie viele Blöcke auch gemint werden sollen und wie
 * schnell das Level steigt"): the lines that finish a run (`goal`), every how
 * many lines the level goes up (`every`, 0: never), and the gravity curve the
 * levels climb: the start level (`start`), the levels of the curve one
 * level-up climbs (`step`) and the highest (`cap`).
 *
 * The curve is the guideline curve of Tetris Worlds that most Guideline games
 * use: a row every (0.8 - (level - 1) * 0.007) ^ (level - 1) seconds (Tetris
 * Wiki, "Marathon", "Speed curve", https://tetris.wiki/Marathon, read
 * 2026-10-02), as GRAVITY_LEVELS in 1/65536 cells per 60 Hz tick, capped at
 * 20G; level 1 is the gravity of every run before the week rules.
 *
 * A rule set IS an engine id (resources/js/stacker/engine.js rulesId(), the
 * same canonical string there and here): `bf1` for today's rules (40 lines,
 * level 1, no level-ups), `t60g1` without level-ups, `t60e5g1s1c9` with them.
 * A run is issued on the week's id (StackerRuns::issue()), its replay header
 * carries it, the verifier replays exactly those rules, and the week's board
 * counts only runs of that id (BlockfillWeeks). The frozen ids of the first
 * league weeks (bf1hard, bf1expert, bf1master; engine.js ENGINES) keep
 * their own rules: 40 lines, no level-ups, their own gravity.
 */
final class BlockfillRules
{
    /** engine.js GRAVITY_LEVELS: the gravity of each level of the curve (index 0 is level 1). */
    public const GRAVITY_LEVELS = [
        1092, 1377, 1768, 2310, 3075, 4168, 5758, 8106, 11634, 17026,
        25415, 38708, 60168, 95483, 154742, 256186, 433424, 749596, 1310720,
    ];

    /** engine.js RULE_LIMITS: inclusive bounds of goal, every (0 besides: no level-ups), a level of the curve, step. */
    public const LIMITS = ['goal' => [10, 100], 'every' => [1, 20], 'level' => [1, 19], 'step' => [1, 3]];

    /** The gravity of the frozen ids (engine.js ENGINES). */
    private const LEGACY_GRAVITY = ['bf1' => 1092, 'bf1hard' => 3277, 'bf1expert' => 10923, 'bf1master' => 1310720];

    /** Quick picks on the admin card: the fields they fill. Normal is today's rules (bf1), the others the old presets on the curve, and the guideline Marathon. */
    public const PRESETS = [
        'normal' => ['goal' => 40, 'every' => 0, 'start' => 1, 'step' => 0, 'cap' => 1],
        'hard' => ['goal' => 40, 'every' => 0, 'start' => 5, 'step' => 0, 'cap' => 5],
        'expert' => ['goal' => 40, 'every' => 0, 'start' => 9, 'step' => 0, 'cap' => 9],
        'master' => ['goal' => 40, 'every' => 0, 'start' => 19, 'step' => 0, 'cap' => 19],
        'marathon' => ['goal' => 40, 'every' => 10, 'start' => 1, 'step' => 1, 'cap' => 15],
    ];

    /** Preset => its English label and what it means, as the admin card shows them. */
    public const PRESET_LABELS = [
        'normal' => ['Normal', 'The rules of every week so far: 40 blocks at about 1 row per second.'],
        'hard' => ['Hard', '40 blocks at about 3 rows per second.'],
        'expert' => ['Expert', '40 blocks at about 10 rows per second.'],
        'master' => ['Master', '40 blocks at 20G: a new piece lands at once and can only slide along the stack.'],
        'marathon' => ['Marathon', 'Guideline Marathon: a level every 10 blocks, one step faster each.'],
    ];

    /** The preset an old frozen id is shown as on the admin card (the nearest level of the curve). */
    private const LEGACY_PRESET = ['bf1' => 'normal', 'bf1hard' => 'hard', 'bf1expert' => 'expert', 'bf1master' => 'master'];

    private const PATTERN = '/^t([1-9][0-9]*)(?:e([1-9][0-9]*)g([1-9][0-9]*)s([1-9][0-9]*)c([1-9][0-9]*)|g([1-9][0-9]*))$/';

    /**
     * The one id of a rule set (engine.js rulesId()); throws on a rule set out of LIMITS or level-ups that change nothing.
     *
     * @param  array{goal: int, every: int, start: int, step?: int, cap?: int}  $rules
     */
    public static function id(array $rules): string
    {
        $goal = $rules['goal'];
        $every = $rules['every'];
        $start = $rules['start'];
        $step = $rules['step'] ?? 0;
        $cap = $rules['cap'] ?? $start;

        if (! self::within($goal, self::LIMITS['goal']) || ! self::within($start, self::LIMITS['level'])) {
            throw new InvalidArgumentException('goal or start level out of range');
        }

        if ($every === 0) {
            if ($step !== 0 || $cap !== $start) {
                throw new InvalidArgumentException('no level-ups: step must be 0 and cap the start level');
            }

            return $goal === 40 && $start === 1 ? 'bf1' : "t{$goal}g{$start}";
        }

        if (! self::within($every, self::LIMITS['every']) || ! self::within($step, self::LIMITS['step']) || ! self::within($cap, self::LIMITS['level']) || $cap <= $start) {
            throw new InvalidArgumentException('level-ups need every, step and a cap above the start level in range');
        }

        return "t{$goal}e{$every}g{$start}s{$step}c{$cap}";
    }

    /**
     * The rules of an engine id: a frozen id (40 lines, no level-ups, its own
     * gravity) or a canonical rules id; null for anything else.
     *
     * @return array{goal: int, every: int, start: int, step: int, cap: int, gravity: int}|null
     */
    public static function of(mixed $engine): ?array
    {
        if (! is_string($engine)) {
            return null;
        }

        if (isset(self::LEGACY_GRAVITY[$engine])) {
            return ['goal' => 40, 'every' => 0, 'start' => 1, 'step' => 0, 'cap' => 1, 'gravity' => self::LEGACY_GRAVITY[$engine]];
        }

        // every group present, null where its branch did not match
        if (preg_match(self::PATTERN, $engine, $match, PREG_UNMATCHED_AS_NULL) !== 1) {
            return null;
        }

        $rules = $match[6] !== null
            ? ['goal' => (int) $match[1], 'every' => 0, 'start' => (int) $match[6], 'step' => 0, 'cap' => (int) $match[6]]
            : ['goal' => (int) $match[1], 'every' => (int) $match[2], 'start' => (int) $match[3], 'step' => (int) $match[4], 'cap' => (int) $match[5]];

        try {
            // canonical only: one rule set has one id (t40g1 is bf1)
            return self::id($rules) === $engine ? $rules + ['gravity' => self::GRAVITY_LEVELS[$rules['start'] - 1]] : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function isKnown(mixed $engine): bool
    {
        return self::of($engine) !== null;
    }

    /** The rules of a week without its own (every week before 2026-10, and a game with no plan): `esports.blockfill.engine`, else bf1. */
    public static function default(): string
    {
        $engine = config('esports.blockfill.engine');

        return self::isKnown($engine) ? (string) $engine : 'bf1';
    }

    /**
     * The preset whose fields these are, if any.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function presetOf(array $fields): ?string
    {
        $ordered = [];

        foreach (array_keys(self::PRESETS['normal']) as $key) {
            $value = filter_var($fields[$key] ?? null, FILTER_VALIDATE_INT);
            $ordered[$key] = $value === false ? null : $value;
        }

        $found = array_search($ordered, self::PRESETS, true);

        return is_string($found) ? $found : null;
    }

    /** The blocks (lines) a run on `$engine` mines; 40 for an unknown id, as on every frozen one. */
    public static function goal(?string $engine): int
    {
        return self::of($engine)['goal'] ?? 40;
    }

    /** "60 blocks": what a run on `$engine` mines, in the current language. */
    public static function blocks(?string $engine): string
    {
        return trans_choice(':count block|:count blocks', self::goal($engine));
    }

    /** "60 blocks" of a week's own rules. */
    public static function weekBlocks(Tournament $week): string
    {
        return self::blocks(app(BlockfillWeeks::class)->difficultyOf($week));
    }

    /**
     * The blocks where no week is in view (the rules page, generic copy): "the week's blocks",
     * and while a week runs with its number and count, "the week's blocks (60 in week 42)".
     */
    public static function weeksBlocks(): string
    {
        $weeks = app(BlockfillWeeks::class);
        $week = $weeks->game() === null ? null : $weeks->current();

        if ($week === null) {
            return __('the week\'s blocks');
        }

        return __('the week\'s blocks (:count in week :week)', [
            'count' => self::goal($weeks->difficultyOf($week)),
            'week' => CarbonImmutable::instance($week->starts_at)->setTimezone(BlockfillWeeks::TIMEZONE)->isoWeek(),
        ]);
    }

    /**
     * The admin card's fields of an engine id: its rules, an old frozen id as the preset it is shown as.
     *
     * @return array{goal: int, every: int, start: int, step: int, cap: int}
     */
    public static function fields(string $engine): array
    {
        if (isset(self::LEGACY_PRESET[$engine])) {
            return self::PRESETS[self::LEGACY_PRESET[$engine]];
        }

        $rules = self::of($engine) ?? self::PRESETS['normal'];

        return ['goal' => $rules['goal'], 'every' => $rules['every'], 'start' => $rules['start'], 'step' => $rules['step'], 'cap' => $rules['cap']];
    }

    /** Whether the id is one of the frozen ids with a gravity of its own, not on the curve (bf1hard, bf1expert, bf1master). */
    public static function isLegacy(string $engine): bool
    {
        return isset(self::LEGACY_GRAVITY[$engine]) && $engine !== 'bf1';
    }

    /**
     * How fast pieces fall at a gravity, as players read it: "1 row/s",
     * "2.8 rows/s", "36 rows/s", "20G" (from 60 rows a second, one row per tick, on).
     */
    public static function speed(int $gravity): string
    {
        $rows = $gravity * 60 / 65536;

        if ($rows >= 60) {
            return self::number($gravity / 65536).'G';
        }

        $shown = $rows < 10 ? round($rows, 1) : round($rows);

        return trans_choice(':count row/s|:count rows/s', $shown === 1.0 ? 1 : 2, ['count' => self::number($shown)]);
    }

    /** The speed of a level of the curve. */
    public static function levelSpeed(int $level): string
    {
        return self::speed(self::GRAVITY_LEVELS[max(1, min(19, $level)) - 1]);
    }

    /**
     * The week's rules as players see them, short: "60 blocks", "Level up every 5 blocks", "Speeds up 1 row/s → 11 rows/s"
     * (no level-ups: "Steady 1 row/s").
     *
     * @return list<string>
     */
    public static function chips(string $engine): array
    {
        $rules = self::of($engine) ?? self::of('bf1');
        $chips = [trans_choice(':count block|:count blocks', $rules['goal'])];

        if ($rules['every'] === 0) {
            $chips[] = __('Steady :speed', ['speed' => self::speed($rules['gravity'])]);

            return $chips;
        }

        $chips[] = trans_choice('Level up every block|Level up every :count blocks', $rules['every']);
        $chips[] = __('Speeds up :from → :to', ['from' => self::levelSpeed($rules['start']), 'to' => self::levelSpeed($rules['cap'])]);

        return $chips;
    }

    /**
     * The rule set in one sentence for the admin card, from its fields (valid or not: an invalid one says what is missing).
     *
     * @param  array<string, mixed>  $fields
     */
    public static function summary(array $fields): string
    {
        $value = fn (string $key): ?int => filter_var($fields[$key] ?? null, FILTER_VALIDATE_INT) === false ? null : (int) $fields[$key];
        $goal = $value('goal');
        $every = $value('every');
        $start = $value('start');

        if ($goal === null || $every === null || $start === null || ! self::within($goal, self::LIMITS['goal']) || ! self::within($start, self::LIMITS['level'])) {
            return __('Fill in the blocks per run and the start speed to see the rules.');
        }

        $end = trans_choice('A run ends after :count block (cleared line).|A run ends after :count blocks (cleared lines).', $goal);

        if ($every === 0) {
            return $end.' '.__('Pieces fall at :speed all run long (level :level of the guideline curve); there are no level-ups.', ['speed' => self::levelSpeed($start), 'level' => $start]);
        }

        $step = $value('step');
        $cap = $value('cap');

        if ($step === null || $cap === null || ! self::within($every, self::LIMITS['every']) || ! self::within($step, self::LIMITS['step']) || ! self::within($cap, self::LIMITS['level']) || $cap <= $start) {
            return $end.' '.__('Pick how much faster each level gets and a top speed above the start.');
        }

        // the level at which the cap is reached, and whether a run of `goal` lines gets there
        $levels = intdiv($cap - $start + $step - 1, $step);
        $capAt = $levels * $every;

        return $end.' '.trans_choice('Every block the level goes up|Every :count blocks the level goes up', $every)
            .' '.trans_choice('and pieces fall one step faster on the guideline curve:|and pieces fall :count steps faster on the guideline curve:', $step)
            .' '.__('from :from (level :start) to at most :to (level :cap).', ['from' => self::levelSpeed($start), 'start' => $start, 'to' => self::levelSpeed($cap), 'cap' => $cap])
            .' '.($capAt < $goal
                ? __('The top speed comes at block :count.', ['count' => $capAt])
                : __('The run ends at level :level, before the top speed.', ['level' => 1 + intdiv($goal - 1, $every)]));
    }

    /** "1", "2.8", "10,6" in German: one decimal at most. */
    private static function number(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return app()->getLocale() === 'de' ? str_replace('.', ',', $text) : $text;
    }

    /**
     * @param  array{0: int, 1: int}  $range
     */
    private static function within(mixed $value, array $range): bool
    {
        return is_int($value) && $value >= $range[0] && $value <= $range[1];
    }
}
