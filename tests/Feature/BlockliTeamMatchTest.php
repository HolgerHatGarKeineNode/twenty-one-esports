<?php

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Caster\GameMoments\MatchAnchor;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Dock\OpenMatches;
use App\Support\Engagement\ClanHashrate;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesRuleViolation;
use App\Support\Series\SeriesService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockliOn;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
 * Blockli team matches (NIP rev. 9.25; DerCaddy, 2026-11-06: "Bei den Clans fehlt die Möglichkeit, ein Lineup für
 * Blockli anzulegen", "Bau mir Rapid und orientiere dich strikt an dem Pendant von Schach!"): the chess team match of
 * tests/Feature/Chess/ChessTeamMatchTest.php with a Blockli rapid lineup and Blockli games on the boards. The same
 * steps, the same expectations where the rules are the same: the lineup in the clan page, challenge and accept, the
 * lock by Blockli rapid Elo, the start of each board, board forfeit and void, the whole-team forfeit, the team result
 * with its bonus and attestations, the pages.
 */

beforeEach(function () {
    Queue::fake();
    BlockliOn::play();
    $this->teamMatches = app(ChessTeamMatches::class);
});

/**
 * A ready Blockli rapid lineup: the clan owner as captain (a real key) plus `$players - 1` more active players.
 *
 * @return array{0: Lineup, 1: User, 2: TestSigner}
 */
function blockliTeamLineup(int $players = 3): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->game('blockli', 'rapid')->ready($players - 2)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

/**
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $a
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $b
 */
function blockliTeamChallengeAndAccept(array $a, array $b, bool $rated = false, int $boards = 2): SeriesMatch
{
    $service = app(SeriesService::class);
    $start = now()->addHours(2)->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($a[0]->id, $b[0]->id, 1, $rated, [$start, $start + 3600], $start - 3600, 'gl hf', $boards);
    $match = $service->challenge($a[1], $draft, $a[2]->signTemplates($service->prepareChallenge($a[1], $draft)['templates']));
    $at = $match->proposals[0];
    $service->answer($match, $b[1], 'accepted', $at, $b[2]->signTemplates($service->prepareAnswer($match, $b[1], 'accepted', $at)));

    return $match->refresh();
}

/** @return list<int> */
function blockliActiveIds(Lineup $lineup): array
{
    return array_map(fn ($seat) => $seat->user_id, $lineup->fresh()->activeSeats());
}

/**
 * @return array{0: SeriesMatch, 1: array{0: Lineup, 1: User, 2: TestSigner}, 2: array{0: Lineup, 1: User, 2: TestSigner}}
 */
function lockedBlockliTeamMatch(bool $rated = false, int $boards = 2): array
{
    $a = blockliTeamLineup();
    $b = blockliTeamLineup();
    $match = blockliTeamChallengeAndAccept($a, $b, $rated, $boards);
    $teamMatches = app(ChessTeamMatches::class);
    $teamMatches->name($match, $a[1], array_slice(blockliActiveIds($a[0]), 0, $boards));
    $teamMatches->name($match, $b[1], array_slice(blockliActiveIds($b[0]), 0, $boards));
    test()->travelTo(ChessTeamMatches::lockAt($match->refresh())->copy()->addSecond());
    expect($teamMatches->lock($match))->toBe('locked');

    return [$match->refresh(), $a, $b];
}

/**
 * At the start: every board started, keyed by board.
 *
 * @return array<int, BoardGame>
 */
function startedBlockliBoards(SeriesMatch $match): array
{
    test()->travelTo($match->refresh()->start_at->copy()->addSecond());
    app(ChessTeamMatches::class)->startDue();

    return BoardGame::query()->with(['white', 'black'])->where('series_match_id', $match->id)->orderBy('board')->get()->keyBy('board')->all();
}

/** Play these Blockli moves in turn, White first; returns the game as it is now. */
function playBlockli(BoardGame $game, array $moves): BoardGame
{
    $service = app(BoardGameService::class);

    foreach ($moves as $move) {
        $game = $game->refresh()->loadMissing(['white', 'black']);
        $service->move($game, $game->turn === 'w' ? $game->white : $game->black, $move, $game->ply + 1);
    }

    return $game->refresh();
}

/** Two pawn moves each, then `$loser` ('w' or 'b') resigns: a played result. */
function decideBlockliBoard(BoardGame $game, string $loser): BoardGame
{
    $game = playBlockli($game, ['e1-e2', 'e9-e8', 'e2-e3', 'e8-e7'])->loadMissing(['white', 'black']);
    app(BoardGameService::class)->resign($game, $loser === 'w' ? $game->white : $game->black);

    return $game->refresh();
}

function drawBlockliBoard(BoardGame $game): BoardGame
{
    $game = playBlockli($game, ['e1-e2', 'e9-e8'])->loadMissing(['white', 'black']);
    app(BoardGameService::class)->offerDraw($game, $game->white);
    app(BoardGameService::class)->acceptDraw($game->refresh(), $game->black);

    return $game->refresh();
}

/** A rated team match needs a live season with the Blockli rapid ladder and trusted players. */
function ratedBlockliTeamSetup(): void
{
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
}

/** The attestation of a board as a signed event, or null. */
function blockliBoardAttestation(BoardGame $game): ?SignedEvent
{
    $row = SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->where('source_id', $game->id)->first();

    return $row === null ? null : SignedEvent::fromInput(NostrEvent::query()->findOrFail($row->nostr_event_id)->payload());
}

test('Blockli rapid plays team matches over 2 or 3 boards like chess rapid, and the clan page offers its lineup', function () {
    $registry = app(GameRegistry::class);
    $owner = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id]);

    expect($registry->mode('blockli', 'rapid')->boards)->toBe([2, 3])
        ->and($registry->mode('blockli', 'rapid')->lineupMinimum())->toBe(2)
        ->and($registry->mode('blockli', 'blitz')->boards)->toBe([])
        ->and($registry->mode('blockli', 'correspondence')->boards)->toBe([])
        ->and($registry->teamMatchModes())->toMatchArray(['chess' => ['rapid'], 'blockli' => ['rapid']])
        ->and($registry->isTeamMatchMode('blockli', 'rapid'))->toBeTrue()
        // A team match reports one result per board, as chess.
        ->and($registry->get('blockli')->validateResult($registry->mode('blockli', 'rapid'), ['format' => 'team', 'boards' => ['1-0', '1/2-1/2']]))->toBe([])
        ->and($registry->get('blockli')->validateResult($registry->mode('blockli', 'rapid'), ['format' => 'team', 'boards' => ['1-0']]))->toBe(['boards_not_allowed'])
        ->and($registry->get('blockli')->validateResult($registry->mode('blockli', 'blitz'), ['result' => '0-1']))->toBe([]);

    // The clan page lists the Blockli rapid lineup next to chess rapid, with its button, and opens its builder.
    $this->actingAs($owner)->get(route('clans.manage', $clan))->assertOk()
        ->assertSee('data-test="lineups-blockli"', false)
        ->assertSee('data-test="edit-lineup-blockli-rapid"', false)
        ->assertDontSee('data-test="edit-lineup-blockli-blitz"', false);

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('editLineup', 'rapid', 'blockli')->assertHasNoErrors()
        ->assertSet('editingGame', 'blockli')->assertSet('editing', 'rapid')
        ->call('editLineup', 'blitz', 'blockli')->assertForbidden();
});

