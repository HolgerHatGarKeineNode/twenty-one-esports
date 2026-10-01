<?php

/*
 * Helpers of the score game tests (plan "AoE2 und Trackmania", P4), shared by
 * the files in tests/Feature/Scores (loaded from tests/Pest.php). The demo
 * must be switched on first (Tests\Support\ScoreDemoOn).
 */

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;

/**
 * A running leaderboard of `$n` players of the score demo, its window [start, start + 7 days).
 *
 * @return array{0: Tournament, 1: list<User>}
 */
function runningScoreBoard(int $n, string $mode = 'time-trial', array $attributes = []): array
{
    $tournament = Tournament::factory()->scoreDemo($mode)->create([
        'status' => TournamentStatus::Running,
        'starts_at' => CarbonImmutable::parse('2026-10-05 17:00:00'),
        'capacity' => $n,
        ...$attributes,
    ]);
    $users = [];

    foreach (range(1, $n) as $index) {
        $users[] = $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), $users];
}
