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
 * The weeks of `$game` from `$before` weeks before the week of `$at` (now)
 * to `$after` weeks after it, approved a day before each start; a TMNF week
 * also counts as on its track from its start (the listener saw it there).
 * `$settings` override the game's defaults (Blockfill bf1, TMNF A01-Race).
 * A week that already has a plan keeps it.
 *
 * @param  array<string, mixed>  $settings
 */
function leagueWeeksApproved(string $game, array $settings = [], int $before = 4, int $after = 4, ?CarbonInterface $at = null): void
{
    $current = BlockfillWeeks::startOf($at ?? now());
    $tmnf = $game === TrackmaniaNationsForever::SLUG;
    $defaults = $tmnf ? ['track' => 'BeySZdnfuSh4nHY5xztiXLmlrXe', 'time_limit_minutes' => null] : ['difficulty' => 'bf1'];

    for ($offset = -$before; $offset <= $after; $offset++) {
        $start = CarbonImmutable::instance($current)->setTimezone(BlockfillWeeks::TIMEZONE)->addWeeks($offset)->startOfDay()->utc();

        if (LeagueWeek::query()->where('game', $game)->where('starts_at', $start)->exists()) {
            continue;
        }

        LeagueWeek::query()->forceCreate([
            'game' => $game,
            'starts_at' => $start,
            'settings' => $settings + $defaults,
            'approved_at' => $start->subDay(),
            'notified_at' => $start->subDays(4),
            'track_ready_at' => $tmnf ? $start : null,
        ]);
    }
}
