<?php

/*
| Correspondence board games (plan "Mühle und Dame", P8): nine men's morris
| and checkers one move a day, as daily chess. A challenge starts the game;
| each move has a day; a missed deadline loses (or aborts before both first
| moves) through the delayed job and the safety-net command; the player to
| move is reminded and told of the opponent's move; a rated challenge needs
| two Trusted players who list each other, and a rated win mines with the
| correspondence weight, a casual one never.
*/

use App\Enums\BoardGameStatus;
use App\Enums\BoardInviteStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Enums\TournamentStatus;
use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Jobs\CheckBoardClock;
use App\Jobs\PublishNostrEvent;
use App\Jobs\SendNostrDm;
use App\Jobs\SendWebPush;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\User;
use App\Support\Board\BoardChallenges;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Dock\OpenMatches;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\RatedTrustGate;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentBrackets;
use App\Support\Tournaments\TournamentDeadlines;
use App\Support\Tournaments\TournamentGames;
use App\Support\Tournaments\TournamentRunner;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TrustedFacts;

const CORRESPONDENCE_DAY_MS = 86_400_000;

beforeEach(function () {
    $this->freezeTime();
    Queue::fake();
    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
    NineMensMorrisOn::play();
    CheckersGame::play();
    config([
        'esports.notifications.nsec' => bin2hex(random_bytes(32)),
        'esports.board_games.rated_queue' => true,
    ]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/** A player who wants Nostr DMs for every kind. */
function correspondencePlayer(): User
{
    return User::factory()->create(['chess_settings' => ['dm' => true]]);
}

/**
 * A live season whose genesis lets the board games mine, correspondence at
 * three times nine men's morris blitz, with its ladders published.
 */
function correspondenceSeason(): Season
{
    $defaults = ChainDraft::defaults();

    $season = openSeason(['parameters' => [
        'weights' => [...$defaults['weights'], 'nine-mens-morris/blitz' => 1000, 'nine-mens-morris/correspondence' => 3000, 'checkers/blitz' => 1000, 'checkers/correspondence' => 2000],
        'groups' => $defaults['groups'],
        'shares' => ['chess' => 30, 'rocket-league' => 35, 'ea-sports-fc' => 25, 'board-games' => 10],
        'daily' => [...$defaults['daily'], 'board-games' => 5],
        'pairlimit' => $defaults['pairlimit'],
        'subtree' => $defaults['subtree'],
        'moves' => 1,
    ]]);
    publishLadders($season);

    return $season;
}

/** The reason a board action is refused with, or null when it is not. */
function correspondenceRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

/** A correspondence game Anna (White) and Bert (Black) start from Anna's challenge. */
function correspondenceGame(User $anna, User $bert, string $slug = NineMensMorris::SLUG, bool $rated = false): BoardGame
{
    $challenges = app(BoardChallenges::class);

    return $challenges->accept($challenges->challenge($anna, $bert, $slug, 'white', $rated), $bert);
}

/* ---------- Start from a challenge -------------------------------------------------------------------------- */

test('a challenge starts a casual correspondence game with the chosen colours and a day for the first move', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $challenges = app(BoardChallenges::class);

    $challenge = $challenges->challenge($anna, $bert, Checkers::SLUG, 'black', message: 'Fern-Dame?');

    expect($challenges->incoming($bert)->pluck('id')->all())->toBe([$challenge->id])
        ->and($challenges->outgoing($anna)->pluck('id')->all())->toBe([$challenge->id])
        ->and($bert->notifications()->where('type', 'challenge')->count())->toBe(1)
        ->and(Bus::dispatched(SendNostrDm::class)->map(fn (SendNostrDm $job) => $job->user->id)->all())->toBe([$bert->id])
        // A second challenge between the two, either way, is refused while this one is open.
        ->and(correspondenceRefusal(fn () => $challenges->challenge($bert, $anna, Checkers::SLUG)))->toBe('challenge_open');

    $game = $challenges->accept($challenge, $bert);

    expect($game->only(['game', 'mode', 'rated', 'white_id', 'black_id', 'ply']))
        ->toBe(['game' => Checkers::SLUG, 'mode' => BoardGame::CORRESPONDENCE, 'rated' => false, 'white_id' => $bert->id, 'black_id' => $anna->id, 'ply' => 0])
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + CORRESPONDENCE_DAY_MS)
        ->and($game->number)->toBeNull()
        ->and($challenge->refresh()->only(['status', 'board_game_id']))->toBe(['status' => BoardInviteStatus::Accepted, 'board_game_id' => $game->id])
        ->and($anna->notifications()->where('type', 'game_started')->count())->toBe(1)
        // Not accepted twice.
        ->and(correspondenceRefusal(fn () => $challenges->accept($challenge, $bert)))->toBe('challenge_closed');

    // No live game: both still play blitz (queue, live game) next to it, and any number of correspondence games.
    $live = app(BoardGameService::class)->start(NineMensMorris::SLUG, $anna, $bert);
    expect($live->mode)->toBe('blitz')
        ->and(app(BoardGameService::class)->activeGameOf($anna)?->id)->toBe($live->id)
        ->and(correspondenceGame($anna, $bert)->mode)->toBe(BoardGame::CORRESPONDENCE);
});

test('a challenge is refused to oneself, for a game without correspondence and past the daily limits', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $challenges = app(BoardChallenges::class);
    config(['esports.board_games.correspondence.challenges_per_recipient_per_day' => 1]);

    expect(correspondenceRefusal(fn () => $challenges->challenge($anna, $anna, Checkers::SLUG)))->toBe('challenge_self')
        ->and(correspondenceRefusal(fn () => $challenges->challenge($anna, $bert, 'chess')))->toBe('unknown_game')
        ->and(correspondenceRefusal(fn () => $challenges->challenge($anna, $bert, Checkers::SLUG, 'green')))->toBe('challenge_color');

    $first = $challenges->challenge($anna, $bert, Checkers::SLUG);
    $challenges->close($first, $anna);

    expect($first->refresh()->status)->toBe(BoardInviteStatus::Withdrawn)
        ->and(correspondenceRefusal(fn () => $challenges->challenge($anna, $bert, NineMensMorris::SLUG)))->toBe('challenge_limit');
});

