<?php

use App\Enums\BoardGameStatus;
use App\Enums\SeriesResolution;
use App\Enums\SeriesStatus;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\NostrEvent;
use App\Models\Rating;
use App\Models\SeasonAttestation;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Board\GoRules;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Engagement\ClanHashrate;
use App\Support\Nostr\SignedEvent;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\Ladders;
use App\Support\Series\SeriesService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\GoOn;
use Tests\Support\TestSigner;
use Tests\Support\TrustedFacts;

/*
 * Go team matches (NIP rev. 9.28; DerCaddy, 2026-11-23: "Go sollte auch als Mannschaftsspiel möglich sein!", all three
 * sizes in rapid): the Blockli team match of tests/Feature/BlockliTeamMatchTest.php, itself the chess one, with a Go
 * rapid lineup per size and Go games on the boards. Black moves first in Go, so the challenger plays Black, the first
 * move, on boards 1 and 3; everything else is the chess team match: lineups, lock by that size's rapid Elo, start and
 * first-move window, board points, the team bonus, attestations on that size's rapid ladder, the pages.
 */

beforeEach(function () {
    Queue::fake();
    GoOn::play();
    $this->teamMatches = app(ChessTeamMatches::class);
});

/**
 * A ready Go rapid lineup of one size: the clan owner as captain (a real key) plus `$players - 1` more active players.
 *
 * @return array{0: Lineup, 1: User, 2: TestSigner}
 */
function goTeamLineup(string $game = 'go-9', int $players = 3): array
{
    $signer = new TestSigner;
    $captain = User::factory()->withPubkey($signer->pubkey)->create();
    $lineup = Lineup::factory()->game($game, 'rapid')->ready($players - 2)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);

    return [$lineup->load('clan', 'seats.user'), $captain, $signer];
}

/**
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $a
 * @param  array{0: Lineup, 1: User, 2: TestSigner}  $b
 */
function goTeamChallengeAndAccept(array $a, array $b, bool $rated = false, int $boards = 2): SeriesMatch
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
function goTeamActiveIds(Lineup $lineup): array
{
    return array_map(fn ($seat) => $seat->user_id, $lineup->fresh()->activeSeats());
}

/**
 * @return array{0: SeriesMatch, 1: array{0: Lineup, 1: User, 2: TestSigner}, 2: array{0: Lineup, 1: User, 2: TestSigner}}
 */
function lockedGoTeamMatch(string $game = 'go-9', bool $rated = false, int $boards = 2): array
{
    $a = goTeamLineup($game);
    $b = goTeamLineup($game);
    $match = goTeamChallengeAndAccept($a, $b, $rated, $boards);
    $teamMatches = app(ChessTeamMatches::class);
    $teamMatches->name($match, $a[1], array_slice(goTeamActiveIds($a[0]), 0, $boards));
    $teamMatches->name($match, $b[1], array_slice(goTeamActiveIds($b[0]), 0, $boards));
    test()->travelTo(ChessTeamMatches::lockAt($match->refresh())->copy()->addSecond());
    expect($teamMatches->lock($match))->toBe('locked');

    return [$match->refresh(), $a, $b];
}

/**
 * At the start: every board started, keyed by board.
 *
 * @return array<int, BoardGame>
 */
function startedGoTeamBoards(SeriesMatch $match): array
{
    test()->travelTo($match->refresh()->start_at->copy()->addSecond());
    app(ChessTeamMatches::class)->startDue();

    return BoardGame::query()->with(['white', 'black'])->where('series_match_id', $match->id)->orderBy('board')->get()->keyBy('board')->all();
}

/** Play these stones in turn, Black (the first side) first; returns the game as it is now. */
function playGoTeamBoard(BoardGame $game, array $moves): BoardGame
{
    $service = app(BoardGameService::class);

    foreach ($moves as $move) {
        $game = $game->refresh()->loadMissing(['white', 'black']);
        $service->move($game, $game->turn === 'w' ? $game->white : $game->black, $move, $game->ply + 1);
    }

    return $game->refresh();
}

