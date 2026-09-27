<?php

use App\Enums\ChessEndReason;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Livewire\Actions\DeleteAccount;
use App\Models\Admin;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessRuleViolation;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use App\Support\Tournaments\Engine\Standings;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Nothing hangs (P18, slice 1)
|--------------------------------------------------------------------------
|
| Every way a players-mode tournament match could block forever ends in a
| decided match: a missed first move (forfeit, or restart and then the
| double no-show rule), a voided series (replayed), an accepted series
| nobody reports (an admin decides), a report nobody answers (admin queue),
| a deleted account (withdrawn, forfeits) and endless knockout draws (the
| higher seed advances). The league's own forfeits move no Elo.
|
*/

beforeEach(function () {
    Queue::fake();
});

/** The one live chess game of a tournament match. */
function p18Game(Tournament $tournament): ChessGame
{
    return ChessGame::query()->whereIn('tournament_match_id', $tournament->matches()->select('id'))->where('status', ChessGameStatus::Active)->sole();
}

/** Let the first-move deadline of `$game` pass and have the clock checked. */
function p18MissFirstMove(ChessGame $game): ChessGame
{
    test()->travelTo(CarbonImmutable::createFromTimestampMs((int) $game->refresh()->deadline_ms)->addSecond());

    return app(ChessGameService::class)->checkClock($game);
}

/**
 * A running players-mode RL 1v1 final between two solo players (casual: no
 * season), its series paired.
 *
 * @return array{0: Tournament, 1: SeriesMatch, 2: User, 3: User}
 */