/* ---------- Moves, deadlines, timeouts ---------------------------------------------------------------------- */

test('a move within the deadline is played, gives the opponent a full day, puts nothing in the bell and never sends a DM', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $game = correspondenceGame($anna, $bert);
    $service = app(BoardGameService::class);
    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);

    // A minute before the deadline is in time.
    $this->travel(CORRESPONDENCE_DAY_MS / 60_000 - 1)->minutes();
    $game = $service->move($game, $anna, 'd2', 1);

    expect($game->ply)->toBe(1)
        ->and($game->turn)->toBe('b')
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + CORRESPONDENCE_DAY_MS)
        ->and($game->white_ms)->toBe(CORRESPONDENCE_DAY_MS)
        // The game bar shows the turn; the bell keeps no entry per move (user, 2026-10-02).
        ->and($bert->notifications()->where('type', 'your_move')->count())->toBe(0)
        // "Your move" is never a DM (user decision 2026-09-30, NotificationKind::dmAllowed()).
        ->and(Bus::dispatched(SendNostrDm::class))->toHaveCount(0)
        ->and($service->snapshot($game)['clock'])->toMatchArray(['b' => CORRESPONDENCE_DAY_MS, 'running' => 'b'])
        ->and($service->snapshot($game)['daily'])->toBeTrue()
        // The move list carries each position, so the board can step back through the game.
        ->and($service->snapshot($game)['moves'][0]['pieces'])->toBe(['d2' => ['side' => 'w', 'kind' => 'man']]);

    // A live board game's move is no DM: its players are at the board.
    $live = $service->start(Checkers::SLUG, $anna, $bert);
    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);
    $service->move($live, $anna, 'c3-d4', 1);

    expect(Bus::dispatched(SendNostrDm::class))->toHaveCount(0);
});