test('a Blockli team match is challenged and accepted with its boards on the Blockli rapid ladder, and the lineups lock by Blockli rapid Elo', function () {
    ratedBlockliTeamSetup();
    $a = blockliTeamLineup();
    $b = blockliTeamLineup();
    $match = blockliTeamChallengeAndAccept($a, $b, rated: true, boards: 3);

    expect($match->status)->toBe(SeriesStatus::Accepted)
        ->and($match->game)->toBe('blockli')
        ->and($match->mode)->toBe('rapid')
        ->and($match->boards)->toBe(3)
        ->and($match->isTeamMatch())->toBeTrue()
        ->and($match->playsBoardGames())->toBeTrue()
        ->and($match->ladder_address)->toBe(Ladders::address('blockli', 'rapid'))
        ->and($match->challengeEvent->payload()['tags'])->toContain(['boards', '3']);

    // The weakest Blockli player of side A has the best chess rating: the board order reads Blockli rapid, never chess.
    [$first, $second, $third] = blockliActiveIds($a[0]);
    foreach ([[$first, 1400], [$second, 1600], [$third, 1500]] as [$userId, $rating]) {
        Rating::query()->create(['game' => 'blockli', 'mode' => 'rapid', 'pool' => Rating::CASUAL, 'season' => '', 'subject' => 'user:'.$userId, 'user_id' => $userId, 'rating' => $rating, 'results' => 3]);
    }
    Rating::query()->create(['game' => 'chess', 'mode' => 'rapid', 'pool' => Rating::CASUAL, 'season' => '', 'subject' => 'user:'.$first, 'user_id' => $first, 'rating' => 2400, 'results' => 30]);

    $this->teamMatches->name($match, $a[1], [$first, $second, $third]);
    $this->teamMatches->name($match, $b[1], blockliActiveIds($b[0]));
    $this->travelTo(ChessTeamMatches::lockAt($match)->copy()->addSecond());

    expect($this->teamMatches->lock($match))->toBe('locked');

    $order = SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenger')->orderBy('board')->get();

    expect($order->pluck('user_id')->all())->toBe([$second, $third, $first])
        ->and($order->pluck('rating')->all())->toBe([1600, 1500, 1400])
        ->and($this->teamMatches->standing(User::query()->findOrFail($first), 'blockli'))->toBe(['rating' => 1400, 'pool' => 'casual', 'results' => 3]);
});

