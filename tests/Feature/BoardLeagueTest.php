<?php

/*
| The board games join the league (plan "Mühle und Dame", P5): the casual
| queue and invites of each board game, one live game at a time across
| chess, the board games and the casual 1v1, the casual Elo of each board
| game (chess ratings untouched), casual cups and tournaments that play a
| board game on the board game core; a casual game never mines (rated play
| and mining: tests/Feature/BoardMiningTest.php, P6). With the switch off
| none of it has a route or a place in the navigation.
*/

use App\Enums\BoardGameStatus;
use App\Enums\BoardInviteStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\BoardQueueEntry;
use App\Models\ChessGame;
use App\Models\ChessQueueEntry;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardInvites;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessQueue;
use App\Support\Chess\ChessRuleViolation;
use App\Support\Dock\OpenMatches;
use App\Support\Navigation\ShellNavigation;
use App\Support\Rating\RatingService;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Series\Ladders;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentMatchMaker;
use App\Support\Tournaments\TournamentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;

beforeEach(function () {
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
});

function boardLeagueRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation|ChessRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

/**
 * A running players-mode tournament of one board game, bracket stored and synced.
 */
function runningBoardTournament(string $game, int $n, TournamentFormat $format = TournamentFormat::SingleElimination): Tournament
{
    $tournament = Tournament::factory()->create([
        'game' => $game, 'mode' => 'blitz', 'format' => $format,
        'options' => FormatOptions::fromArray([], GameProfile::for($game, 'blitz'))->toArray(),
        'capacity' => $n, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-cup-'.fake()->unique()->numberBetween(1, 1_000_000), 'ladder_address' => null,
    ]);

    foreach (range(1, $n) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    return $tournament->refresh();
}

/* ---------- Queue and invites ------------------------------------------------------------------------------- */

test('two players who search the same board game are paired into a board game of it, and nobody else is', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $queue = app(BoardQueue::class);
    ChessQueueEntry::query()->create(['user_id' => $a->id, 'mode' => 'blitz', 'rated' => false, 'rating' => 1000, 'joined_at' => now()]);

    expect($queue->join($a, NineMensMorris::SLUG))->toBeNull()
        // One intent at a time: searching here ended the chess search.
        ->and(ChessQueueEntry::query()->count())->toBe(0)
        // Another board game's queue never pairs with this one.
        ->and($queue->join($c, Checkers::SLUG))->toBeNull();

    $game = $queue->join($b, NineMensMorris::SLUG);

    expect($game)->toBeInstanceOf(BoardGame::class)
        ->and($game->game)->toBe(NineMensMorris::SLUG)
        ->and($game->status)->toBe(BoardGameStatus::Active)
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$a->id, $b->id])
        ->and(BoardQueueEntry::query()->pluck('user_id')->all())->toBe([$c->id])
        ->and(ChessGame::query()->count())->toBe(0);
});

test('an invite to a player looking to play starts a board game on accept, and a player not looking cannot be invited', function () {
    [$inviter, $invitee, $other] = User::factory()->count(3)->create();
    $invites = app(BoardInvites::class);

    expect(boardLeagueRefusal(fn () => $invites->invite($inviter, $invitee, Checkers::SLUG)))->toBe('not_looking');

    $invitee->forceFill(['looking_to_play' => 'checkers/blitz'])->save();
    $invite = $invites->invite($inviter, $invitee, Checkers::SLUG);

    expect($invite->status)->toBe(BoardInviteStatus::Pending)
        ->and($invites->incoming($invitee)->pluck('id')->all())->toBe([$invite->id])
        ->and(boardLeagueRefusal(fn () => $invites->accept($invite, $other)))->toBe('invite_closed');

    $game = $invites->accept($invite, $invitee);

    expect($game->game)->toBe(Checkers::SLUG)
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$inviter->id, $invitee->id])
        ->and($invite->refresh()->status)->toBe(BoardInviteStatus::Accepted)
        ->and($invite->board_game_id)->toBe($game->id);
});

test('an invite to a player who searches the same board game starts the game at once', function () {
    [$inviter, $invitee] = User::factory()->count(2)->create();
    $invitee->forceFill(['looking_to_play' => 'nine-mens-morris/blitz'])->save();
    app(BoardQueue::class)->join($invitee, NineMensMorris::SLUG);

    $invite = app(BoardInvites::class)->invite($inviter, $invitee, NineMensMorris::SLUG);

    expect($invite->board_game_id)->not->toBeNull()
        ->and(BoardGame::query()->sole()->id)->toBe($invite->board_game_id)
        ->and(BoardQueueEntry::query()->count())->toBe(0);
});

