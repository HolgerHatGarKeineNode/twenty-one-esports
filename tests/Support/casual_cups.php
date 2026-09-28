<?php

/*
 * Helpers of the casual cup tests (P25), shared by the files in
 * tests/Feature/Tournaments (loaded from tests/Pest.php).
 */

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentScheduler;

/** The open EU cup of chess, if any. */
function openCup(): ?Tournament
{
    return Tournament::query()->where('cup_open_series', 'chess-eu')->first();
}

/** `$n` keyed players signed up solo to the cup. */
function cupSignups(Tournament $cup, int $n): void
{
    foreach (range(1, $n) as $ignored) {
        [$player, $signer] = keyedPlayer();
        // The league grows a cup with one place left before the next player comes (P27).
        app(CasualCups::class)->grow($cup->refresh(), $cup->signups()->active()->count());
        soloSignup($cup->refresh(), $player, $signer);
    }
}

/**
 * A running cup of `$n` players, bracket stored; no round open yet. Chess
 * double elimination unless a small format (S2) and its game are given.
 *
 * @param  array<string, mixed>  $options
 */
function runningCup(int $n, TournamentFormat $format = TournamentFormat::DoubleElimination, array $options = ['grandFinal' => 'single'], string $game = 'chess', string $mode = 'blitz'): Tournament
{
    $cup = Tournament::factory()->create([
        'name' => 'Chess Casual Cup EU #1', 'format' => $format, 'game' => $game, 'mode' => $mode,
        'options' => FormatOptions::fromArray($options, GameProfile::for($game, $mode))->toArray(),
        'capacity' => 16, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'chess-casual-cup-1-'.fake()->unique()->numberBetween(1, 1_000_000), 'starts_at' => now(), 'created_by_id' => null,
        'cup_series' => "{$game}-eu", 'cup_number' => 1, 'cup_open_series' => "{$game}-eu",
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $cup->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1000 + 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($cup, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($cup);

    return $cup->refresh();
}

function cupTick(): array
{
    return app(TournamentScheduler::class)->tick();
}

/** The ready matches of the cup's open round, with slots and games. */
function openCupMatches(Tournament $cup)
{
    return TournamentMatch::query()->where('tournament_id', $cup->id)->where('status', 'ready')->where('bracket', '!=', 'bye')
        ->whereHas('round', fn ($query) => $query->whereNotNull('window_ends_at'))->with(['slots.participant', 'round.stage', 'chessGame'])->orderBy('id')->get();
}