test('a missed deadline loses on time through the delayed job, and aborts before both first moves', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $service = app(BoardGameService::class);

    $game = correspondenceGame($anna, $bert);
    // The start dispatched the check for the first deadline, a day and a second away.
    Queue::assertPushed(CheckBoardClock::class, fn (CheckBoardClock $job) => $job->gameId === $game->id && $job->delay?->getTimestamp() === now()->addSeconds(86_401)->getTimestamp());

    $game = $service->move($game, $anna, 'd2', 1);
    $game = $service->move($game->refresh(), $bert, 'd6', 2);

    // White does not move for a day: the job ends the game, Black wins on time; both hear the result.
    $this->travel(CORRESPONDENCE_DAY_MS)->milliseconds();
    (new CheckBoardClock($game->id))->handle($service);

    expect($game->refresh()->only(['status', 'result', 'end_reason']))->toBe(['status' => BoardGameStatus::Finished, 'result' => '0-1', 'end_reason' => 'timeout'])
        ->and($anna->notifications()->where('type', 'game_over')->count())->toBe(1)
        ->and($bert->notifications()->where('type', 'game_over')->count())->toBe(1);

    // Before both first moves the game is aborted, as a blitz game.
    $unopened = correspondenceGame($anna, $bert, Checkers::SLUG);
    $this->travel(CORRESPONDENCE_DAY_MS)->milliseconds();
    (new CheckBoardClock($unopened->id))->handle($service);

    expect($unopened->refresh()->only(['status', 'result', 'end_reason']))->toBe(['status' => BoardGameStatus::Aborted, 'result' => null, 'end_reason' => 'aborted']);
});

test('the safety-net command ends a correspondence game whose deadline passed, and not one still in time', function () {
    [$anna, $bert, $cleo] = [correspondencePlayer(), correspondencePlayer(), correspondencePlayer()];
    $service = app(BoardGameService::class);

    $due = correspondenceGame($anna, $bert, Checkers::SLUG);
    $due = $service->move($due, $anna, 'c3-d4', 1);
    $due = $service->move($due->refresh(), $bert, 'f6-e5', 2);
    $this->travel(12)->hours();
    $fresh = correspondenceGame($anna, $cleo);
    $this->travel(12)->hours();

    $this->artisan('board:check-clocks')->expectsOutputToContain('Checked 1 game(s).')->assertSuccessful();

    expect($due->refresh()->only(['status', 'result', 'end_reason']))->toBe(['status' => BoardGameStatus::Finished, 'result' => '0-1', 'end_reason' => 'timeout'])
        ->and($fresh->refresh()->status)->toBe(BoardGameStatus::Active);
});

test('the player to move is reminded once per turn when their window is reached', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $game = correspondenceGame($anna, $bert);
    Bus::fake([SendWebPush::class, SendNostrDm::class, PublishNostrEvent::class]);

    // 6 h 01 min left: not yet (the default window is 6 h).
    $this->travel(18 * 60 - 1)->minutes();
    $this->artisan('board:daily-reminders')->expectsOutputToContain('Sent 0 reminder(s).')->assertSuccessful();
    $this->travel(2)->minutes();
    $this->artisan('board:daily-reminders')->expectsOutputToContain('Sent 1 reminder(s).')->assertSuccessful();
    $this->artisan('board:daily-reminders')->expectsOutputToContain('Sent 0 reminder(s).')->assertSuccessful();

    expect($anna->notifications()->where('type', 'reminder')->count())->toBe(1)
        ->and($bert->notifications()->where('type', 'reminder')->count())->toBe(0)
        ->and(Bus::dispatched(SendNostrDm::class)->sole()->user->id)->toBe($anna->id)
        ->and($game->refresh()->reminded_ply)->toBe(0);

    // The next turn gets its own reminder.
    app(BoardGameService::class)->move($game, $anna, 'd2', 1);
    $this->travel(18 * 60 + 1)->minutes();
    $this->artisan('board:daily-reminders')->expectsOutputToContain('Sent 1 reminder(s).')->assertSuccessful();

    expect($bert->notifications()->where('type', 'reminder')->count())->toBe(1);
});

