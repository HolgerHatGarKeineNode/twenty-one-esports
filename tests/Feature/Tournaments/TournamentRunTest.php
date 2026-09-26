<?php

use App\Enums\ChessGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Running tournaments (P8b)
|--------------------------------------------------------------------------
|
| Results move the stored bracket through the P8a engine; every ready match
| is played as a normal match (a chess game here) carrying its tournament
| match; tournament matches count for Elo and never mine season blocks.
|
*/

beforeEach(function () {
    Queue::fake();
});

test('a rated tournament game counts for Elo and never mines a block (guard)', function () {
    $season = openSeason();
    app()->bind(TrustFacts::class, TrustedFacts::class);

    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, clans: true);
    $game = ChessGame::query()->sole();

    expect($game->rated)->toBeTrue()
        ->and($game->tournament_match_id)->toBe(TournamentMatch::query()->sole()->id);

    // The same 20 moves that mine block 1 as a normal rated game (RatedChessTest).
    $moves = ['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6', 'b5a4', 'g8f6', 'e1g1', 'f8e7',
        'f1e1', 'b7b5', 'a4b3', 'd7d6', 'c2c3', 'e8g8', 'h2h3', 'c6b8', 'd2d4', 'b8d7',
        'b1d2', 'c8b7', 'b3c2', 'f8e8', 'd2f1', 'e7f8', 'f1g3', 'g7g6', 'a2a4', 'c7c5',
        'd4d5', 'c5c4', 'c1g5', 'h7h6', 'g5e3', 'd7c5', 'd1d2', 'h6h5', 'e3g5', 'f8e7'];
    $service = app(ChessGameService::class);

    foreach ($moves as $index => $uci) {
        $game = $service->move($game->refresh(), $index % 2 === 0 ? $game->white : $game->black, $uci);
    }

    $service->resign($game->refresh(), $game->black);

    $attestation = SeasonAttestation::query()->sole();
    $tags = NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];

    expect(RatingChange::query()->count())->toBe(2)
        ->and(Rating::query()->where('pool', Rating::RATED)->where('user_id', $game->white_id)->value('rating'))->toBe(1020)
        ->and($attestation->height)->toBeNull()
        ->and($attestation->candidate)->toBeNull()
        ->and($attestation->reward)->toBe(0)
        ->and(collect($tags)->firstWhere(0, 'block'))->toBeNull()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(TournamentMatch::query()->sole()->result['winner'])->toBe(0);
});

test('a whole tournament runs from the first round to its winner', function (TournamentFormat $format, int $n, int $played) {
    $tournament = runningChess($format, $n);

    playOutAsDirector($tournament);

    $done = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->whereNotNull('result')->get();
    $open = TournamentMatch::query()->where('tournament_id', $tournament->id)->whereNotIn('status', ['done', 'skipped'])->count();
    $top = TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('seed', 1)->sole();
    $final = $done->sortByDesc('id')->first();

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($open)->toBe(0)
        ->and($done)->toHaveCount($played)
        // Every result became a finished game record that moved the (casual) Elo.
        ->and(ChessGame::query()->whereNotNull('tournament_match_id')->where('status', ChessGameStatus::Finished)->count())->toBe($played)
        ->and(RatingChange::query()->count())->toBe(2 * $played)
        ->and($tournament->resultEntries()->count())->toBe($played);

    if ($format->hasFinal()) {
        // The best seed wins every match, so it wins the final.
        $final->load('slots');
        expect($final->slots[$final->result['winner']]->tournament_participant_id)->toBe($top->id);
    }
})->with([
    'single elimination, 4' => [TournamentFormat::SingleElimination, 4, 3],
    'single elimination, 8' => [TournamentFormat::SingleElimination, 8, 7],
    'double elimination, 4' => [TournamentFormat::DoubleElimination, 4, 6],
    'double elimination, 8' => [TournamentFormat::DoubleElimination, 8, 14],
    'swiss, 4' => [TournamentFormat::Swiss, 4, 4],
    'swiss, 8' => [TournamentFormat::Swiss, 8, 12],
    'round robin, 4' => [TournamentFormat::RoundRobin, 4, 6],
    'round robin, 8' => [TournamentFormat::RoundRobin, 8, 28],
    'two stage, 4' => [TournamentFormat::TwoStage, 4, 7],
    'two stage, 8' => [TournamentFormat::TwoStage, 8, 15],
]);

/** A running RL 1v1 final between two solo entries (no lineup). */
function rocketLeagueDuel(TournamentResultsMode $mode): array
{
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 2,
        'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => $mode, 'status' => TournamentStatus::Running, 'slug' => 'duel-'.fake()->unique()->numberBetween(1, 1_000_000),
        'ladder_address' => Ladders::address('rocket-league', '1v1'),
    ]);
    $players = [User::factory()->create(), User::factory()->create()];

    foreach ($players as $index => $player) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);

    return [$tournament->refresh(), SeriesMatch::query()->where('tournament_match_id', TournamentMatch::query()->where('tournament_id', $tournament->id)->value('id'))->sole()];
}

test('an RL 1v1 tournament series reported by its players moves the two players\' casual Elo', function () {
    [$tournament, $series] = rocketLeagueDuel(TournamentResultsMode::Players);
    $service = app(SeriesService::class);
    [$a, $b] = [User::query()->find($series->rosterSide('challenger')[0]), User::query()->find($series->rosterSide('challenged')[0])];

    foreach ([[3, 1], [2, 0], [4, 1]] as $index => [$x, $y]) {
        $service->saveLiveGame($series, $a, $index, $x, $y, null);
    }

    $service->report($series, $a, []);
    $service->respond($series, $b, 'confirmed', '', []);

    expect($series->refresh()->rated)->toBeFalse()
        ->and(Rating::query()->where('pool', Rating::CASUAL)->where('game', 'rocket-league')->pluck('subject')->sort()->values()->all())->toBe(collect(['user:'.$a->id, 'user:'.$b->id])->sort()->values()->all())
        ->and(RatingChange::query()->count())->toBe(2)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('an RL 1v1 director series on an open frozen ladder moves the players\' rated Elo and is attested by pubkey', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$tournament, $series] = rocketLeagueDuel(TournamentResultsMode::Director);
    $runner = app(TournamentRunner::class);
    $runner->enterResult(TournamentMatch::query()->where('tournament_id', $tournament->id)->sole(), $tournament->creator, ['games' => [[3, 1], [2, 0], [4, 1]]]);
    $runner->closeRound(TournamentRunner::currentRound($tournament), $tournament->creator);

    $series->refresh();
    [$a, $b] = [User::query()->find($series->rosterSide('challenger')[0]), User::query()->find($series->rosterSide('challenged')[0])];
    $tags = NostrEvent::query()->findOrFail(SeasonAttestation::query()->where('source', 'series')->sole()->nostr_event_id)->payload()['tags'];

    expect($series->rated)->toBeTrue()
        ->and(RatingChange::query()->count())->toBe(2)
        ->and(Rating::query()->where('pool', Rating::RATED)->where('user_id', $a->id)->value('rating'))->toBeGreaterThan(1000)
        ->and(collect($tags)->where(0, 'elo')->pluck(1)->sort()->values()->all())->toBe(collect([$a->pubkey, $b->pubkey])->sort()->values()->all())
        ->and(collect($tags)->where(0, 'a')->filter(fn ($tag) => isset($tag[3]))->count())->toBe(0)
        ->and(collect($tags)->filter(fn ($tag) => $tag[0] === 'p' && isset($tag[3]))->pluck(3)->values()->all())->toBe(['challenger', 'challenged']);
});