test('at the start each board starts once as a Blockli game with its own first-move window, the challenger moving first on odd boards', function () {
    [$match] = lockedBlockliTeamMatch(boards: 2);

    $this->travelTo($match->start_at->copy()->subSecond());
    expect($this->teamMatches->startDue())->toBe(0);

    $this->travelTo($match->start_at->copy()->addSeconds(20));
    $this->artisan('teammatches:tick')->expectsOutputToContain('started 2 board(s)')->assertSuccessful();
    $this->artisan('teammatches:tick')->expectsOutputToContain('started 0 board(s)')->assertSuccessful();

    $games = BoardGame::query()->where('series_match_id', $match->id)->orderBy('board')->get();
    $player = fn (string $side, int $board) => SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', $side)->where('board', $board)->value('user_id');

    expect($games)->toHaveCount(2)
        ->and($this->teamMatches->startDue())->toBe(0)
        ->and($this->teamMatches->startBoard($match, 1))->toBeNull()
        ->and($games->pluck('game')->unique()->all())->toBe(['blockli'])
        ->and($games->pluck('board')->all())->toBe([1, 2])
        ->and($games->pluck('mode')->unique()->all())->toBe(['rapid'])
        ->and($games->pluck('number')->unique()->all())->toBe([null])
        ->and($games->pluck('rated')->unique()->all())->toBe([false])
        ->and($games[0]->white_id)->toBe($player('challenger', 1))
        ->and($games[0]->black_id)->toBe($player('challenged', 1))
        ->and($games[1]->white_id)->toBe($player('challenged', 2))
        ->and($games[1]->black_id)->toBe($player('challenger', 2))
        // Rapid 10+5, and 600 s for the first move from the agreed start, not from the (late) tick.
        ->and($games[0]->initial_ms)->toBe(600_000)
        ->and($games[0]->increment_ms)->toBe(5_000)
        ->and($games[0]->first_move_seconds)->toBe(600)
        ->and($games[0]->deadline_ms)->toBe($match->start_at->getTimestampMs() + 600_000)
        ->and($games[0]->number())->toBe('#'.$match->number.'/1')
        ->and(ChessTeamMatches::gameUrl($games[0]))->toBe(route('board.show', $games[0]));

    // The backstop: the unique index refuses a second board 1.
    expect(fn () => DB::transaction(fn () => BoardGame::query()->create([
        'game' => 'blockli', 'mode' => 'rapid', 'white_id' => $games[0]->white_id, 'black_id' => $games[0]->black_id, 'status' => BoardGameStatus::Active,
        'position' => $games[0]->position, 'turn' => 'w', 'ply' => 0, 'initial_ms' => 600_000, 'increment_ms' => 5_000, 'white_ms' => 600_000, 'black_ms' => 600_000,
        'turn_started_ms' => 0, 'series_match_id' => $match->id, 'board' => 1,
    ])))->toThrow(UniqueConstraintViolationException::class);
});