test('the lobby finds an opponent and moves both players to the board', function () {
    [$a, $b] = User::factory()->count(2)->create();

    Livewire::actingAs($a)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->call('findOpponent')
        ->assertSeeHtml('data-test="lobby-searching"')
        ->assertNoRedirect();

    Livewire::actingAs($b)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->call('findOpponent')
        ->assertRedirect(route('board.show', BoardGame::query()->sole()));

    Livewire::actingAs($a)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->call('poll')
        ->assertRedirect(route('board.show', BoardGame::query()->sole()));
});

/* ---------- One live game at a time ------------------------------------------------------------------------- */

test('one live game at a time: a board game keeps chess out, and live chess keeps the board games out', function () {
    [$a, $b, $c, $d] = User::factory()->count(4)->create();
    $board = app(BoardGameService::class)->start(NineMensMorris::SLUG, $a, $b);

    // Chess, unchanged, refuses a board player in its queue and never starts a live game with one.
    expect(boardLeagueRefusal(fn () => app(ChessQueue::class)->join($a)))->toBe('already_playing')
        ->and(boardLeagueRefusal(fn () => app(ChessGameService::class)->start($a, $c)))->toBe('already_playing')
        ->and(ChessGame::query()->count())->toBe(0)
        ->and(ChessQueueEntry::query()->count())->toBe(0);

    // Daily chess is no live game: it still starts.
    expect(app(ChessGameService::class)->start($a, $c, ChessGame::CORRESPONDENCE)->isActive())->toBeTrue();

    $chess = app(ChessGameService::class)->start($c, $d);

    expect(boardLeagueRefusal(fn () => app(BoardQueue::class)->join($c, Checkers::SLUG)))->toBe('playing_elsewhere')
        ->and(boardLeagueRefusal(fn () => app(BoardGameService::class)->start(Checkers::SLUG, $d, User::factory()->create())))->toBe('playing_elsewhere')
        ->and(boardLeagueRefusal(fn () => app(BoardQueue::class)->join($a, Checkers::SLUG)))->toBe('already_playing')
        ->and(BoardGame::query()->count())->toBe(1);

    // Over, and both are free again.
    app(BoardGameService::class)->abort($board, $a);
    app(ChessGameService::class)->abort($chess->refresh(), $c);

    expect(app(ChessQueue::class)->join($a))->toBeNull()
        ->and(app(BoardQueue::class)->join($c, Checkers::SLUG))->toBeNull();
});

/* ---------- Elo --------------------------------------------------------------------------------------------- */

test('a finished board game moves only the casual rating of its board game; chess ratings stay as they were', function () {
    [$white, $black] = User::factory()->count(2)->create();
    // Chess ratings of both, rated and casual, before the board game.
    foreach ([$white, $black] as $player) {
        foreach ([[Rating::CASUAL, ''], [Rating::RATED, 'season-1']] as [$pool, $season]) {
            Rating::query()->create(['pool' => $pool, 'season' => $season, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$player->id, 'user_id' => $player->id, 'rating' => 1234, 'results' => 7, 'wins' => 3, 'draws' => 1, 'losses' => 3]);
        }
    }
    $chessBefore = Rating::query()->where('game', 'chess')->orderBy('id')->get(['rating', 'results', 'wins', 'draws', 'losses'])->toArray();

    $service = app(BoardGameService::class);
    $game = $service->start(NineMensMorris::SLUG, $white, $black);
    $game = $service->resign($game, $black);

    $morris = Rating::query()->where('game', NineMensMorris::SLUG)->orderBy('id')->get();

    expect($game->result)->toBe('1-0')
        ->and(Rating::query()->where('game', 'chess')->orderBy('id')->get(['rating', 'results', 'wins', 'draws', 'losses'])->toArray())->toBe($chessBefore)
        ->and($morris->pluck('pool')->unique()->all())->toBe([Rating::CASUAL])
        ->and($morris->pluck('season')->unique()->all())->toBe([''])
        ->and($morris->firstWhere('user_id', $white->id)->rating)->toBeGreaterThan(1000)
        ->and($morris->firstWhere('user_id', $black->id)->rating)->toBeLessThan(1000)
        ->and(RatingChange::query()->pluck('source')->unique()->all())->toBe([RatingChange::BOARD])
        ->and(RatingChange::query()->pluck('source_id')->unique()->all())->toBe([$game->id])
        // Checkers has its own ladder: untouched by a game of nine men's morris.
        ->and(Rating::query()->where('game', Checkers::SLUG)->count())->toBe(0)
        // Rated once: the same game again moves nothing.
        ->and(app(RatingService::class)->applyBoardGame($game))->toBeFalse();
});

