<?php

/*
| Board games mine (plan "Mühle und Dame", P6): a rated game of nine men's
| morris or checkers is paired by the rated queue with the trust gate pinned,
| rated on its season ladder, attested (`2154`) and mined with the weight the
| season set for it; the two board games share one share and one daily
| limit (`board-games`), the pairing limit and consensus rule 2 (minimum
| moves) hold for them as for chess, and chess keeps its weights. The admin
| season page proposes their values and never redistributes on its own.
*/

use App\Enums\BoardEndReason;
use App\Enums\BoardGameStatus;
use App\Games\Checkers;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\Admin;
use App\Models\BoardGame;
use App\Models\BoardQueueEntry;
use App\Models\Clan;
use App\Models\FairPlayVoid;
use App\Models\NostrEvent;
use App\Models\QuestCredit;
use App\Models\Rating;
use App\Models\RatingChange;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardQueue;
use App\Support\Board\BoardRuleViolation;
use App\Support\Board\RatedBoard;
use App\Support\Engagement\ClanHashrate;
use App\Support\Engagement\Quests;
use App\Support\FairPlay\AccountLinks;
use App\Support\Rating\ClanRating;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\ChainOverview;
use App\Support\SeasonChain\Opponents;
use App\Support\SeasonChain\SeasonParameters;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\Ladders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

beforeEach(function () {
    Queue::fake();
    NineMensMorrisOn::play();
    CheckersGame::play();
    config(['esports.board_games.rated_queue' => true]);
    app()->bind(TrustFacts::class, TrustedFacts::class);
});

/**
 * A live season released before the board games joined: Block 0 published
 * the ladder of every other game and mode, none of a board game. Both board
 * games are switched on again afterwards.
 */