function p18Duel(): array
{
    $tournament = Tournament::factory()->create([
        'game' => 'rocket-league', 'mode' => '1v1', 'format' => TournamentFormat::SingleElimination, 'capacity' => 2,
        'options' => FormatOptions::defaults(GameProfile::for('rocket-league', '1v1'))->toArray(),
        'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running, 'slug' => 'p18-duel-'.fake()->unique()->numberBetween(1, 1_000_000),
    ]);
    $players = [User::factory()->create(), User::factory()->create()];

    foreach ($players as $index => $player) {
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'name' => $player->displayName(), 'rating' => 1100 - $index, 'members' => [$player->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ef', 32));
    app(TournamentRunner::class)->sync($tournament);
    $series = SeriesMatch::query()->where('tournament_match_id', $tournament->matches()->value('id'))->sole();

    return [$tournament->refresh(), $series, User::query()->findOrFail($series->rosterSide('challenger')[0]), User::query()->findOrFail($series->rosterSide('challenged')[0])];
}

/** `$winner` enters a clean 2-0 and reports it (casual: nothing to sign). */
function p18Report(SeriesMatch $series, User $winner): SeriesMatch
{
    $service = app(SeriesService::class);
    $challenger = $series->refresh()->captainSideOf($winner) === 'challenger';

    foreach (range(0, intdiv($series->best_of, 2)) as $index) {
        $service->saveLiveGame($series, $winner, $index, $challenger ? 3 : 1, $challenger ? 1 : 3, null);
    }

    $service->report($series, $winner, []);

    return $series->refresh();
}

function p18Admin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/* ---------- G1: a missed first move ------------------------------------------------------------------------- */

test('blitz: a side that misses its first move loses the tournament match by forfeit, attested without Elo', function () {
    openSeason();
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, clans: true);
    $game = p18Game($tournament);
    $service = app(ChessGameService::class);

    // Tournament window, not the global 30 s; and a player cannot abort a tournament game.
    expect($game->rated)->toBeTrue()
        ->and($game->deadline_ms - $game->turn_started_ms)->toBe(300_000)
        ->and(fn () => $service->abort($game, $game->black))->toThrow(ChessRuleViolation::class, 'tournament_game');

    $game = $service->move($game, $game->white, 'e2e4');
    expect($game->deadline_ms - $game->turn_started_ms)->toBe(300_000);

    $game = p18MissFirstMove($game);
    $tags = NostrEvent::query()->findOrFail(SeasonAttestation::query()->sole()->nostr_event_id)->payload()['tags'];
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole();

    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->end_reason)->toBe(ChessEndReason::Forfeit)
        ->and($game->result)->toBe('1-0')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($tags)->toContain(['resolution', 'forfeit'], ['winner', 'challenger'])
        ->and(collect($tags)->where(0, 'elo')->all())->toBe([])
        ->and($match->result['winner'])->toBe(0)
        ->and($match->result['forfeit'])->toBeTrue()
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('the board of a tournament game offers no abort before the first moves; a normal game still does', function () {
    $game = p18Game(runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players));
    [$white, $black] = [User::factory()->create(), User::factory()->create()];
    $casual = app(ChessGameService::class)->start($white, $black);

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertDontSee('data-test="abort"', false)->assertSee('data-test="resign"', false);
    $this->actingAs($white)->get(route('games.show', $casual))->assertOk()
        ->assertSee('data-test="abort"', false);
});

test('daily: White who never moves within the day loses by forfeit to a Black who opened the board', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, chessMode: 'correspondence');
    $game = p18Game($tournament);

    expect($game->deadline_ms - $game->turn_started_ms)->toBe(86_400_000);

    app(ChessGameService::class)->markPresent($game, $game->black);
    $game = p18MissFirstMove($game);

    expect($game->status)->toBe(ChessGameStatus::Finished)
        ->and($game->end_reason)->toBe(ChessEndReason::Forfeit)
        ->and($game->result)->toBe('0-1')
        ->and(RatingChange::query()->count())->toBe(0)
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->sole()->result['winner'])->toBe(1)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('both sides missing: the game restarts once with the same colours, then the double no-show rule decides', function (TournamentFormat $format, Closure $check) {
    $tournament = runningChess($format, 2, TournamentResultsMode::Players);
    $first = p18Game($tournament);

    p18MissFirstMove($first);
    $second = p18Game($tournament);

    expect($first->refresh()->status)->toBe(ChessGameStatus::Aborted)
        ->and($second->tournament_game)->toBe(2)
        ->and($second->white_id)->toBe($first->white_id)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);

    p18MissFirstMove($second);

    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant', 'round.stage', 'chessGame')->sole();

    expect(ChessGame::query()->count())->toBe(2)
        ->and($second->refresh()->status)->toBe(ChessGameStatus::Aborted)
        // A run that saw the match before its result was stored starts no third game either.
        ->and(TournamentMatchMaker::needsGame($match))->toBeFalse()
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

/* ---------- G14: endless knockout draws --------------------------------------------------------------------- */

test('knockout chess: after three draws the higher seed advances instead of a fourth game', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
    $service = app(ChessGameService::class);
    $whites = [];

    foreach (range(1, 3) as $number) {
        $game = p18Game($tournament);
        $whites[] = $game->white_id;
        $service->offerDraw($game, $game->white);
        $service->acceptDraw($game->refresh(), $game->black);
    }

    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->with('slots.participant', 'round.stage', 'chessGame')->sole();

    expect(ChessGame::query()->count())->toBe(3)
        ->and(TournamentMatchMaker::needsGame($match))->toBeFalse()
        ->and($whites[0])->not->toBe($whites[1])
        ->and($match->slots[$match->result['winner']]->participant->seed)->toBe(1)
        ->and($match->result['decided'])->toBe('seed')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

/* ---------- G6: a voided series is replayed ----------------------------------------------------------------- */

test('an admin voids a tournament series and the match is played again as a new series', function () {
    [$tournament, $series, $a, $b] = p18Duel();

    app(SeriesService::class)->decide($series, p18Admin(), ['type' => 'void'], 'The lobby crashed.');
    $replay = SeriesMatch::query()->where('tournament_match_id', $series->tournament_match_id)->where('id', '!=', $series->id)->sole();

    expect($series->refresh()->resolution)->toBe(SeriesResolution::Void)
        ->and($replay->tournament_attempt)->toBe(2)
        ->and($replay->status)->toBe(SeriesStatus::Accepted)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Running);

    app(SeriesService::class)->respond(p18Report($replay, $a), $b, 'confirmed', '', []);

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->sole()->result['number'])->toBe($replay->number);
});

/* ---------- G3: an accepted tournament series nobody reports ------------------------------------------------ */