/* ---------- Rated and casual -------------------------------------------------------------------------------- */

test('a rated correspondence win is attested on the correspondence ladder with the correspondence weight', function () {
    $season = correspondenceSeason();
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];

    $game = correspondenceGame($anna, $bert, NineMensMorris::SLUG, rated: true);

    expect($game->rated)->toBeTrue()
        ->and($game->number)->toBeInt()
        ->and($game->ladder_address)->toBe(Ladders::address(NineMensMorris::SLUG, BoardGame::CORRESPONDENCE))
        ->and($game->ladder_address)->toEndWith(':nine-mens-morris/correspondence/'.$season->slug)
        ->and($game->gate_at_accept['players'])->toHaveKeys([$anna->pubkey, $bert->pubkey]);

    $service = app(BoardGameService::class);
    $game = $service->move($game, $anna, 'a1', 1);
    $game = $service->move($game->refresh(), $bert, 'a4', 2);
    $service->resign($game->refresh(), $bert);

    $attestation = SeasonAttestation::query()->sole();
    $parameters = $season->chainParameters();

    expect($attestation->only(['source', 'source_id', 'height', 'game', 'mode']))
        ->toBe(['source' => SeasonAttestation::BOARD, 'source_id' => $game->id, 'height' => 1, 'game' => NineMensMorris::SLUG, 'mode' => BoardGame::CORRESPONDENCE])
        ->and($attestation->candidate['weight_key'])->toBe('nine-mens-morris/correspondence')
        ->and($attestation->reward_per_player)->toBe($parameters->rewardPerPlayer(3000, 1))
        ->and(RatingChange::query()->where('source', RatingChange::BOARD)->count())->toBe(2)
        // On the rated correspondence ladder of nine men's morris, not on its blitz one.
        ->and(Rating::query()->where(['pool' => Rating::RATED, 'game' => NineMensMorris::SLUG])->pluck('mode')->unique()->all())->toBe([BoardGame::CORRESPONDENCE]);
});

test('a casual correspondence win never mines, even in a live season', function () {
    correspondenceSeason();
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $service = app(BoardGameService::class);

    $game = correspondenceGame($anna, $bert, Checkers::SLUG);
    $game = $service->move($game, $anna, 'c3-d4', 1);
    $game = $service->move($game->refresh(), $bert, 'f6-e5', 2);
    $service->resign($game->refresh(), $bert);

    expect($game->refresh()->result)->toBe('1-0')
        ->and($game->rated)->toBeFalse()
        ->and(SeasonAttestation::query()->count())->toBe(0)
        ->and(Rating::query()->where('pool', Rating::RATED)->count())->toBe(0)
        // Its casual rating per mode: correspondence, not blitz.
        ->and(Rating::query()->where(['pool' => Rating::CASUAL, 'game' => Checkers::SLUG])->pluck('mode')->unique()->all())->toBe([BoardGame::CORRESPONDENCE]);
});

