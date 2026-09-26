<?php

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;

/*
|--------------------------------------------------------------------------
| Storing a bracket (P8a)
|--------------------------------------------------------------------------
|
| The engine's bracket lands in stages → rounds → matches → slots, with the
| seeds and groups on the participants; first matches are ready, the rest
| wait for their sources. Runs once per tournament.
|
*/

function tournamentWith(int $participants, TournamentFormat $format, array $options = []): Tournament
{
    $tournament = Tournament::factory()->create([
        'format' => $format,
        'options' => FormatOptions::fromArray($options, GameProfile::for('chess', 'blitz'))->toArray(),
        'capacity' => $participants,
    ]);

    foreach (range(1, $participants) as $index) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index]);
    }

    return $tournament;
}

test('a single-elimination bracket of 13 is stored with its byes, seeds and waiting matches', function () {
    $tournament = tournamentWith(13, TournamentFormat::SingleElimination, ['thirdPlace' => true]);

    app(TournamentBrackets::class)->generate($tournament, 'block-900000');

    $matches = $tournament->matches()->with('slots', 'round')->get();
    $firstRound = $matches->filter(fn (TournamentMatch $match) => $match->round->number === 1);
    $strongest = TournamentParticipant::query()->orderByDesc('rating')->first();

    expect($matches)->toHaveCount(13)
        ->and($tournament->stages()->count())->toBe(1)
        ->and($tournament->stages()->first()->rounds()->count())->toBe(4)
        ->and($firstRound)->toHaveCount(5)
        ->and($firstRound->every(fn (TournamentMatch $match) => $match->status === 'ready' && $match->slots->every(fn ($slot) => $slot->tournament_participant_id !== null)))->toBeTrue()
        ->and($matches->where('status', 'waiting'))->toHaveCount(8)
        ->and($strongest->refresh()->seed)->toBe(1)
        ->and($tournament->refresh()->seed)->toBe('block-900000')
        // Seed 1 has a bye: its first match is in round 2, against the winner of a round-1 match.
        ->and($firstRound->flatMap->slots->pluck('tournament_participant_id'))->not->toContain($strongest->id);
});

test('a two-stage bracket stores both stages and the groups', function () {
    $tournament = tournamentWith(12, TournamentFormat::TwoStage);

    app(TournamentBrackets::class)->generate($tournament, 'seed');

    expect($tournament->stages()->pluck('format')->all())->toBe([TournamentFormat::RoundRobin, TournamentFormat::SingleElimination])
        ->and(TournamentParticipant::query()->whereNotNull('group')->count())->toBe(12)
        ->and(TournamentParticipant::query()->distinct()->pluck('group')->sort()->values()->all())->toBe([1, 2, 3])
        ->and($tournament->matches()->count())->toBe(23)
        // 3 groups, top 2: no two of one group meet in the first round of the final stage.
        ->and($tournament->matches()->where('key', 'like', 'f-m1-%')->get()->map(fn (TournamentMatch $match) => $match->slots->pluck('source.group')->unique()->count())->all())
        ->toBe([2, 2]);
});

test('a Swiss bracket stores round 1 with the bye', function () {
    $tournament = tournamentWith(13, TournamentFormat::Swiss);

    app(TournamentBrackets::class)->generate($tournament, 'seed');

    $bye = $tournament->matches()->where('bracket', 'bye')->sole();

    expect($tournament->matches()->count())->toBe(7)
        ->and($bye->status)->toBe('done')
        ->and($bye->slots->sole()->participant->seed)->toBe(13);
});

test('a bracket is generated once, and never from fewer than 2 participants', function () {
    $tournament = tournamentWith(4, TournamentFormat::RoundRobin);
    app(TournamentBrackets::class)->generate($tournament, 'seed');

    expect(fn () => app(TournamentBrackets::class)->generate($tournament, 'seed'))->toThrow(RuntimeException::class)
        ->and(fn () => app(TournamentBrackets::class)->generate(tournamentWith(1, TournamentFormat::RoundRobin), 'seed'))->toThrow(RuntimeException::class)
        ->and($tournament->matches()->count())->toBe(6);
});