/** Two stones each, then `$loser` ('w' for Black, 'b' for White) resigns: a played result. */
function decideGoTeamBoard(BoardGame $game, string $loser): BoardGame
{
    $game = playGoTeamBoard($game, ['d4', 'f6', 'c3', 'e5'])->loadMissing(['white', 'black']);
    app(BoardGameService::class)->resign($game, $loser === 'w' ? $game->white : $game->black);

    return $game->refresh();
}

function goTeamBoardAttestation(BoardGame $game): ?SignedEvent
{
    $row = SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->where('source_id', $game->id)->first();

    return $row === null ? null : SignedEvent::fromInput(NostrEvent::query()->findOrFail($row->nostr_event_id)->payload());
}

test('Go rapid plays team matches over 2 or 3 boards on every size, and the clan page offers a lineup per size', function () {
    $registry = app(GameRegistry::class);
    $owner = User::factory()->create();
    $clan = Clan::factory()->create(['owner_id' => $owner->id]);

    foreach (['go-9', 'go-13', 'go-19'] as $slug) {
        expect($registry->mode($slug, 'rapid')->boards)->toBe([2, 3], $slug)
            ->and($registry->mode($slug, 'rapid')->lineupMinimum())->toBe(2)
            ->and($registry->mode($slug, 'correspondence')->boards)->toBe([])
            ->and($registry->isTeamMatchMode($slug, 'rapid'))->toBeTrue()
            // A team match reports one result per board, as chess and Blockli.
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'team', 'boards' => ['1-0', '0-1', '1-0']]))->toBe([])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'team', 'boards' => ['1-0']]))->toBe(['boards_not_allowed'])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'solo', 'result' => '0-1']))->toBe([])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'team', 'boards' => ['1-0', 'x']]))->toBe(['board_2_result'])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'team', 'boards' => ['1-0', '0-1', '1-0', '0-1']]))->toBe(['boards_not_allowed'])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'team', 'boards' => ['a' => '1-0', 'b' => '0-1']]))->toBe(['boards_not_allowed'])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['result' => '1-0']))->toBe(['format'])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'rapid'), ['format' => 'solo', 'result' => 'x']))->toBe(['result'])
            ->and($registry->get($slug)->validateResult($registry->mode($slug, 'correspondence'), ['result' => '1-0']))->toBe([]);
    }

    expect($registry->mode('go-9', 'blitz')->boards)->toBe([])
        ->and($registry->teamMatchModes())->toMatchArray(['chess' => ['rapid'], 'go-9' => ['rapid'], 'go-13' => ['rapid'], 'go-19' => ['rapid']]);

    // The clan page lists a Go rapid lineup per size, and opens each builder; blitz has none.
    $page = $this->actingAs($owner)->get(route('clans.manage', $clan))->assertOk();
    foreach (['go-9', 'go-13', 'go-19'] as $slug) {
        $page->assertSee('data-test="edit-lineup-'.$slug.'-rapid"', false);
    }
    $page->assertDontSee('data-test="edit-lineup-go-9-blitz"', false);

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->call('editLineup', 'rapid', 'go-19')->assertHasNoErrors()
        ->assertSet('editingGame', 'go-19')->assertSet('editing', 'rapid')
        ->call('editLineup', 'blitz', 'go-9')->assertForbidden();
});

test('a Go team match is challenged on its size\'s rapid ladder, and the lineups lock by that size\'s rapid Elo', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    $a = goTeamLineup('go-13');
    $b = goTeamLineup('go-13');
    $match = goTeamChallengeAndAccept($a, $b, rated: true, boards: 3);

    expect($match->status)->toBe(SeriesStatus::Accepted)
        ->and($match->game)->toBe('go-13')
        ->and($match->mode)->toBe('rapid')
        ->and($match->boards)->toBe(3)
        ->and($match->playsBoardGames())->toBeTrue()
        ->and($match->ladder_address)->toBe(Ladders::address('go-13', 'rapid'))
        ->and($match->challengeEvent->payload()['tags'])->toContain(['boards', '3']);

    // The weakest 13 x 13 player of side A is the best on 9 x 9: the board order reads Go 13 x 13 rapid only.
    [$first, $second, $third] = goTeamActiveIds($a[0]);
    foreach ([[$first, 1400], [$second, 1600], [$third, 1500]] as [$userId, $rating]) {
        Rating::query()->create(['game' => 'go-13', 'mode' => 'rapid', 'pool' => Rating::CASUAL, 'season' => '', 'subject' => 'user:'.$userId, 'user_id' => $userId, 'rating' => $rating, 'results' => 3]);
    }
    Rating::query()->create(['game' => 'go-9', 'mode' => 'rapid', 'pool' => Rating::CASUAL, 'season' => '', 'subject' => 'user:'.$first, 'user_id' => $first, 'rating' => 2400, 'results' => 30]);

    $this->teamMatches->name($match, $a[1], [$first, $second, $third]);
    $this->teamMatches->name($match, $b[1], goTeamActiveIds($b[0]));
    $this->travelTo(ChessTeamMatches::lockAt($match)->copy()->addSecond());

    expect($this->teamMatches->lock($match))->toBe('locked')
        ->and(SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', 'challenger')->orderBy('board')->pluck('user_id')->all())->toBe([$second, $third, $first])
        ->and($this->teamMatches->standing(User::query()->findOrFail($first), 'go-13'))->toBe(['rating' => 1400, 'pool' => 'casual', 'results' => 3]);
});