test('from the lock the named players start no other game, a board starts for them anyway, and its players can neither abort it nor take a move back', function () {
    [$match] = lockedBlockliTeamMatch(boards: 2);
    $named = User::query()->findOrFail(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenged')->where('board', 1)->value('user_id'));
    $reason = function (Closure $action): ?string {
        try {
            $action();
        } catch (BoardRuleViolation $violation) {
            return $violation->reason;
        }

        return null;
    };

    expect(ChessTeamMatches::reservationOf($named)?->id)->toBe($match->id)
        ->and($reason(fn () => app(BoardGameService::class)->start('blockli', $named, User::factory()->create(), 'correspondence')))->toBe(ChessTeamMatches::RESERVED);

    $games = startedBlockliBoards($match);

    expect($games)->toHaveCount(2)
        ->and($games[1]->black_id)->toBe($named->id)
        ->and($reason(fn () => app(BoardGameService::class)->abort($games[1], $games[1]->white)))->toBe('tournament_game')
        ->and($games[1]->refresh()->status)->toBe(BoardGameStatus::Active);

    // As chess boards (NIP rev. 9.25): no take-back on a team board, also right after the move.
    $board = playBlockli($games[1], ['e1-e2'])->loadMissing(['white', 'black']);

    expect(app(BoardGameService::class)->takeBackUntil($board))->toBeNull()
        ->and($reason(fn () => app(BoardGameService::class)->takeBack($board, $board->white)))->toBe('take_back_closed')
        ->and($board->refresh()->ply)->toBe(1);
});

test('a board forfeit counts one board point, is unrated and is attested with the team result', function () {
    ratedBlockliTeamSetup();
    [$match] = lockedBlockliTeamMatch(rated: true);
    $games = startedBlockliBoards($match);
    $service = app(BoardGameService::class);

    expect(collect($games)->every(fn (BoardGame $game): bool => $game->rated && $game->ladder_address === Ladders::address('blockli', 'rapid') && $game->gate_at_accept !== null))->toBeTrue();

    // Board 1: the challenger (White) never moves, the challenged Black opened the board. Board 2: a draw.
    $service->markSeen($games[1], $games[1]->black);
    drawBlockliBoard($games[2]);

    expect(blockliBoardAttestation($games[2]->refresh()))->not->toBeNull();

    $this->travelTo($match->start_at->copy()->addSeconds(601));
    $service->checkClock($games[1]);
    $match->refresh();
    $forfeit = blockliBoardAttestation($games[1]->refresh());

    expect($games[1]->end_reason)->toBe(BoardEndReason::Forfeit->value)
        ->and($games[1]->result)->toBe('0-1')
        ->and(RatingChange::query()->where('source', RatingChange::BOARD)->where('source_id', $games[1]->id)->exists())->toBeFalse()
        ->and($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and($match->winner)->toBe('challenged')
        ->and($match->finished_at)->not->toBeNull()
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 1, 'challenged' => 3])
        ->and($forfeit->tag('resolution'))->toBe('forfeit')
        ->and($forfeit->tag('winner'))->toBe('challenged')
        ->and($forfeit->tagsNamed('elo'))->toBe([])
        ->and($forfeit->tagsNamed('block'))->toBe([])
        ->and($forfeit->tag('match'))->toBe((string) $match->number)
        ->and(ChessTeamMatches::reservationOf($games[1]->white))->toBeNull();
});

