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
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Support\Chess\ChessGameService;
use App\Support\SeasonChain\TrustFacts;
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
