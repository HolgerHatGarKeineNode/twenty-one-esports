<?php

/*
 * Helpers of the tournament tests (P8b), shared by the files in
 * tests/Feature/Tournaments (loaded from tests/Pest.php).
 */

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentOrganizer;
use App\Models\TournamentParticipant;
use App\Models\TournamentSignup;
use App\Models\User;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Tournaments\TournamentRunner;
use App\Support\Tournaments\TournamentSignups;
use Carbon\CarbonImmutable;
use Tests\Support\TestSigner;

/** An organizer (unlocked by an admin) with a signing key. */
function organizer(): User
{
    $user = User::factory()->create();
    TournamentOrganizer::query()->create(['pubkey' => $user->pubkey]);

    return $user;
}

/**
 * A published tournament, sign-up open for a day.
 *
 * @param  array<string, mixed>  $attributes
 */
function openTournament(array $attributes = [], bool $rocketLeague = false): Tournament
{
    $factory = Tournament::factory();
    $factory = $rocketLeague ? $factory->rocketLeague(TournamentFormat::SingleElimination) : $factory;
    $tournament = $factory->create(['created_by_id' => organizer()->id, ...$attributes]);

    return app(TournamentPublisher::class)->publish($tournament, $tournament->creator, CarbonImmutable::now()->addDay());
}

/** A user with a real key, and the key. */
function keyedPlayer(): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(), $signer];
}

/** A ready RL 3v3 lineup with `$subs` substitutes whose clan owner (captain) holds a key. */
function keyedLineup(int $subs = 0): array
{
    [$captain, $signer] = keyedPlayer();
    $lineup = Lineup::factory()->mode('3v3')->ready($subs)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

function soloSignup(Tournament $tournament, User $user, TestSigner $signer): TournamentSignup
{
    $service = app(TournamentSignups::class);

    return $service->enterSolo($tournament, $user, $signer->signTemplates($service->prepareSolo($tournament, $user)));
}

function lineupSignup(Tournament $tournament, Lineup $lineup, User $captain, TestSigner $signer, ?array $members = null): TournamentSignup
{
    $service = app(TournamentSignups::class);
    $members ??= array_map(fn ($seat) => $seat->user_id, $lineup->activeSeats());

    return $service->enterLineup($tournament, $captain, $lineup->id, $members, $signer->signTemplates($service->prepareLineup($tournament, $captain, $lineup->id, $members)));
}

/**
 * A running chess tournament of `$n` players (seed 1 strongest), bracket
 * stored and synced.
 *
 * @param  array<string, mixed>  $options
 */
function runningChess(TournamentFormat $format, int $n, TournamentResultsMode $mode = TournamentResultsMode::Director, array $options = [], bool $clans = false): Tournament
{
    $tournament = Tournament::factory()->create([
        'format' => $format,
        'options' => FormatOptions::fromArray($options, GameProfile::for('chess', 'blitz'))->toArray(),
        'capacity' => $n,
        'results_mode' => $mode,
        'status' => TournamentStatus::Running,
        'slug' => 'test-cup-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();

        if ($clans) {
            Clan::factory()->create(['owner_id' => $user->id]);
        }

        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The two players of a match, White (slot 0) first. */
function matchPlayers(TournamentMatch $match): array
{
    $match->load('slots.participant');

    return [User::query()->find($match->slots[0]->participant->user_id), User::query()->find($match->slots[1]->participant->user_id)];
}

/**
 * Drive a director tournament to its end: in every open round the better
 * seed wins (slot of the lower seed number), then the round is closed.
 */
function playOutAsDirector(Tournament $tournament): int
{
    $runner = app(TournamentRunner::class);
    $director = $tournament->creator;
    $rounds = 0;

    while ($tournament->refresh()->status === TournamentStatus::Running && $rounds < 40) {
        $round = TournamentRunner::currentRound($tournament);
        expect($round)->not->toBeNull();

        foreach (TournamentMatch::query()->where('tournament_round_id', $round->id)->where('status', 'ready')->with('slots.participant')->get() as $match) {
            $better = $match->slots[0]->participant->seed < $match->slots[1]->participant->seed ? 0 : 1;
            $runner->enterResult($match, $director, ['result' => $better === 0 ? '1-0' : '0-1']);
        }

        $runner->closeRound($round, $director);
        $rounds++;
    }

    return $rounds;
}