test('an admin decides an accepted tournament series without report or no-show; a player admin still cannot', function () {
    [$tournament, $series, $a] = p18Duel();
    Admin::query()->create(['pubkey' => $a->pubkey]);
    $service = app(SeriesService::class);

    expect(fn () => $service->decide($series, $a, ['type' => 'forfeit', 'winner' => 'challenger'], 'Mine.'))->toThrow(SeriesRuleViolation::class, 'play in');

    $service->decide($series->refresh(), p18Admin(), ['type' => 'forfeit', 'winner' => 'challenged'], 'The challenger never showed up.');

    expect($series->refresh()->resolution)->toBe(SeriesResolution::Forfeit)
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->sole()->result['winner'])->toBe(1)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('an accepted ladder series outside a tournament is still nothing to decide', function () {
    $series = SeriesMatch::factory()->create(['status' => SeriesStatus::Accepted]);

    expect(SeriesService::isDecidable($series))->toBeFalse()
        ->and(fn () => app(SeriesService::class)->decide($series, p18Admin(), ['type' => 'void'], 'No.'))->toThrow(SeriesRuleViolation::class, 'nothing to decide');
});

/* ---------- G4: a report nobody answers --------------------------------------------------------------------- */

test('a report nobody answered for two hours joins the admin queue as an unanswered report', function () {
    [$tournament, $series, $a] = p18Duel();
    $series = p18Report($series, $a);
    $admin = p18Admin();

    $this->travel(119)->minutes();
    expect(SeriesMatch::query()->openCase()->count())->toBe(0);
    Livewire::actingAs($admin)->test('pages::admin.disputes')->assertDontSee('Unanswered report');

    $this->travel(2)->minutes();
    expect(SeriesMatch::query()->openCase()->pluck('id')->all())->toBe([$series->id]);
    Livewire::actingAs($admin)->test('pages::admin.disputes')->assertSee('Unanswered report')->assertSee($series->label());

    app(SeriesService::class)->decide($series, $admin, ['type' => 'report', 'report' => $series->latestReport->id], 'Nobody answered.');

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

/* ---------- G10: an account deleted mid-tournament ---------------------------------------------------------- */

test('an account deleted before its pairing: the withdrawn player loses the later match by forfeit', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 3, TournamentResultsMode::Players);
    $top = TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('seed', 1)->sole();

    // Seed 1 has a bye; the others play round 1 while seed 1 deletes the account.
    app(DeleteAccount::class)(User::query()->findOrFail($top->user_id));
    $game = p18Game($tournament);
    app(ChessGameService::class)->resign($game, $game->black);

    $final = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->whereNotNull('result')->orderByDesc('id')->with('slots')->first();
    $winner = $final->slots[$final->result['winner']]->tournament_participant_id;

    expect($tournament->refresh()->status)->toBe(TournamentStatus::Finished)
        ->and($final->result['decided'])->toBe('withdrawn')
        ->and($winner)->not->toBe($top->id)
        ->and(ChessGame::query()->count())->toBe(1);
});

test('an account deleted mid-match: its running tournament game or series goes to the opponent by forfeit, unrated', function (Closure $setup) {
    [$tournament, $leaver, $check] = $setup();

    app(DeleteAccount::class)($leaver);

    expect(User::query()->whereKey($leaver->id)->exists())->toBeFalse()
        ->and(RatingChange::query()->count())->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);

    $check();
})->with([
    'a casual chess game' => [function () {
        $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players);
        $game = app(ChessGameService::class)->move(p18Game($tournament), p18Game($tournament)->white, 'e2e4');
        $white = $game->white;

        return [$tournament, $white, function () use ($game) {
            expect($game->refresh()->end_reason)->toBe(ChessEndReason::Forfeit)
                ->and($game->result)->toBe('0-1');
        }];
    }],
    'an accepted series' => [function () {
        [$tournament, $series, , $b] = p18Duel();

        return [$tournament, $b, function () use ($series) {
            expect($series->refresh()->resolution)->toBe(SeriesResolution::Forfeit)
                ->and($series->winner)->toBe('challenger');
        }];
    }],
]);
