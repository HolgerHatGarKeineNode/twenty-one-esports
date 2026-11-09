<?php

/*
 * Helpers of the league week approvals (App\Support\Scores\LeagueWeekDrafts),
 * loaded from tests/Pest.php. Since 2026-10-02 a Blockfill or TMNF week
 * starts only once an admin approved it; a test whose subject is the week
 * itself (its board, its runs, its notes) states that approval as data
 * with leagueWeeksApproved(), the way /admin/league-weeks stores it. The
 * approval itself is tested in tests/Feature/Scores/LeagueWeekApprovalTest.php.
 */

use App\Games\TrackmaniaNationsForever;
use App\Models\LeagueWeek;
use App\Support\Stacker\BlockfillWeeks;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The first Monday (Europe/Berlin) a test may travel to or build its week on:
 * the approvals shipped 2026-10-02 and the earliest fixed date the suite uses
 * is 2026-09-26. A test dated earlier needs its own `before: 0, at: ...`.
 */
const LEAGUE_WEEKS_ANCHOR = '2026-09-14';

/**
 * The weeks of `$game` from `$before` weeks before the week of `$at` (now)
 * to `$after` weeks after it, approved a day before each start; a TMNF week
 * also counts as on its track from its start (the listener saw it there).
 * `$before` null (the default) reaches back to LEAGUE_WEEKS_ANCHOR, so a test
 * that travels to a fixed date finds its week approved whatever the real
 * today is (PageCardsTest went red five weeks after its fixed day when this
 * was a fixed four). `$settings` override the game's defaults (Blockfill bf1,
 * TMNF A01-Race). A week that already has a plan keeps it. Two queries
 * however many weeks: one read of the existing starts, one insert.
 *
 * @param  array<string, mixed>  $settings
 */
function leagueWeeksApproved(string $game, array $settings = [], ?int $before = null, int $after = 4, ?CarbonInterface $at = null): void
{
    $local = CarbonImmutable::instance(BlockfillWeeks::startOf($at ?? now()))->setTimezone(BlockfillWeeks::TIMEZONE);
    $tmnf = $game === TrackmaniaNationsForever::SLUG;
    $defaults = $tmnf ? ['track' => 'BeySZdnfuSh4nHY5xztiXLmlrXe', 'time_limit_minutes' => null] : ['difficulty' => 'bf1'];
    $before ??= max(0, (int) round(CarbonImmutable::parse(LEAGUE_WEEKS_ANCHOR, BlockfillWeeks::TIMEZONE)->diffInDays($local, false) / 7));

    $starts = [];

    for ($offset = -$before; $offset <= $after; $offset++) {
        $starts[] = $local->addWeeks($offset)->startOfDay()->utc();
    }

    $existing = LeagueWeek::query()->where('game', $game)->whereIn('starts_at', $starts)->pluck('starts_at')
        ->map(fn ($start): string => CarbonImmutable::instance($start)->utc()->toDateTimeString())->all();
    $stamp = now()->utc()->toDateTimeString();
    $rows = [];

    foreach ($starts as $start) {
        if (in_array($start->toDateTimeString(), $existing, true)) {
            continue;
        }

        $rows[] = [
            'game' => $game,
            'starts_at' => $start->toDateTimeString(),
            'settings' => json_encode($settings + $defaults, JSON_THROW_ON_ERROR),
            'approved_at' => $start->subDay()->toDateTimeString(),
            'notified_at' => $start->subDays(4)->toDateTimeString(),
            'track_ready_at' => $tmnf ? $start->toDateTimeString() : null,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }

    if ($rows !== []) {
        LeagueWeek::query()->insert($rows);
    }
}