test('a board where neither player moved is void and counts no point; the other boards decide', function () {
    ratedBlockliTeamSetup();
    [$match] = lockedBlockliTeamMatch(rated: true);
    $games = startedBlockliBoards($match);

    // Board 2 (the challenged side White): the challenger's Black resigns. Board 1: nobody moves, Black never opens it.
    decideBlockliBoard($games[2], 'b');
    $this->travelTo($match->start_at->copy()->addSeconds(601));
    app(BoardGameService::class)->checkClock($games[1]);
    $match->refresh();
    $void = blockliBoardAttestation($games[1]->refresh());

    expect($games[1]->status)->toBe(BoardGameStatus::Aborted)
        ->and($match->winner)->toBe('challenged')
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 0, 'challenged' => 2])
        ->and($void->tag('resolution'))->toBe('void')
        ->and($void->tag('winner'))->toBe('none')
        ->and($void->tagsNamed('board')[0][3])->toBe('*')
        ->and($void->tagsNamed('elo'))->toBe([])
        ->and($void->tagsNamed('block'))->toBe([]);
});

test('a side that made no first move on any board loses the whole team match by forfeit, attested once and unrated', function () {
    ratedBlockliTeamSetup();
    [$match] = lockedBlockliTeamMatch(rated: true);
    $games = startedBlockliBoards($match);
    $service = app(BoardGameService::class);

    // Board 1: the challenger's White moves, the challenged Black never does. Board 2: the challenged White never moves.
    playBlockli($games[1], ['e1-e2']);
    $service->markSeen($games[2], $games[2]->black);
    $this->travelTo($match->start_at->copy()->addSeconds(1200));
    $service->checkClock($games[1]);
    $service->checkClock($games[2]);
    $match->refresh();

    $attestation = SeasonAttestation::query()->where('source', SeasonAttestation::SERIES)->where('source_id', $match->id)->sole();
    $event = SignedEvent::fromInput(NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload());

    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->winner)->toBe('challenger')
        ->and($event->tag('resolution'))->toBe('forfeit')
        ->and($event->tagsNamed('elo'))->toBe([])
        ->and($event->tagsNamed('board'))->toBe([])
        ->and($event->tagsNamed('block'))->toBe([])
        ->and($attestation->candidate)->toBeNull()
        // The challenge and the answer; a board game has no record to name.
        ->and($event->tagsNamed('e'))->toBe([
            [$match->challengeEvent->event_id, '', $match->challengeEvent->pubkey],
            [$match->answerEvent->event_id, '', $match->answerEvent->pubkey],
        ])
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->count())->toBe(0)
        ->and($this->teamMatches->settle($match))->toBeNull();
});