test('each board starts as a Go game of its size with its rapid clock, the challenger laying the first stone, Black, on odd boards', function () {
    [$match] = lockedGoTeamMatch('go-19', boards: 2);
    $games = startedGoTeamBoards($match);
    $player = fn (string $side, int $board) => SeriesMatchBoard::query()->where('series_match_id', $match->id)->where('side', $side)->where('board', $board)->value('user_id');

    expect($games)->toHaveCount(2)
        ->and(collect($games)->pluck('game')->unique()->all())->toBe(['go-19'])
        ->and(collect($games)->pluck('mode')->unique()->all())->toBe(['rapid'])
        ->and(collect($games)->pluck('status')->unique()->all())->toBe([BoardGameStatus::Active])
        ->and($games[1]->position)->toBe((new GoRules(19))->serialize((new GoRules(19))->start()))
        // The first side (`w`) lays the black stones: the challenger on board 1, the challenged on board 2.
        ->and($games[1]->white_id)->toBe($player('challenger', 1))
        ->and($games[2]->white_id)->toBe($player('challenged', 2))
        ->and($games[1]->turn)->toBe('w')
        // Rapid 15+10 on 19 x 19, and 600 s for the first move from the agreed start, as every team board.
        ->and($games[1]->initial_ms)->toBe(900_000)
        ->and($games[1]->increment_ms)->toBe(10_000)
        ->and($games[1]->first_move_seconds)->toBe(600)
        ->and($games[1]->number())->toBe('#'.$match->number.'/1');

    // The challenger's first stone on board 1 is a black one.
    $game = playGoTeamBoard($games[1], ['q16']);
    $rules = new GoRules(19);
    expect($game->position)->toBe($rules->serialize($rules->apply($rules->start(), 'q16')))
        ->and($rules->view($rules->deserialize($game->position))['pieces'])->toBe(['q16' => ['side' => 'w', 'kind' => 'black']]);
});

test('a rated 2:1 Go team win adds the team bonus once, and every board is attested on that size\'s rapid ladder', function () {
    openSeason(['slug' => 'season-1']);
    app()->bind(TrustFacts::class, TrustedFacts::class);
    [$match, [$lineupA], [$lineupB]] = lockedGoTeamMatch('go-9', rated: true, boards: 3);
    $games = startedGoTeamBoards($match);

    // Boards 1 and 3: the challenger plays Black (`w`); board 2: White (`b`).
    decideGoTeamBoard($games[1], 'b');
    decideGoTeamBoard($games[2], 'w');
    decideGoTeamBoard($games[3], 'w');
    $match->refresh();
    $clanA = $lineupA->clan->address();
    $hashrate = app(ClanHashrate::class)->breakdown('season-1');

    expect($match->winner)->toBe('challenger')
        ->and($match->resolution)->toBe(SeriesResolution::Admin)
        ->and(ChessTeamMatches::score(ChessTeamMatches::boardResults($match)))->toBe(['challenger' => 4, 'challenged' => 2])
        ->and($hashrate[$clanA]['teamWins'])->toBe(1)
        ->and($hashrate[$clanA]['bonus'])->toBe(5)
        ->and($hashrate[$lineupB->clan->address()]['bonus'] ?? 0)->toBe(0);

    $event = goTeamBoardAttestation($games[2]->refresh());

    expect($event)->not->toBeNull()
        ->and($event->tags[2])->toBe(['a', Ladders::address('go-9', 'rapid'), ''])
        ->and($match->challenger_lineup_address)->toContain('/go-9/rapid')
        // White won board 2, and White is the challenger side there.
        ->and($event->tag('winner'))->toBe('challenger')
        ->and($event->tagsNamed('elo'))->toHaveCount(2)
        ->and($event->tag('match'))->toBe((string) $match->number)
        ->and(Rating::query()->where(['game' => 'go-9', 'mode' => 'rapid', 'pool' => Rating::RATED])->count())->toBe(6)
        ->and(Rating::query()->whereIn('game', ['chess', 'go-13', 'go-19'])->count())->toBe(0)
        ->and(SeasonAttestation::query()->where('source', SeasonAttestation::BOARD)->count())->toBe(3);
});