test('a rated challenge needs the rated switch and two players who list each other, when sent and when accepted', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    $challenges = app(BoardChallenges::class);

    // Before Block 0 there is no correspondence ladder.
    expect(correspondenceRefusal(fn () => $challenges->challenge($anna, $bert, Checkers::SLUG, rated: true)))->toBe('rated_unavailable');

    correspondenceSeason();
    config(['esports.board_games.rated_queue' => false]);
    expect(correspondenceRefusal(fn () => $challenges->challenge($anna, $bert, Checkers::SLUG, rated: true)))->toBe('rated_unavailable');

    config(['esports.board_games.rated_queue' => true]);
    $challenge = $challenges->challenge($anna, $bert, Checkers::SLUG, rated: true);

    // Between the challenge and the accept the two stop listing each other: no rated accept, the challenge stays open.
    app()->bind(TrustFacts::class, fn () => new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => false];
        }
    });
    $challenges = app(BoardChallenges::class);

    try {
        $challenges->accept($challenge, $bert);
        $this->fail('A rated challenge was accepted between two players who do not list each other.');
    } catch (BoardRuleViolation $violation) {
        expect($violation->reason)->toBe('rated_pair')
            ->and($violation->getMessage())->toBe(RatedTrustGate::NOT_CONNECTED);
    }

    expect($challenge->refresh()->status)->toBe(BoardInviteStatus::Pending)
        ->and(BoardGame::query()->count())->toBe(0)
        ->and($challenges->ratedRefusal($anna, $bert, Checkers::SLUG))->toBe(['reason' => 'rated_pair', 'message' => RatedTrustGate::NOT_CONNECTED])
        // A casual challenge between the same two is fine.
        ->and($challenges->accept($challenges->challenge($bert, $anna, NineMensMorris::SLUG), $anna)->rated)->toBeFalse();
});

test('a correspondence game keeps nobody out of the live queue', function () {
    [$anna, $bert, $cleo] = [correspondencePlayer(), correspondencePlayer(), correspondencePlayer()];
    correspondenceGame($anna, $bert);

    expect(app(BoardQueue::class)->join($anna, Checkers::SLUG))->toBeNull()
        ->and(app(BoardQueue::class)->join($cleo, Checkers::SLUG)?->mode)->toBe('blitz');
});

/* ---------- Tournaments (as daily chess) -------------------------------------------------------------------- */

test('a correspondence tournament of a board game starts a correspondence game per match, with its check-in for the first move', function () {
    expect(GameProfile::for(NineMensMorris::SLUG, BoardGame::CORRESPONDENCE)->isDaily())->toBeTrue()
        ->and(TournamentGames::find('nine-mens-morris/correspondence'))->toBe([NineMensMorris::SLUG, BoardGame::CORRESPONDENCE]);

    $tournament = Tournament::factory()->create([
        'game' => NineMensMorris::SLUG, 'mode' => BoardGame::CORRESPONDENCE, 'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::fromArray([], GameProfile::for(NineMensMorris::SLUG, BoardGame::CORRESPONDENCE))->toArray(),
        'capacity' => 2, 'results_mode' => TournamentResultsMode::Players, 'status' => TournamentStatus::Running,
        'slug' => 'board-daily-cup', 'ladder_address' => null,
    ]);

    foreach (range(1, 2) as $index) {
        $user = User::factory()->create();
        TournamentParticipant::query()->create(['tournament_id' => $tournament->id, 'user_id' => $user->id, 'name' => "Player {$index}", 'rating' => 1500 - 10 * $index, 'members' => [$user->id]]);
    }

    app(TournamentBrackets::class)->generate($tournament, str_repeat('ab', 32));
    app(TournamentRunner::class)->sync($tournament);

    $match = TournamentMatch::query()->where('tournament_id', $tournament->id)->where('bracket', '!=', 'bye')->sole();
    [$first, $second] = matchPlayers($match);
    $game = BoardGame::query()->sole();
    $service = app(BoardGameService::class);

    expect($game->only(['mode', 'tournament_match_id', 'white_id', 'black_id']))->toBe(['mode' => BoardGame::CORRESPONDENCE, 'tournament_match_id' => $match->id, 'white_id' => $first->id, 'black_id' => $second->id])
        ->and($game->deadline_ms)->toBe((int) now()->getTimestampMs() + TournamentDeadlines::checkinSeconds($tournament->refresh()) * 1000);

    // From the first move on, a day per move.
    $game = $service->move($game, $first, 'a1', 1);
    expect($game->deadline_ms)->toBe((int) now()->getTimestampMs() + CORRESPONDENCE_DAY_MS);

    $service->resign($game->refresh(), $second);

    expect($match->refresh()->result['winner'])->toBe(0)
        ->and($tournament->refresh()->status)->toBe(TournamentStatus::Finished);
});