test('a rated 2:1 Blockli team win adds the team bonus once, and every board is attested with the lineups, its board and the challenge number', function () {
    ratedBlockliTeamSetup();
    [$match, [$lineupA], [$lineupB]] = lockedBlockliTeamMatch(rated: true, boards: 3);
    $games = startedBlockliBoards($match);

    // Board 1 and 3: the challenger plays White; board 2: Black.
    decideBlockliBoard($games[1], 'b');
    decideBlockliBoard($games[2], 'w');
    decideBlockliBoard($games[3], 'w');
    $match->refresh();
    $clanA = $lineupA->clan->address();
    $clanB = $lineupB->clan->address();
    $hashrate = app(ClanHashrate::class)->breakdown('season-1');

    expect($match->winner)->toBe('challenger')
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 4, 'challenged' => 2])
        ->and($hashrate[$clanA]['teamWins'])->toBe(1)
        ->and($hashrate[$clanA]['bonus'])->toBe(5)
        ->and($hashrate[$clanB]['bonus'] ?? 0)->toBe(0)
        ->and($hashrate[$clanA]['points'])->toBe(2 * 3 + 1 * 1 + 5)
        ->and($hashrate[$clanB]['points'])->toBe(1 * 3 + 2 * 1);

    $board = $games[2]->refresh();
    $event = blockliBoardAttestation($board);
    $challenger = User::query()->findOrFail(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenger')->where('board', 2)->value('user_id'));
    $challenged = User::query()->findOrFail(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenged')->where('board', 2)->value('user_id'));
    $ladder = Ladders::address('blockli', 'rapid');

    expect(array_slice($event->tags, 0, 9))->toBe([
        ['e', $match->challengeEvent->event_id, '', $match->challengeEvent->pubkey],
        ['e', $match->answerEvent->event_id, '', $match->answerEvent->pubkey],
        ['a', $ladder, ''],
        ['a', $match->challenger_lineup_address, '', 'challenger'],
        ['a', $match->challenged_lineup_address, '', 'challenged'],
        ['p', $challenger->pubkey, '', 'challenger', 'player'],
        ['p', $challenged->pubkey, '', 'challenged', 'player'],
        ['board', '2', $challenged->pubkey, $challenger->pubkey, '0-1'],
        ['resolution', 'admin'],
    ])
        ->and($match->challenger_lineup_address)->toContain('/blockli/rapid')
        // Black won board 2, and Black is the challenger side there.
        ->and($event->tag('winner'))->toBe('challenger')
        ->and($event->tagsNamed('elo'))->toHaveCount(2)
        ->and($event->tag('match'))->toBe((string) $match->number)
        ->and(collect($event->tagsNamed('clan'))->mapWithKeys(fn (array $tag) => [$tag[0] => $tag[1]])->all())->toBe([$challenged->pubkey => $clanB, $challenger->pubkey => $clanA])
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->where('source_id', $board->id)->value('board'))->toBe(2)
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->where('source_id', $board->id)->value('label'))->toBe('#'.$match->number.'/2')
        // Rated on the Blockli rapid ladder, never on chess.
        ->and(Rating::query()->where(['game' => 'blockli', 'mode' => 'rapid', 'pool' => Rating::RATED])->count())->toBe(6)
        ->and(Rating::query()->where('game', 'chess')->count())->toBe(0)
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->count())->toBe(3)
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::SERIES)->count())->toBe(0);
});

test('a friendly Blockli team match is decided on board points and earns no hashrate', function () {
    openSeason(['slug' => 'season-1']);
    [$match] = lockedBlockliTeamMatch(rated: false);
    $games = startedBlockliBoards($match);
    $events = NostrEvent::query()->count();

    decideBlockliBoard($games[1], 'b');
    decideBlockliBoard($games[2], 'w');

    expect($match->refresh()->winner)->toBe('challenger')
        ->and($match->rated)->toBeFalse()
        ->and(app(ClanHashrate::class)->breakdown('season-1'))->toBe([])
        ->and(NostrEvent::query()->count())->toBe($events);
});

test('the team match page shows every Blockli board with its clocks and a link to the board, and the board page leads back to the team match', function () {
    [$match] = lockedBlockliTeamMatch(boards: 2);
    $games = startedBlockliBoards($match);
    playBlockli($games[1], ['e1-e2']);

    $page = $this->get(route('matches.show', $match->number))->assertOk();
    $page->assertSee('data-test="team-match-boards"', false)
        ->assertSee('data-kind="board"', false)
        ->assertSee(route('board.show', $games[1]), false)
        ->assertSee('data-test="team-board-move-1"', false)
        ->assertSee('data-test="team-board-clock-1-w"', false)
        ->assertDontSee('data-test="team-board-fen-1"', false);

    $black = $games[2]->black;
    $this->actingAs($black)->get(route('board.show', $games[2]))->assertOk()
        ->assertSee('data-test="team-board-banner"', false)
        ->assertSee('#'.$match->number, false)
        ->assertDontSee('data-test="abort"', false);

    // Opening the board counts as seen, as on a chess board.
    expect($games[2]->refresh()->black_seen_at)->not->toBeNull()
        ->and($games[2]->white_seen_at)->toBeNull();
});