test('the team match page shows the Go boards with their clocks, the lobby offers the team match, and the rules name Go', function () {
    [$match] = lockedGoTeamMatch('go-9', boards: 2);
    $games = startedGoTeamBoards($match);
    playGoTeamBoard($games[1], ['e5']);

    $this->get(route('matches.show', $match->number))->assertOk()
        ->assertSee('data-test="team-match-boards"', false)
        ->assertSee('data-kind="board"', false)
        ->assertSee(route('board.show', $games[1]), false)
        ->assertSee('data-test="team-board-clock-1-w"', false)
        ->assertSee('Go 9×9 team match over 2 boards');

    $this->actingAs($games[2]->black)->get(route('board.show', $games[2]))->assertOk()
        ->assertSee('data-test="team-board-banner"', false)
        ->assertDontSee('data-test="abort"', false);

    foreach (['go-9', 'go-13', 'go-19'] as $slug) {
        $this->get(route('board.lobby', $slug))->assertOk()->assertSee('data-test="play-team"', false)->assertSee('data-test="play-team-link"', false);
    }

    $this->get(route('rules'))->assertOk()
        ->assertSee(__('Two clans play chess or Go on 2 or 3 boards at the same time, all in :mode. The board points decide the team match.', ['mode' => 'Rapid']), false)
        ->assertSee('Go team matches follow the same rules with one rapid lineup per board size', false);

    $this->withSession(['locale' => 'de'])->get(route('rules'))->assertOk()
        ->assertSee('Go-Mannschaftskämpfe folgen denselben Regeln');
});

test('the public clan page offers one Go team challenge per size beside chess', function () {
    [$go9] = goTeamLineup('go-9');
    $go19 = Lineup::factory()->game('go-19', 'rapid')->ready(1)->create(['clan_id' => $go9->clan_id]);
    $chess = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => $go9->clan_id]);

    $html = $this->actingAs(User::factory()->create())->get(route('clans.show', $go9->clan))->assertOk()
        ->assertSee('Challenge to a Go 9×9 team match')
        ->assertSee('Challenge to a Go 19×19 team match')
        ->assertSeeInOrder([e(route('challenges.create', ['to' => $chess->id])), e(route('challenges.create', ['to' => $go9->id])), e(route('challenges.create', ['to' => $go19->id]))], false)
        ->getContent();

    expect(substr_count($html, 'data-test="challenge-team-match"'))->toBe(3)
        // Go 19 x 19 rapid runs 15+10: the heading names no clock then.
        ->and($html)->toContain('Rapid over 2 or 3 boards')->not->toContain('Rapid 10+5 over 2 or 3 boards');

    // Lineups that all play 10+5 keep the clock in the heading.
    $go19->delete();
    $this->get(route('clans.show', $go9->clan))->assertOk()->assertSee('Rapid 10+5 over 2 or 3 boards');
});