test('an aborted board game rates nothing', function () {
    [$white, $black] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);
    $service->abort($service->start(Checkers::SLUG, $white, $black), $white);

    expect(RatingChange::query()->count())->toBe(0)->and(Rating::query()->count())->toBe(0);
});

/* ---------- Casual games never mine (P6 opened the ladders) ------------------------------------------------ */

test('a casual board game mines nothing: no attestation and no rated Elo, while its season ladder is open and published (P6)', function () {
    $season = openSeason();
    $trust = new TestSigner;
    config(['esports.trust.nsec' => $trust->secret]);

    // Live, but not published yet: a board game's ladder opens with its first version.
    expect(Ladders::isOpen(NineMensMorris::SLUG, 'blitz'))->toBeFalse();

    app(LadderEvents::class)->publish($season, LeagueKey::required(), $trust->pubkey);

    expect(Ladders::isOpen(NineMensMorris::SLUG, 'blitz'))->toBeTrue()
        ->and(Ladders::isOpen(Checkers::SLUG, 'blitz'))->toBeTrue()
        ->and(NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => NineMensMorris::SLUG.'/blitz/'.$season->slug])->count())->toBe(1)
        ->and(NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => Checkers::SLUG.'/blitz/'.$season->slug])->count())->toBe(1)
        ->and(NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/'.$season->slug])->count())->toBe(1);

    $events = NostrEvent::query()->count();
    [$white, $black] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);
    $service->resign($service->start(NineMensMorris::SLUG, $white, $black), $white);
    $service->offerDraw($game = $service->start(Checkers::SLUG, $white, $black), $white);

    expect(SeasonAttestation::query()->count())->toBe(0)
        ->and(NostrEvent::query()->count())->toBe($events)
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        ->and(Rating::query()->where('pool', Rating::CASUAL)->where('game', NineMensMorris::SLUG)->count())->toBe(2)
        ->and(BoardGame::query()->where('rated', true)->count())->toBe(0);
});

/* ---------- Tournaments and cups ---------------------------------------------------------------------------- */