test('the challenge form offers a Blockli rapid lineup boards and friendly or rated, and the rules page names Blockli team matches', function () {
    [$lineup, $captain] = blockliTeamLineup();

    [$other] = blockliTeamLineup();

    $this->actingAs($captain)->get(route('challenges.create', ['lineup' => $lineup->id]))->assertOk();

    // The wizard (P4): friendly or rated and the boards are step 3, after the lineup and the opponent.
    Livewire::actingAs($captain)->test('pages::challenges.create', ['lineupId' => $lineup->id])
        ->call('next')->call('pickOpponent', $other->id)->call('next')->assertSet('step', 3)
        ->assertSet('boards', 2)
        ->assertSee(__('Friendly'));

    $this->get(route('rules'))->assertOk()
        ->assertSee(__('Two clans play chess or Blockli on 2 or 3 boards at the same time, all in :mode. The board points decide the team match.', ['mode' => 'Rapid 10+5']), false);
});

test('the public clan page shows a Blockli rapid lineup with its own team challenge, and one challenge per game beside chess', function () {
    [$blockli] = blockliTeamLineup();
    $outsider = User::factory()->create();

    // Only a Blockli rapid lineup: the card, the lineup and the challenge against it.
    $this->actingAs($outsider)->get(route('clans.show', $blockli->clan))->assertOk()
        ->assertSeeHtml('data-test="clan-team-matches"')
        ->assertSeeHtml('data-test="rapid-lineup"')
        ->assertSee('Team matches')
        ->assertSee('Blockli · rapid lineup')
        ->assertSeeHtml('href="'.e(route('challenges.create', ['to' => $blockli->id])).'"');

    // Beside a chess rapid lineup: one challenge per game, each against its own lineup.
    $chess = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => $blockli->clan_id]);
    $html = $this->actingAs($outsider)->get(route('clans.show', $blockli->clan))->assertOk()
        ->assertSee('Challenge to a Chess team match')
        ->assertSee('Challenge to a Blockli team match')
        ->assertSeeInOrder([e(route('challenges.create', ['to' => $chess->id])), e(route('challenges.create', ['to' => $blockli->id]))], false)
        ->getContent();

    expect(substr_count($html, 'data-test="challenge-team-match"'))->toBe(2)
        ->and(substr_count($html, 'data-test="rapid-lineup"'))->toBe(2);

    // A captain of a Blockli rapid lineup gets their own lineup picked for the Blockli challenge.
    [$mine, $captain] = blockliTeamLineup();
    $this->actingAs($captain)->get(route('clans.show', $blockli->clan))->assertOk()
        ->assertSeeHtml('href="'.e(route('challenges.create', ['lineup' => $mine->id, 'to' => $blockli->id])).'"');
});

test('the match list reads the team match number of Blockli boards with the list, not once per board', function () {
    [$match] = lockedBlockliTeamMatch(boards: 3);
    $boards = startedBlockliBoards($match);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $html = $this->get(route('matches.index'))->assertOk()->getContent();
    DB::disableQueryLog();

    $perBoard = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'select "number" from "series_matches"'));

    expect($perBoard)->toBe([])
        ->and($html)->toContain($boards[3]->number());
});

test('the clan page names the games of its team lineups in German', function () {
    [$blockli] = blockliTeamLineup();
    Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => $blockli->clan_id]);
    app()->setLocale('de');

    $this->withSession(['locale' => 'de'])->actingAs(User::factory()->create())->get(route('clans.show', $blockli->clan))->assertOk()
        ->assertSee('Zum Schach-Mannschaftskampf herausfordern')
        ->assertSee('Schach · Rapid-Aufstellung')
        ->assertDontSee('Chess-Mannschaftskampf');
});