test('Go 19 x 19 names its own 15+10 clock in the challenge form, the board start notice and the rules, never 10+5', function () {
    [$lineup, $captain] = goTeamLineup('go-19');
    [$other] = goTeamLineup('go-19');

    Livewire::actingAs($captain)->test('pages::challenges.create', ['lineupId' => $lineup->id])
        ->call('next')->call('pickOpponent', $other->id)->call('next')->assertSet('step', 3)
        ->assertSee(__(':n players per clan play :mode at the same time, the boards ordered by rapid Elo', ['n' => 2, 'mode' => 'Rapid 15+10']))
        ->assertDontSee('10+5');

    [$match] = lockedGoTeamMatch('go-19', boards: 2);
    $games = startedGoTeamBoards($match);
    $notice = $games[1]->white->notifications()->get()->first(fn ($row) => $row->data['title'] === __('Your board :board started', ['board' => 1]));

    expect($notice)->not->toBeNull()
        ->and($notice->data['body'])->toContain('Rapid 15+10')->not->toContain('10+5');

    // The lead names no single clock while Go plays team matches; the Go sentence names each size's.
    $this->get(route('rules'))->assertOk()
        ->assertSee('Go 9×9 10+5, Go 13×13 10+5, Go 19×19 15+10', false)
        ->assertDontSee('all in Rapid 10+5', false);
});

test('a Go team board names Black for the side that moves first: the challenger on board 1, on the match page and in the lineup', function () {
    [$match, $a, $b] = lockedGoTeamMatch('go-9', boards: 2);
    $challenger = $match->sideName('challenger');

    // Before the start every board waits for Black's first move.
    $this->travelTo($match->start_at->copy()->addSecond());
    app(ChessTeamMatches::class)->startDue();
    $this->get(route('matches.show', $match->number))->assertOk()
        ->assertSee(__('Black').' · '.$challenger)
        ->assertSee(__('Black to make the first move'))
        ->assertDontSee(__('White to make the first move'));

    // The lineup in the match room: the challenger's board 1 player plays Black.
    Livewire::actingAs($a[1])->test('team-lineup', ['match' => $match->refresh()])
        ->assertSeeHtmlInOrder(['data-test="team-lineup-side-challenger"', '· '.__('Black').'</span>', '· '.__('White').'</span>', 'data-test="team-lineup-side-challenged"', '· '.__('White').'</span>', '· '.__('Black').'</span>']);
});

test('with only Go 9 x 9 switched on, the rules name its clock alone and a leftover 19 x 19 lineup gets no team challenge', function () {
    [$go9] = goTeamLineup('go-9');
    $go19 = Lineup::factory()->game('go-19', 'rapid')->ready(1)->create(['clan_id' => $go9->clan_id]);
    config(['esports.board_games.games.go-13.enabled' => false, 'esports.board_games.games.go-19.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);

    $this->get(route('rules'))->assertOk()
        ->assertSee('(Go 9×9 10+5)', false)
        ->assertDontSee('Go 19×19 15+10', false);

    $this->actingAs(User::factory()->create())->get(route('clans.show', $go9->clan))->assertOk()
        ->assertSee(e(route('challenges.create', ['to' => $go9->id])), false)
        ->assertDontSee(e(route('challenges.create', ['to' => $go19->id])), false);
});

test('the clan page\'s next team matches name the Go size, so two Go team matches can be told apart', function () {
    [$nine] = lockedGoTeamMatch('go-9', boards: 3);
    $clan = $nine->challengerLineup->clan;

    $this->get(route('clans.show', $clan))->assertOk()
        ->assertSeeInOrder(['data-test="team-match-row"', $nine->label(), 'data-test="team-match-game"', 'Go 9×9', 'Go 9×9 · 3 boards'], false);
});

/** The SVG of a team match tile's Go board, cut from the page by its test hook. */
function goTileBoard(string $html, int $board): string
{
    $at = strpos($html, 'data-test="team-board-stones-'.$board.'"');
    expect($at)->not->toBeFalse("no Go board in tile {$board}");
    $start = strrpos(substr($html, 0, (int) $at), '<svg');

    return substr($html, (int) $start, strpos($html, '</svg>', (int) $at) + 6 - (int) $start);
}

test('the clan page lines up its team challenges in one labelled row: one field per lineup, named by its game, each to its challenge form', function () {
    [$go9] = goTeamLineup('go-9');
    $chess = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => $go9->clan_id]);
    $go13 = Lineup::factory()->game('go-13', 'rapid')->ready(1)->create(['clan_id' => $go9->clan_id]);
    $go19 = Lineup::factory()->game('go-19', 'rapid')->ready(1)->create(['clan_id' => $go9->clan_id]);

    $html = $this->actingAs(User::factory()->create())->get(route('clans.show', $go9->clan))->assertOk()
        ->assertSeeHtml('data-test="team-challenges-label">Challenge:</span>')
        ->assertSeeInOrder(['data-test="team-challenges"', 'Challenge:',
            e(route('challenges.create', ['to' => $chess->id])), '<span class="truncate">Chess</span>',
            e(route('challenges.create', ['to' => $go9->id])), '<span class="truncate">Go 9×9</span>',
            e(route('challenges.create', ['to' => $go13->id])), '<span class="truncate">Go 13×13</span>',
            e(route('challenges.create', ['to' => $go19->id])), '<span class="truncate">Go 19×19</span>'], false)
        ->getContent();

    // Four equal fields in one row: the grid's column count is the number of fields; none spans two on a phone.
    expect(substr_count($html, 'data-test="challenge-team-match"'))->toBe(4)
        ->and($html)->toContain('style="--fields: 4"')
        ->and($html)->not->toContain('max-sm:col-span-2');

    // An odd count: the last field spans both phone columns, so no field stands alone in half a row.
    $go19->delete();
    $html = $this->get(route('clans.show', $go9->clan))->assertOk()->getContent();
    $last = strpos($html, e(route('challenges.create', ['to' => $go13->id])));
    expect(substr_count($html, 'data-test="challenge-team-match"'))->toBe(3)
        ->and($html)->toContain('style="--fields: 3"')
        ->and(substr_count($html, 'max-sm:col-span-2'))->toBe(1)
        ->and(substr($html, (int) $last, 400))->toContain('max-sm:col-span-2');

    $this->withSession(['locale' => 'de'])->get(route('clans.show', $go9->clan))->assertOk()
        ->assertSeeHtml('data-test="team-challenges-label">Herausfordern:</span>')
        ->assertSeeHtml('<span class="truncate">Schach</span>');
});

