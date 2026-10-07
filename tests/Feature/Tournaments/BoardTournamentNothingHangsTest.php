<?php

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\NostrEvent;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use App\Support\Tournaments\Engine\Standings;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Nothing hangs, board games (plan "Mühle und Dame", P5 review)
|--------------------------------------------------------------------------
|
| The board games' own ways a players-mode tournament match could block,
| after tests/Feature/Tournaments/TournamentNothingHangsTest.php for chess:
| endless knockout draws (replayed with the colours swapped up to
| `drawn_replays`, then the higher seed advances; no Armageddon), both
| sides missing the first move (restarted once with the same colours, then
| the double no-show rule), and a Black who misses the first move after
| White's (loses by forfeit, unrated, attested as `forfeit` in a live season).
|
*/

beforeEach(function () {
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
});

/**
 * A running players-mode tournament of one board game, bracket stored and
 * synced; rated (the open ladder frozen) when a season is live.
 */
function hangsBoardTournament(string $game, TournamentFormat $format = TournamentFormat::SingleElimination, int $n = 2): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => $game, 'mode' => 'correspondence', 'format' => $format,
        'options' => FormatOptions::fromArray([], GameProfile::for($game, 'correspondence'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-hangs-'.fake()->unique()->numberBetween(1, 1_000_000),
        // Frozen as a publish would freeze it: the open ladder, or none (unrated).
        'ladder_address' => Ladders::address($game, 'correspondence'),
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('cd', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/** The one live board game of a tournament. */
function hangsBoardGame(Tournament $tournament): BoardGame
{
    return BoardGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', BoardGameStatus::Active)->sole();
}

/** Let the first-move deadline of `$game` pass and have the clock checked. */
function hangsMissFirstMove(BoardGame $game): BoardGame
{
    test()->travelTo(CarbonImmutable::createFromTimestampMs((int) $game->refresh()->deadline_ms)->addSecond());

    return app(BoardGameService::class)->checkClock($game)->refresh();
}

function hangsMatch(Tournament $tournament): TournamentMatch
{
    return TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')
        ->with('slots.participant', 'round.stage', 'boardGame', 'tournament')->sole();
}

/* ---------- Endless knockout draws -------------------------------------------------------------------------- */

test('knockout board game: once the drawn replays are used up the higher seed advances instead of another game', function (int $replays) {
    config(['esports.tournaments.drawn_replays' => $replays]);
    $tournament = hangsBoardTournament(NineMensMorris::SLUG);
    $service = app(BoardGameService::class);
    $whites = [];

    foreach (range(1, $replays + 1) as $number) {
        $game = hangsBoardGame($tournament);
        $whites[] = $game->white_id;
        $service->offerDraw($game, $game->white);
        $service->acceptDraw($game->refresh(), $game->black);
    }

    $match = hangsMatch($tournament);

    expect(BoardGame::query()->count())->toBe($replays + 1)
        ->and(BoardGame::query()->where('status', BoardGameStatus::Active)->count())->toBe(0)
        ->and(TournamentMatchMaker::needsBoardGame($match))->toBeFalse()
        // Each replay swaps the colours.
        ->and($whites[0])->not->toBe($whites[1])
        ->and($match->slots[$match->result['winner']]->participant->seed)->toBe(1)
        ->and($match->result['decided'])->toBe('seed')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
})->with(['two draws (one replay)' => 1, 'three draws (the default two replays)' => 2]);

/* ---------- Both sides missing ------------------------------------------------------------------------------ */

test('both sides missing: the board game restarts once with the same colours, then the double no-show rule decides', function (TournamentFormat $format, Closure $check) {
    $tournament = hangsBoardTournament(Checkers::SLUG, $format);
    $first = hangsBoardGame($tournament);

    // A player cannot abort a tournament game.
    expect(fn () => app(BoardGameService::class)->abort($first, $first->black))->toThrow(BoardRuleViolation::class, 'tournament_game');

    hangsMissFirstMove($first);
    $second = hangsBoardGame($tournament);

    expect($first->refresh()->status)->toBe(BoardGameStatus::Aborted)
        ->and($second->tournament_game)->toBe(2)
        ->and($second->white_id)->toBe($first->white_id)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);

    hangsMissFirstMove($second);
    $match = hangsMatch($tournament);

    expect(BoardGame::query()->count())->toBe(2)
        ->and($second->refresh()->status)->toBe(BoardGameStatus::Aborted)
        // A run that saw the match before its result was stored starts no third game either.
        ->and(TournamentMatchMaker::needsBoardGame($match))->toBeFalse()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    $check($tournament, $match);
})->with([
    'round robin: a loss for both, no points' => [TournamentFormat::RoundRobin, function (Tournament $tournament, TournamentMatch $match) {
        $table = Standings::table($tournament->participants()->orderBy('seed')->pluck('id')->all(), app(TournamentRunner::class)->games($tournament));

        expect($match->result['double_loss'])->toBeTrue()
            ->and(array_map(fn ($row) => [$row->points, $row->losses], $table))->toBe([[0.0, 1], [0.0, 1]]);
    }],
    'knockout: the higher seed advances' => [TournamentFormat::SingleElimination, function (Tournament $tournament, TournamentMatch $match) {
        expect($match->slots[$match->result['winner']]->participant->seed)->toBe(1)
            ->and($match->result['decided'])->toBe('noshow');
    }],
]);

/* ---------- Black misses the first move after White's ------------------------------------------------------- */

test('a Black who misses the first move after White\'s loses by forfeit, unrated, and a live season attests it as a forfeit without a block', function () {
    $season = openSeason();
    publishLadders($season);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $tournament = hangsBoardTournament(NineMensMorris::SLUG);
    $game = hangsBoardGame($tournament);

    // Rated: the tournament froze the open ladder, and the gate passed at the pairing.
    expect($game->rated)->toBeTrue()
        ->and($game->ladder_address)->toBe($tournament->ladder_address);

    $game = app(BoardGameService::class)->move($game, $game->white, 'a1', 1);
    $game = hangsMissFirstMove($game);

    $match = hangsMatch($tournament);
    $attestation = SeasonAttestation::query()->sole();
    $tags = NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];
    $whiteSlot = in_array($game->white_id, $match->slots[0]->participant->memberIds(), true) ? 0 : 1;

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->end_reason)->toBe(BoardEndReason::Forfeit->value)
        ->and($game->result)->toBe('1-0')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($match->result['winner'])->toBe($whiteSlot)
        ->and($match->result['forfeit'])->toBeTrue()
        ->and($attestation->only(['source', 'source_id', 'candidate', 'height']))->toBe(['source' => SeasonAttestation::BOARD, 'source_id' => $game->id, 'candidate' => null, 'height' => null])
        ->and($tags)->toContain(['resolution', 'forfeit'], ['winner', 'challenger'], ['a', $tournament->ladder_address, ''])
        ->and(collect($tags)->where(0, 'elo')->all())->toBe([])
        // Tournament games never mine: no block tag at all.
        ->and(collect($tags)->where(0, 'block')->all())->toBe([])
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($season->refresh()->attestations()->count())->toBe(1);
});