function seasonBeforeBoardGames(): Season
{
    config(['esports.board_games.games.'.NineMensMorris::SLUG.'.enabled' => false, 'esports.board_games.games.'.Checkers::SLUG.'.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $season = openSeason();
    NineMensMorrisOn::play();
    CheckersGame::play();

    return $season;
}

/**
 * A live season whose genesis lets the board games mine: the defaults with
 * the board games' weights (nine men's morris 1.5, checkers 1), their group's
 * share and daily limit, and the values given.
 *
 * @param  array<string, mixed>  $parameters
 */
function boardMiningSeason(array $parameters = []): Season
{
    $defaults = ChainDraft::defaults();

    $season = openSeason(['parameters' => [
        'weights' => [...$defaults['weights'], 'nine-mens-morris/blitz' => 1500, 'checkers/blitz' => 1000],
        'groups' => $defaults['groups'],
        'shares' => ['chess' => 30, 'rocket-league' => 35, 'ea-sports-fc' => 25, 'board-games' => 10],
        'daily' => [...$defaults['daily'], 'board-games' => 5],
        'pairlimit' => $defaults['pairlimit'],
        'subtree' => $defaults['subtree'],
        'moves' => 1,
        ...$parameters,
    ]]);
    publishLadders($season);

    return $season;
}

/** A rated game of `$slug` the league pinned for White and Black, as the rated queue starts one. */
function ratedBoardGame(string $slug, User $white, User $black): BoardGame
{
    $game = app(BoardGameService::class)->start($slug, $white, $black, ratedGate: app(RatedBoard::class)->pin($white, $black));

    expect($game->rated)->toBeTrue();

    return $game;
}

/** Both first moves of a rated game, then Black resigns: a decisive rated win of White after one full move. */
function whiteWinsAfterOneMove(BoardGame $game): BoardGame
{
    $service = app(BoardGameService::class);
    [$first, $second] = $game->game === Checkers::SLUG ? ['c3-d4', 'f6-e5'] : ['a1', 'a4'];
    $game = $service->move($game, $game->white, $first, 1);
    $game = $service->move($game->refresh(), $game->black, $second, 2);

    return $service->resign($game->refresh(), $game->black)->refresh();
}

/* ---------- The rated queue ---------------------------------------------------------------------------------- */

test('the rated queue pairs two rated searches with the gate and clans pinned; casual and rated searches never pair', function () {
    boardMiningSeason();
    [$anna, $bert, $cleo] = User::factory()->count(3)->create();
    $queue = app(BoardQueue::class);

    expect($queue->join($anna, NineMensMorris::SLUG, rated: true))->toBeNull()
        // A casual search waits beside a rated one: the two never pair.
        ->and($queue->join($cleo, NineMensMorris::SLUG))->toBeNull()
        ->and(BoardQueueEntry::query()->where('user_id', $anna->id)->value('rated'))->toBeTrue();

    $game = $queue->join($bert, NineMensMorris::SLUG, rated: true);

    expect($game)->toBeInstanceOf(BoardGame::class)
        ->and($game->rated)->toBeTrue()
        ->and($game->number)->toBeInt()
        ->and($game->ladder_address)->toBe(Ladders::address(NineMensMorris::SLUG, 'blitz'))
        ->and($game->gate_at_accept['players'])->toHaveKeys([$anna->pubkey, $bert->pubkey])
        ->and([$game->white_id, $game->black_id])->toEqualCanonicalizing([$anna->id, $bert->id])
        ->and(BoardQueueEntry::query()->where('user_id', $cleo->id)->exists())->toBeTrue();
});

test('the rated queue refuses while it is not offered or no season is live, and pairs nobody the gate turns away', function () {
    [$anna, $bert] = User::factory()->count(2)->create();
    $queue = app(BoardQueue::class);
    $refusal = function (Closure $join): ?string {
        try {
            $join();
        } catch (BoardRuleViolation $violation) {
            return $violation->reason;
        }

        return null;
    };

    // Before Block 0 there is no ladder.
    expect(Ladders::address(Checkers::SLUG, 'blitz'))->toBeNull()
        ->and($refusal(fn () => $queue->join($anna, Checkers::SLUG, rated: true)))->toBe('rated_not_open');

    boardMiningSeason();
    config(['esports.board_games.rated_queue' => false]);
    expect($refusal(fn () => $queue->join($anna, Checkers::SLUG, rated: true)))->toBe('rated_not_open')
        ->and(ChainOverview::mines('checkers/blitz'))->toBeFalse();

    // Offered, but the two do not list each other: both wait, no game.
    config(['esports.board_games.rated_queue' => true]);
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

    $queue = app(BoardQueue::class);

    expect($queue->join($anna, Checkers::SLUG, rated: true))->toBeNull()
        ->and($queue->join($bert, Checkers::SLUG, rated: true))->toBeNull()
        ->and(BoardGame::query()->count())->toBe(0)
        ->and(ChainOverview::mines('checkers/blitz'))->toBeTrue();
});

test('a switched-off board game has no ladder, and a switched-on one has the season\'s once the league published it', function () {
    $season = seasonBeforeBoardGames();

    // Live, but its ladder not published yet (a season released before the board games joined): closed.
    expect(Ladders::address(NineMensMorris::SLUG, 'blitz'))->toBeNull()
        ->and(Ladders::address('chess', 'blitz'))->not->toBeNull();

    publishLadders($season);

    expect(Ladders::address(NineMensMorris::SLUG, 'blitz'))->toBe(Ladders::KIND.':'.$season->league_pubkey.':nine-mens-morris/blitz/'.$season->slug);

    config(['esports.board_games.games.checkers.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(Ladders::address(Checkers::SLUG, 'blitz'))->toBeNull()
        ->and(ChainOverview::mines('checkers/blitz'))->toBeFalse()
        ->and(Ladders::address(NineMensMorris::SLUG, 'blitz'))->not->toBeNull();
});

/* ---------- A win mines ------------------------------------------------------------------------------------- */

test('a rated win of nine men\'s morris is rated, attested and mines a block with the weight the season set for it', function () {
    $season = boardMiningSeason(['moves' => 10]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $game = ratedBoardGame(NineMensMorris::SLUG, $anna, $bert);

    foreach (NineMensMorrisOn::BLOCKING_GAME as $move) {
        $game = app(BoardGameService::class)->move($game->refresh(), $game->player($game->turn), $move, $game->ply + 1);
    }

    $game = $game->refresh();

    $attestation = SeasonAttestation::query()->sole();
    $tags = NostrEvent::query()->findOrFail($attestation->nostr_event_id)->payload()['tags'];
    $parameters = $season->chainParameters();

    expect($game->result)->toBe('1-0')
        ->and(intdiv($game->ply + 1, 2))->toBe(10)
        ->and(Rating::query()->where(['pool' => Rating::RATED, 'game' => NineMensMorris::SLUG, 'user_id' => $anna->id])->value('rating'))->toBe(1020)
        ->and(Rating::query()->where(['pool' => Rating::RATED, 'game' => 'chess'])->count())->toBe(0)
        ->and($attestation->only(['source', 'source_id', 'height', 'rule', 'game', 'mode', 'match_number']))
        ->toBe(['source' => SeasonAttestation::BOARD, 'source_id' => $game->id, 'height' => 1, 'rule' => null, 'game' => NineMensMorris::SLUG, 'mode' => 'blitz', 'match_number' => $game->number])
        // The configured weight (1.5), not chess's: the reward per player of era 1 at 1500 thousandths.
        ->and($attestation->reward_per_player)->toBe($parameters->rewardPerPlayer(1500, 1))
        ->and($attestation->reward_per_player)->toBeGreaterThan($parameters->rewardPerPlayer(1000, 1))
        ->and($attestation->candidate['weight_key'])->toBe('nine-mens-morris/blitz')
        ->and($tags)->toContain(
            ['a', $game->ladder_address, ''],
            ['p', $anna->pubkey, '', 'challenger'],
            ['p', $bert->pubkey, '', 'challenged'],
            ['board', '1', $anna->pubkey, $bert->pubkey, '1-0'],
            ['resolution', 'admin'],
            ['winner', 'challenger'],
            ['elo', $anna->pubkey, '1000', '1020'],
            ['elo', $bert->pubkey, '1000', '980'],
            ['match', (string) $game->number],
        )
        ->and(collect($tags)->firstWhere(0, 'block'))->toBe(['block', '1', $season->genesisId()]);
});

test('consensus rule 2 holds for board games: a win with fewer full moves than `moves` does not mine', function () {
    boardMiningSeason(['moves' => 20]);
    [$anna, $bert] = User::factory()->count(2)->create();

    whiteWinsAfterOneMove(ratedBoardGame(Checkers::SLUG, $anna, $bert));

    expect(SeasonAttestation::query()->sole()->only(['height', 'reason']))->toBe(['height' => null, 'reason' => 'too-few-moves'])
        // Rated all the same: rule 2 decides the block, not the rating.
        ->and(RatingChange::query()->where('source', RatingChange::BOARD)->count())->toBe(2);
});

test('a casual board game and a draw are never block candidates', function () {
    boardMiningSeason();
    [$anna, $bert] = User::factory()->count(2)->create();
    $service = app(BoardGameService::class);

    whiteWinsAfterOneMove($service->start(Checkers::SLUG, $anna, $bert));
    $draw = ratedBoardGame(NineMensMorris::SLUG, $anna, $bert);
    $service->offerDraw($draw, $anna);
    $service->acceptDraw($draw->refresh(), $bert);

    expect(SeasonAttestation::query()->count())->toBe(1)
        ->and(SeasonAttestation::query()->sole()->only(['source_id', 'candidate', 'height']))->toBe(['source_id' => $draw->id, 'candidate' => null, 'height' => null]);
});

test('a rated board win counts for the clans pinned at the pairing: clan hashrate and the attestation\'s clan rows', function () {
    $season = boardMiningSeason();
    [$anna, $bert] = User::factory()->count(2)->create();
    $annaClan = Clan::factory()->create(['owner_id' => $anna->id]);
    Clan::factory()->create(['owner_id' => $bert->id]);

    $game = whiteWinsAfterOneMove(ratedBoardGame(Checkers::SLUG, $anna, $bert));
    $tags = NostrEvent::query()->findOrFail(SeasonAttestation::query()->sole()->nostr_event_id)->payload()['tags'];
    $hashrate = app(ClanHashrate::class)->breakdown($season->slug);

    expect($game->clans_at_accept)->toHaveKeys([$anna->pubkey, $bert->pubkey])
        ->and($tags)->toContain(['clan', $anna->pubkey, $annaClan->address()])
        ->and($hashrate[$annaClan->address()]['players'][$anna->pubkey] ?? null)->toBe(ClanRating::fromConfig()->winPoints);
});

/* ---------- Farming: pairing limit and daily limit ---------------------------------------------------------- */

test('the pairing limit holds for a board game: the same two players mine once a day and three times a season in it', function () {
    boardMiningSeason(['pairlimit' => [1, 3]]);
    [$anna, $bert] = User::factory()->count(2)->create();

    whiteWinsAfterOneMove(ratedBoardGame(NineMensMorris::SLUG, $anna, $bert));
    // Colours swapped: the pairing is the two players, whoever has White.
    whiteWinsAfterOneMove(ratedBoardGame(NineMensMorris::SLUG, $bert, $anna));

    expect(SeasonAttestation::query()->orderBy('id')->get(['height', 'reason'])->toArray())
        ->toBe([['height' => 1, 'reason' => null], ['height' => null, 'reason' => 'pairing-daily-limit']]);

    // The season's limit of three: two more days, one block each, then no more for this pairing.
    foreach ([1, 2, 3] as $day) {
        $this->travelTo(CarbonImmutable::now()->utc()->addDay()->startOfDay()->addHour());
        whiteWinsAfterOneMove(ratedBoardGame(NineMensMorris::SLUG, $anna, $bert));
    }

    expect(SeasonAttestation::query()->orderBy('id')->pluck('reason')->all())
        ->toBe([null, 'pairing-daily-limit', null, null, 'pairing-season-limit']);
});

test('one daily limit for both board games: their group counts a player\'s blocks of either game together', function () {
    boardMiningSeason(['daily' => ['chess' => 5, 'rocket-league' => 5, 'ea-sports-fc' => 5, 'board-games' => 1]]);
    [$anna, $bert, $cleo] = User::factory()->count(3)->create();

    whiteWinsAfterOneMove(ratedBoardGame(NineMensMorris::SLUG, $anna, $bert));
    // Another opponent, another board game, the same day: the group's limit of one is used up.
    whiteWinsAfterOneMove(ratedBoardGame(Checkers::SLUG, $anna, $cleo));
    // Another winner still mines.
    whiteWinsAfterOneMove(ratedBoardGame(Checkers::SLUG, $cleo, $bert));

    expect(SeasonAttestation::query()->orderBy('id')->get(['height', 'reason', 'subject'])->toArray())
        ->toBe([['height' => 1, 'reason' => null, 'subject' => null], ['height' => null, 'reason' => 'player-daily-limit', 'subject' => $anna->pubkey], ['height' => 2, 'reason' => null, 'subject' => null]]);

    // The next UTC day the limit is fresh.
    $this->travelTo(CarbonImmutable::now()->utc()->addDay()->startOfDay()->addHour());
    whiteWinsAfterOneMove(ratedBoardGame(Checkers::SLUG, $anna, $cleo));

    expect(SeasonAttestation::query()->latest('id')->first()->height)->toBe(3);
});

/* ---------- Chess keeps its weights; the draft proposes, never redistributes -------------------------------- */

test('chess keeps its weights and shares: the config, the default draft and a season from it', function () {
    $defaults = ChainDraft::defaults();
    $season = SeasonParameters::fromDraft($defaults, 'pre-season', CarbonImmutable::now());

    expect(config('season.chain.weights'))->toMatchArray(['chess/blitz' => 1000, 'chess/correspondence' => 2000])
        ->and($defaults['weights'])->toMatchArray(['chess/blitz' => 1000, 'chess/correspondence' => 2000])
        ->and($season->genesis->weightFor('chess/blitz'))->toBe(1000)
        ->and($season->genesis->weightFor('chess/correspondence'))->toBe(2000)
        ->and($defaults['shares'])->toBe(['chess' => 35, 'rocket-league' => 40, 'ea-sports-fc' => 25])
        // The board games are in the draft as a group, without a weight: they do not mine until the board says so.
        ->and($defaults['groups'])->toBe(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27'], 'board-games' => ['nine-mens-morris', 'checkers']])
        // Correspondence (P8) is a mode of each board game with its own weight row.
        ->and(ChainDraft::table($defaults)['board-games'])->toBe(['nine-mens-morris/blitz', 'nine-mens-morris/correspondence', 'checkers/blitz', 'checkers/correspondence'])
        ->and($season->genesis->weightFor('nine-mens-morris/blitz'))->toBe(0);

    $proposal = ChainDraft::boardGamesProposal($defaults);

    expect(array_intersect_key($proposal['weights'], ['chess/blitz' => 1, 'chess/correspondence' => 1]))->toBe([]);
});

test('the board games\' proposal shrinks the other shares in proportion to exactly 100 %, and only while they do not mine', function () {
    $defaults = ChainDraft::defaults();

    expect(ChainDraft::boardGamesProposal($defaults))->toBe([
        // Correspondence (P8) proposed at twice blitz, as chess daily.
        'weights' => ['nine-mens-morris/blitz' => 1000, 'nine-mens-morris/correspondence' => 2000, 'checkers/blitz' => 1000, 'checkers/correspondence' => 2000],
        'shares' => ['chess' => 32, 'rocket-league' => 36, 'ea-sports-fc' => 22, 'board-games' => 10],
        'daily' => ['board-games' => 5],
    ])
        // Room enough: nothing shrinks.
        ->and(ChainDraft::boardGamesProposal([...$defaults, 'shares' => ['chess' => 30, 'rocket-league' => 30, 'ea-sports-fc' => 20]])['shares'])
        ->toBe(['chess' => 30, 'rocket-league' => 30, 'ea-sports-fc' => 20, 'board-games' => 10])
        // Mining already: no proposal.
        ->and(ChainDraft::boardGamesProposal([...$defaults, 'weights' => [...$defaults['weights'], 'checkers/blitz' => 500]]))->toBeNull();

    // Switched off: no row, no group, no proposal.
    config(['esports.board_games.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    expect(ChainDraft::boardGamesProposal(ChainDraft::defaults()))->toBeNull()
        ->and(ChainDraft::defaults()['groups'])->toBe(['ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27']]);
});

/* ---------- The lobby's rated search ------------------------------------------------------------------------ */

/** A player with a signer, so an opponent list can be signed as the browser would. */
function boardLobbyPlayer(string $name): array
{
    $signer = new TestSigner;

    return [User::factory()->withPubkey($signer->pubkey)->create(['name' => $name]), $signer];
}

/** Add $opponent to $player's opponent list, signed. */
function boardLobbyList(User $player, TestSigner $signer, User $opponent): void
{
    $opponents = app(Opponents::class);
    $opponents->add($player, $opponent, $signer->signTemplates($opponents->prepareAdd($player, $opponent)));
    test()->travel(1)->seconds();
}

test('the lobby offers no rated search while the rated queue is off, and refuses one', function () {
    boardMiningSeason();
    config(['esports.board_games.rated_queue' => false]);
    [$anna] = boardLobbyPlayer('Anna');

    // As in the chess lobby: Rated is there but disabled, with the reason behind "?" (P5 of plan mempool-streifen).
    Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => Checkers::SLUG])
        ->assertSee('data-test="game-kind" data-rated-open="false"', false)
        ->assertSeeHtml('data-test="kind-rated-badge"')
        ->assertSee('Rated Checkers is not open yet. Games are casual for now.')
        ->assertSee('data-test="find-opponent"', false)
        ->call('findOpponent', true)
        ->assertSet('error', 'Rated Checkers is not open yet. Games are casual for now.');

    expect(BoardQueueEntry::query()->count())->toBe(0);
});

test('the lobby\'s rated search: closed with the reason for a player who lists nobody back, open for two who list each other, who are paired rated', function () {
    boardMiningSeason();
    [$anna, $annaSigner] = boardLobbyPlayer('Anna');
    [$bert, $bertSigner] = boardLobbyPlayer('Bert');

    Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->assertSee('data-rated-open="false"', false)
        ->assertSee('Rated play needs a player you list each other with.');

    boardLobbyList($anna, $annaSigner, $bert);
    boardLobbyList($bert, $bertSigner, $anna);

    Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->assertSee('data-rated-open="true"', false)
        ->call('findOpponent', true)
        ->assertSet('error', '')
        ->assertSee('data-rated="true"', false);

    Livewire::actingAs($bert)->test('pages::board.lobby', ['board' => NineMensMorris::SLUG])
        ->call('findOpponent', true)
        ->assertRedirect(route('board.show', BoardGame::query()->sole()));

    expect(BoardGame::query()->sole()->rated)->toBeTrue();
});

test('P57 in the board lobby: searching rated beside players it does not list each other with says so in counts and offers casual', function () {
    boardMiningSeason();
    [$anna, $annaSigner] = boardLobbyPlayer('Anna');
    [$bert, $bertSigner] = boardLobbyPlayer('Bert');
    [$cleo, $cleoSigner] = boardLobbyPlayer('Cleo');
    boardLobbyList($anna, $annaSigner, $bert);
    boardLobbyList($bert, $bertSigner, $anna);
    // Cleo lists Anna; Anna does not list Cleo.
    boardLobbyList($cleo, $cleoSigner, $anna);
    // Trusted all, connected only where the two list each other (the real lists, as the trust job reads them).
    app()->bind(TrustFacts::class, fn () => new class implements TrustFacts
    {
        public function available(): bool
        {
            return true;
        }

        public function at(array $players, array $gatekeepers): array
        {
            [$a, $b] = User::query()->whereIn('pubkey', $gatekeepers)->get()->all() + [null, null];

            return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => $a !== null && $b !== null && app(Opponents::class)->listEachOther($a, $b)];
        }
    });
    app(BoardQueue::class)->join($cleo, Checkers::SLUG, rated: true);

    $page = Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => Checkers::SLUG])
        ->call('findOpponent', true)
        ->assertSee('data-test="needs-mutual"', false)
        ->assertSee('1 other player searches rated right now, but you do not list each other, so the queue cannot pair you.')
        ->assertSee('1 player in the queue lists you.')
        ->assertDontSee('Cleo');

    $page->call('searchCasualInstead')->assertSee('data-rated="false"', false);

    expect(BoardQueueEntry::query()->where('user_id', $anna->id)->value('rated'))->toBeFalse();
});

/* ---------- Fair play: linked accounts ---------------------------------------------------------------------- */

test('linking two accounts voids their board games like their chess games: no result, rated Elo reverted, the void kept', function () {
    boardMiningSeason();
    [$main, $second, $stranger] = User::factory()->count(3)->create();
    $rated = whiteWinsAfterOneMove(ratedBoardGame(NineMensMorris::SLUG, $second, $main));
    $casual = whiteWinsAfterOneMove(app(BoardGameService::class)->start(Checkers::SLUG, $main, $second));
    $kept = whiteWinsAfterOneMove(ratedBoardGame(NineMensMorris::SLUG, $main, $stranger));
    $rating = fn (User $user): array => Rating::query()->where(['pool' => Rating::RATED, 'game' => NineMensMorris::SLUG, 'user_id' => $user->id])->sole()->only(['rating', 'results', 'wins']);
    $strangerBefore = $rating($stranger);
    $casualBefore = Rating::query()->where(['pool' => Rating::CASUAL, 'game' => Checkers::SLUG, 'user_id' => $main->id])->value('rating');
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    expect($rating($second)['rating'])->toBe(1020);

    $done = app(AccountLinks::class)->link($admin, $main->pubkey, $second->pubkey, 'Same person');
    $void = FairPlayVoid::query()->where('source_id', $rated->id)->sole();

    expect($done['voided'])->toBe(2)
        ->and([$rated->refresh()->status, $rated->result, $rated->end_reason])->toBe([BoardGameStatus::Aborted, null, BoardEndReason::Voided->value])
        ->and([$casual->refresh()->status, $casual->result])->toBe([BoardGameStatus::Aborted, null])
        ->and($void->only(['source', 'match_number']))->toBe(['source' => 'board', 'match_number' => $rated->number])
        ->and($void->previous)->toBe(['status' => 'finished', 'result' => '1-0', 'end_reason' => 'resignation'])
        ->and($void->elo['applied'])->toBeNull()
        // The second account is back at the start; the main account keeps only its game against the stranger.
        ->and($rating($second))->toBe(['rating' => 1000, 'results' => 0, 'wins' => 0])
        ->and($rating($main)['results'])->toBe(1)
        ->and($rating($stranger))->toBe($strangerBefore)
        ->and($kept->refresh()->result)->toBe('1-0')
        // Casual Elo stays as it is, as for chess.
        ->and(Rating::query()->where(['pool' => Rating::CASUAL, 'game' => Checkers::SLUG, 'user_id' => $main->id])->value('rating'))->toBe($casualBefore)
        ->and(RatingChange::query()->withoutGlobalScope(RatingChange::LIVE)->where('source', RatingChange::BOARD)->where('source_id', $rated->id)->whereNotNull('reverted_at')->count())->toBe(2);

    // A later game between them rates nothing.
    $later = whiteWinsAfterOneMove(app(BoardGameService::class)->start(Checkers::SLUG, $second, $main));
    expect(RatingChange::query()->where('source', RatingChange::BOARD)->where('source_id', $later->id)->count())->toBe(0);
});

/* ---------- Follow-ups of the P6 review --------------------------------------------------------------------- */

test('a rated board win credits the "for the clan" quest of both clan players; a casual one only the plain quest', function () {
    boardMiningSeason();
    [$anna, $bert] = User::factory()->count(2)->create();
    Clan::factory()->create(['owner_id' => $anna->id]);
    Clan::factory()->create(['owner_id' => $bert->id]);

    $rated = whiteWinsAfterOneMove(ratedBoardGame(Checkers::SLUG, $anna, $bert));
    $casual = whiteWinsAfterOneMove(app(BoardGameService::class)->start(NineMensMorris::SLUG, $anna, $bert));
    $quests = fn (BoardGame $game, User $user): array => QuestCredit::query()->where(['source' => 'board:'.$game->id, 'user_id' => $user->id])->orderBy('quest')->pluck('quest')->all();

    expect($quests($rated, $anna))->toContain(Quests::FOR_THE_CLAN, Quests::THREE_GAMES)
        ->and($quests($rated, $bert))->toContain(Quests::FOR_THE_CLAN, Quests::THREE_GAMES)
        ->and($quests($casual, $anna))->toContain(Quests::THREE_GAMES)
        ->and($quests($casual, $anna))->not->toContain(Quests::FOR_THE_CLAN);
});

test('a board game\'s ladder is open at a moment only if the league had published it by then', function () {
    $season = seasonBeforeBoardGames();
    $this->travel(10)->minutes();
    $before = CarbonImmutable::now()->subMinute();
    publishLadders($season);
    $address = Ladders::address(NineMensMorris::SLUG, 'blitz');

    expect($address)->not->toBeNull()
        ->and(Ladders::address(NineMensMorris::SLUG, 'blitz', $before))->toBeNull()
        ->and(Ladders::address(NineMensMorris::SLUG, 'blitz', CarbonImmutable::now()))->toBe($address)
        // Chess blitz is open from Block 0 on, which published it.
        ->and(Ladders::address('chess', 'blitz', $before))->not->toBeNull();
});

test('a lobby in a season live since before the board games joined says rated play starts with the board\'s rule change', function () {
    seasonBeforeBoardGames();
    [$anna] = boardLobbyPlayer('Anna');

    expect(app(RatedBoard::class)->refusal($anna, Checkers::SLUG, 'blitz'))->toBe('Rated Checkers starts when the board adds it to the running season.');

    Livewire::actingAs($anna)->test('pages::board.lobby', ['board' => Checkers::SLUG])
        ->assertSee('data-rated-open="false"', false)
        ->assertSee('Rated Checkers starts when the board adds it to the running season.')
        ->assertDontSee('Casual until Block 0');
});