test('a Go team tile draws its board with the stones of its game on the grid of its size, and redraws after a move', function (string $size, int $lines) {
    [$match] = lockedGoTeamMatch($size, boards: 2);

    // Before the start: the empty board of its size, never a chess board.
    $html = $this->get(route('matches.show', $match->number))->assertOk()->getContent();
    $empty = goTileBoard($html, 1);
    expect($html)->not->toContain('data-test="team-board-fen-')
        ->and($empty)->toContain('data-lines="'.$lines.'"', 'data-stones="0"', 'role="img"', 'aria-label="Board 1 of '.$match->label().'"')
        ->and(substr_count($empty, '<line '))->toBe(2 * $lines)
        ->and(substr_count($empty, 'data-stone='))->toBe(0);

    $games = startedGoTeamBoards($match);
    playGoTeamBoard($games[1], ['e5', 'd4', 'c3']);

    $html = $this->get(route('matches.show', $match->number))->assertOk()->getContent();
    $one = goTileBoard($html, 1);
    expect($one)->toContain('data-lines="'.$lines.'"', 'data-stones="3"', 'id="go-tile-1-stone-black"', 'url(#go-tile-1-stone-black)')
        ->and(substr_count($one, '<line '))->toBe(2 * $lines)
        ->and(substr_count($one, 'data-stone="black"'))->toBe(2)
        ->and(substr_count($one, 'data-stone="white"'))->toBe(1)
        // The stone set last, c3 (Black), carries the ring.
        ->and($one)->toContain('data-last="black"')
        ->and(goTileBoard($html, 2))->toContain('data-stones="0"', 'id="go-tile-2-wood"')->not->toContain('data-last=')
        ->and($html)->toContain('data-test="team-board-move-1"');

    // The tile re-renders after a move (teamMatchBoards.js asks for $refresh): the new stone is drawn.
    $component = Livewire::test('team-match-boards', ['match' => $match->refresh()]);
    playGoTeamBoard($games[1], ['f6']);
    expect(goTileBoard($component->call('$refresh')->assertOk()->html(), 1))->toContain('data-stones="4"', 'data-last="white"');
})->with([
    'Go 9×9' => ['go-9', 9],
    'Go 13×13' => ['go-13', 13],
    'Go 19×19' => ['go-19', 19],
]);