/* ---------- Pages ------------------------------------------------------------------------------------------- */

test('the correspondence page sends a challenge, and the other player accepts it there and lands on the board', function () {
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];

    Livewire::actingAs($anna)->test('pages::board.correspondence', ['board' => Checkers::SLUG])
        ->call('pick', $bert->id)
        ->set('color', 'white')
        ->set('message', 'Fern-Dame?')
        ->call('send')
        ->assertSet('error', '')
        ->assertSeeHtml('data-test="outgoing-challenge"');

    $challenge = BoardChallenge::query()->sole();

    // The lobby card says what waits, the dock carries the challenge.
    Livewire::actingAs($bert)->test('pages::board.lobby', ['board' => Checkers::SLUG])
        ->assertSeeHtml('data-test="correspondence-waiting"')
        ->assertSee(route('board.correspondence', Checkers::SLUG), false);
    expect(app(OpenMatches::class)->for($bert)->pluck('key')->all())->toContain('board-challenge-'.$challenge->id);

    Livewire::actingAs($bert)->test('pages::board.correspondence', ['board' => Checkers::SLUG])
        ->assertSeeHtml('data-test="incoming-challenge"')
        ->assertSee('Fern-Dame?')
        ->call('accept', $challenge->id)
        ->assertRedirect(route('board.show', $challenge->refresh()->board_game_id));

    $game = BoardGame::query()->sole();

    expect($game->only(['white_id', 'black_id', 'mode']))->toBe(['white_id' => $anna->id, 'black_id' => $bert->id, 'mode' => BoardGame::CORRESPONDENCE]);

    $this->actingAs($anna)->get(route('board.show', $game))->assertOk()->assertSee('data-mode="correspondence"', false);
    $this->actingAs($anna)->get(route('board.correspondence', Checkers::SLUG))->assertOk()->assertSeeHtml('data-mine="true"');
    // "Your daily games" lists it too, leading to its board; Bert's challenge notice led to the correspondence page, where he answered.
    $this->actingAs($anna)->get(route('me.correspondence'))->assertOk()->assertSeeHtml('data-test="board-correspondence-game"')->assertSee(route('board.show', $game), false);
    expect($bert->notifications()->where('type', 'challenge')->sole()->data['url'])->toBe(route('board.correspondence', Checkers::SLUG));
    // The dock: White's move, on White's side.
    expect(app(OpenMatches::class)->for($anna)->firstWhere('key', 'board-'.$game->id)?->phase)->toBe('your_move');
    auth()->logout();
    $this->get(route('board.correspondence', Checkers::SLUG))->assertOk()->assertSeeHtml('data-test="correspondence-login"');
});

test('the correspondence page offers rated only while it is open, and names the fix when the two do not list each other', function () {
    correspondenceSeason();
    [$anna, $bert] = [correspondencePlayer(), correspondencePlayer()];
    app()->bind(TrustFacts::class, fn () => new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => false];
        }
    });

    Livewire::actingAs($anna)->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG])
        ->assertSeeHtml('data-rated-open="true"')
        ->call('pick', $bert->id)
        ->set('rated', true)
        ->assertSeeHtml('data-test="needs-mutual"')
        ->call('send')
        ->assertSet('error', RatedTrustGate::message(RatedTrustGate::NOT_CONNECTED));

    expect(BoardChallenge::query()->count())->toBe(0);

    // Off, the page is casual only.
    config(['esports.board_games.rated_queue' => false]);
    Livewire::actingAs($anna)->test('pages::board.correspondence', ['board' => NineMensMorris::SLUG])
        ->assertDontSeeHtml('data-test="correspondence-rated"');
});