test('a side without a Blockli lineup at the lock loses the whole team match by forfeit, unrated; neither side named: void', function () {
    ratedBlockliTeamSetup();
    $a = blockliTeamLineup();
    $b = blockliTeamLineup();
    $match = blockliTeamChallengeAndAccept($a, $b, rated: true);
    $this->teamMatches->name($match, $a[1], array_slice(blockliActiveIds($a[0]), 0, 2));

    $this->travelTo(ChessTeamMatches::lockAt($match)->copy()->subMinute());
    expect($this->teamMatches->lockDue())->toBe(['locked' => 0, 'forfeited' => 0, 'voided' => 0]);

    $this->travelTo(ChessTeamMatches::lockAt($match)->copy()->addSecond());
    expect($this->teamMatches->lockDue())->toBe(['locked' => 0, 'forfeited' => 1, 'voided' => 0]);

    $match->refresh();
    expect($match->status)->toBe(SeriesStatus::Resolved)
        ->and($match->resolution)->toBe(SeriesResolution::Forfeit)
        ->and($match->winner)->toBe('challenger')
        ->and(BoardGame::query()->where('series_match_id', $match->id)->exists())->toBeFalse()
        ->and(Rating::query()->where('game', 'blockli')->where('mode', 'rapid')->count())->toBe(0)
        ->and(ChessTeamMatches::reservationOf($a[1]))->toBeNull();

    $void = blockliTeamChallengeAndAccept(blockliTeamLineup(), blockliTeamLineup());
    $this->travelTo(ChessTeamMatches::lockAt($void)->copy()->addSecond());
    expect($this->teamMatches->lock($void))->toBe('void')
        ->and($void->refresh()->resolution)->toBe(SeriesResolution::Void)
        ->and($void->winner)->toBe('none');
});

test('the pair limit counts per game: a rated Blockli team match blocks a second one for seven days, never a chess one of the same clans', function () {
    ratedBlockliTeamSetup();
    $series = app(SeriesService::class);
    $a = blockliTeamLineup();
    $b = blockliTeamLineup();
    $first = blockliTeamChallengeAndAccept($a, $b, rated: true);
    $first->update(['status' => SeriesStatus::Resolved, 'resolution' => SeriesResolution::Void, 'winner' => 'none', 'finished_at' => now()]);
    $draft = fn (Lineup $from, Lineup $to): ChallengeDraft => new ChallengeDraft($from->id, $to->id, 1, true, [$start = now()->addHours(2)->startOfMinute()->getTimestamp(), $start + 3600], $start - 3600, 'gl hf', 2);

    expect(fn () => $series->prepareChallenge($b[1], $draft($b[0], $a[0])))->toThrow(SeriesRuleViolation::class, 'rated team match in the last 7 days');

    // The same two clans in chess rapid: not blocked by the Blockli one.
    $chessA = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => $a[0]->clan_id]);
    $chessB = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => $b[0]->clan_id]);
    expect($series->prepareChallenge($a[1], $draft($chessA, $chessB))['templates'])->toHaveCount(1);

    $this->travelTo(now()->setTimestamp($first->start_at->getTimestamp())->addDays(7)->addMinute());
    expect($series->prepareChallenge($b[1], $draft($b[0], $a[0]))['templates'])->toHaveCount(1);
});

test('a Black who misses the first move after White opened loses the board by forfeit', function () {
    [$match] = lockedBlockliTeamMatch(boards: 2);
    $games = startedBlockliBoards($match);
    $board = playBlockli($games[1], ['e1-e2']);

    expect($board->deadline_ms)->not->toBeNull();

    $this->travelTo(Carbon::createFromTimestampMs($board->deadline_ms + 1000));
    app(BoardGameService::class)->checkClock($board->loadMissing(['white', 'black']));
    $board->refresh();

    expect($board->status)->toBe(BoardGameStatus::Finished)
        ->and($board->end_reason)->toBe(BoardEndReason::Forfeit->value)
        ->and($board->result)->toBe('1-0')
        ->and(collect(ChessTeamMatches::boardResults($match->refresh()))->firstWhere('board', 1)['outcome'])->toBe('forfeit');
});

test('a running Blockli team board is named as a clan team match board by the caster and carries #number/board in the dock', function () {
    [$match] = lockedBlockliTeamMatch(boards: 2);
    $games = startedBlockliBoards($match);
    $anchor = MatchAnchor::of($games[2], 'Blockli', 'Rapid');
    $item = app(OpenMatches::class)->for($games[2]->white)->first(fn ($item) => $item->key === 'board-'.$games[2]->id);

    expect($anchor)->toBe('Rapid-Blocklipartie am Brett 2 eines Clan-Duells')
        ->and($item?->number)->toBe('#'.$match->number.'/2');
});