test('a tournament of a board game starts a board game for each ready match, and its end moves the bracket', function () {
    $tournament = runningBoardTournament(Checkers::SLUG, 2);
    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    [$first, $second] = matchPlayers($match);

    $game = BoardGame::query()->sole();

    expect($game->game)->toBe(Checkers::SLUG)
        ->and($game->tournament_match_id)->toBe($match->id)
        ->and($game->tournament_game)->toBe(1)
        ->and($game->white_id)->toBe($first->id)
        ->and($game->black_id)->toBe($second->id)
        ->and(ChessGame::query()->count())->toBe(0)
        // A tournament game is the league's to abort, never a player's.
        ->and(boardLeagueRefusal(fn () => app(BoardGameService::class)->abort($game, $first)))->toBe('tournament_game');

    app(BoardGameService::class)->resign($game, $second);

    expect($match->refresh()->result['winner'])->toBe(0)
        ->and($match->result['by'])->toBe('players')
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

test('a drawn board game in a knockout is replayed with the colours swapped', function () {
    $tournament = runningBoardTournament(NineMensMorris::SLUG, 2);
    $service = app(BoardGameService::class);
    $first = BoardGame::query()->sole();

    // Both first moves, then a draw by agreement.
    $morris = [$first->white, $first->black];
    $service->move($first, $morris[0], 'a1', 1);
    $service->move($first->refresh(), $morris[1], 'a4', 2);
    $service->offerDraw($first->refresh(), $morris[0]);
    $service->acceptDraw($first->refresh(), $morris[1]);

    app(TournamentMatchMaker::class)->startReady($tournament->refresh());
    $replay = BoardGame::query()->whereKeyNot($first->id)->sole();

    expect($first->refresh()->result)->toBe('1/2-1/2')
        ->and(TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole()->result)->toBeNull()
        ->and($replay->tournament_game)->toBe(2)
        ->and([$replay->white_id, $replay->black_id])->toBe([$first->black_id, $first->white_id]);
});

test('a board game cup opens only while its board game is on, and "Play your cup match" starts its board game', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => ['chess', NineMensMorris::SLUG, Checkers::SLUG]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    expect(CasualCups::enabledGames())->toBe(['chess', NineMensMorris::SLUG, Checkers::SLUG])
        ->and(GameProfile::for(NineMensMorris::SLUG, 'blitz')->isBoard())->toBeTrue()
        ->and(GameProfile::for('chess', 'blitz')->isBoard())->toBeFalse();

    $cup = runningCup(4, game: NineMensMorris::SLUG);
    cupTick();
    $match = openCupMatches($cup)->first();
    [$white, $black] = matchPlayers($match);

    $invite = app(BoardInvites::class)->inviteToCupMatch($black, $match);
    expect($invite->tournament_match_id)->toBe($match->id)->and(BoardGame::query()->count())->toBe(0);

    $game = app(BoardInvites::class)->accept($invite, $white);

    expect($game->tournament_match_id)->toBe($match->id)
        ->and($game->game)->toBe(NineMensMorris::SLUG)
        ->and($game->white_id)->toBe($white->id)
        ->and($game->first_move_seconds)->toBeGreaterThan(30)
        ->and(ChessGame::query()->count())->toBe(0)
        // The cup page leads to the board game, not to a chess game.
        ->and(Livewire::actingAs($white)->test('pages::tournaments.show', ['tournament' => $cup])->assertSeeHtml('data-test="cup-match-game"')->assertSee(route('board.show', $game), false))->not->toBeNull();

    // Switched off, its cups stop opening.
    config(['esports.board_games.games.checkers.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    expect(CasualCups::enabledGames())->toBe(['chess', NineMensMorris::SLUG]);
});

test('a board game cup match nobody started is started by the league at the auto slot', function () {
    config(['esports.league.nsec' => (new TestSigner)->secret, 'esports.casual_cups.enabled' => [NineMensMorris::SLUG]]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));
    $cup = runningCup(4, game: NineMensMorris::SLUG);
    cupTick();
    $match = openCupMatches($cup)->first();

    $this->travelTo(CasualCups::autoSlot($match->round->window_ends_at, CasualCups::timezoneOf($cup)));
    cupTick();

    expect(BoardGame::query()->whereNotNull('tournament_match_id')->count())->toBe(2)
        ->and(ChessGame::query()->count())->toBe(0);
});

/* ---------- Match dock -------------------------------------------------------------------------------------- */

test('the match dock carries a live board game and a board invite, and leaves out the board game on screen', function () {
    [$player, $opponent, $inviter] = User::factory()->count(3)->create();
    $game = app(BoardGameService::class)->start(NineMensMorris::SLUG, $player, $opponent);
    $inviter->forceFill(['looking_to_play' => null])->save();
    $opponent->forceFill(['looking_to_play' => 'checkers/blitz'])->save();
    $invite = app(BoardInvites::class)->invite($inviter, $opponent, Checkers::SLUG);

    $mine = app(OpenMatches::class)->for($player);
    $theirs = app(OpenMatches::class)->for($opponent);

    expect($mine->pluck('key')->all())->toBe(['board-'.$game->id])
        ->and($mine->first()->href)->toBe(route('board.show', $game))
        ->and($mine->first()->boardIcon())->toBe('morris')
        ->and($mine->first()->isChess())->toBeFalse()
        ->and($theirs->pluck('key')->all())->toEqualCanonicalizing(['board-'.$game->id, 'board-invite-'.$invite->id])
        ->and($theirs->firstWhere('key', 'board-invite-'.$invite->id)->href)->toBe(route('board.lobby', Checkers::SLUG))
        ->and(app(OpenMatches::class)->for($player, excludeBoard: $game->id)->count())->toBe(0);

    $this->actingAs($player)->get(route('board.show', $game))->assertOk();
    expect(OpenMatches::boardOnScreen(request()))->toBe($game->id);
});

/* ---------- The switch -------------------------------------------------------------------------------------- */

test('the board games have their own lobby, ladder and context bar while on', function () {
    $this->get(route('board.lobby', NineMensMorris::SLUG))->assertOk()->assertSee("Nine Men's Morris");
    // Casual only until P6: no Rated choice, no note about a rated ladder; a finished game fills it.
    [$winner, $loser] = User::factory()->count(2)->create();
    app(BoardGameService::class)->resign(app(BoardGameService::class)->start(Checkers::SLUG, $winner, $loser), $loser);
    $this->get(route('ladder.show', [Checkers::SLUG, 'blitz']))->assertOk()
        ->assertDontSee('data-test="pool-rated"', false)
        ->assertDontSee('data-test="ladder-rated-note"', false)
        ->assertSee($winner->displayName());

    $player = User::factory()->create();
    $game = app(BoardGameService::class)->start(NineMensMorris::SLUG, $player, User::factory()->create());

    $this->actingAs($player)->get(route('board.show', $game))->assertOk()
        ->assertSee('data-test="hub-game-'.NineMensMorris::SLUG.'"', false);
    expect(array_column(ShellNavigation::current()->games(), 'slug'))->toContain(NineMensMorris::SLUG, Checkers::SLUG);
});
