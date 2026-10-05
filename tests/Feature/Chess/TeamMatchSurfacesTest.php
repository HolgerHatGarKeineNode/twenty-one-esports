<?php

use App\Enums\ClanRole;
use App\Enums\NotificationKind;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\SeriesMatchBoard;
use App\Models\User;
use App\Support\Cards\PageCardFacts;
use App\Support\Cards\SharePosts;
use App\Support\Chess\ChessGameService;
use App\Support\Chess\ChessTeamMatches;
use App\Support\Clans\ClanStats;
use App\Support\Dock\OpenMatches;
use App\Support\Pages\RulesPage;
use App\Support\Players\RecentResults;
use App\Support\Series\ChallengeDraft;
use App\Support\Series\SeriesService;
use App\Support\StreamBot\StreamBotBuilders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * Plan "Schach Rapid und Clan", P6: a chess team match shows on every surface
 * a clan match belongs on: the clan page (rapid lineup, next and past team
 * matches), the /matches "Team" filter, home, the profile, the dock from the
 * lock, the notifications (lock with the board order, lock missed, board
 * start, result), the share post and page card, the rules, and a board's
 * game page (no abort, no rematch, the way back).
 */

beforeEach(function () {
    Queue::fake();
});

/**
 * Two ready chess rapid lineups, a friendly 2-board team match between them,
 * accepted, both sides named, locked; with `$start` the boards started too.
 *
 * @return array{match: SeriesMatch, a: Lineup, b: Lineup, captainA: User, captainB: User}
 */
function surfaceTeamMatch(bool $lock = true, bool $start = false, bool $nameB = true): array
{
    $sides = [];

    foreach (['a', 'b'] as $key) {
        $signer = new TestSigner;
        $captain = User::factory()->withPubkey($signer->pubkey)->create();
        $lineup = Lineup::factory()->game('chess', 'rapid')->ready(1)->create(['clan_id' => Clan::factory()->create(['owner_id' => $captain->id])->id]);
        $sides[$key] = [$lineup->load('clan', 'seats.user'), $captain, $signer];
    }

    $service = app(SeriesService::class);
    $at = now()->addHours(2)->startOfMinute()->getTimestamp();
    $draft = new ChallengeDraft($sides['a'][0]->id, $sides['b'][0]->id, 1, false, [$at, $at + 3600], $at - 3600, 'gl hf', 2);
    $match = $service->challenge($sides['a'][1], $draft, $sides['a'][2]->signTemplates($service->prepareChallenge($sides['a'][1], $draft)['templates']));
    $service->answer($match, $sides['b'][1], 'accepted', $match->proposals[0], $sides['b'][2]->signTemplates($service->prepareAnswer($match, $sides['b'][1], 'accepted', $match->proposals[0])));
    $match->refresh();

    $teamMatches = app(ChessTeamMatches::class);
    $active = fn (Lineup $lineup): array => array_slice(array_map(fn ($seat) => $seat->user_id, $lineup->fresh()->activeSeats()), 0, 2);
    $teamMatches->name($match, $sides['a'][1], $active($sides['a'][0]));

    if ($nameB) {
        $teamMatches->name($match, $sides['b'][1], $active($sides['b'][0]));
    }

    if ($lock) {
        test()->travelTo(ChessTeamMatches::lockAt($match->refresh())->copy()->addSecond());
        $teamMatches->lock($match);
    }

    if ($start) {
        test()->travelTo($match->refresh()->start_at->copy()->addSecond());
        $teamMatches->startDue();
    }

    return ['match' => $match->refresh(), 'a' => $sides['a'][0], 'b' => $sides['b'][0], 'captainA' => $sides['a'][1], 'captainB' => $sides['b'][1]];
}

/** The two started boards of a team match, by board. @return array<int, ChessGame> */
function surfaceBoards(SeriesMatch $match): array
{
    return ChessGame::query()->with(['white', 'black'])->where('series_match_id', $match->id)->orderBy('board')->get()->keyBy('board')->all();
}

/** White wins the board: two moves, then Black resigns. */
function surfaceWhiteWins(ChessGame $game): void
{
    $service = app(ChessGameService::class);
    $service->move($game->refresh()->load(['white', 'black']), $game->white, 'e2e4', 1);
    $service->move($game->refresh()->load(['white', 'black']), $game->black, 'e7e5', 2);
    $service->resign($game->refresh()->load(['white', 'black']), $game->black);
}

/** A named player of a side, by board. */
function surfaceSeat(SeriesMatch $match, string $side, int $board): User
{
    return SeriesMatchBoard::query()->with('user')->where(['series_match_id' => $match->id, 'side' => $side, 'board' => $board])->sole()->user;
}

test('the clan page shows the public rapid lineup, the next team match and a team challenge for outsiders, then the result with its board points', function () {
    ['match' => $match, 'a' => $a, 'b' => $b] = surfaceTeamMatch(start: true);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('clans.show', $a->clan))->assertOk()
        ->assertSeeHtml('data-test="clan-team-matches"')
        ->assertSeeHtml('data-test="rapid-lineup"')
        ->assertSee($a->seats->first()->user->name)
        ->assertSeeInOrder(['data-test="team-match-row"', $match->label(), $b->clan->name, 'rapid · 2 boards'], false)
        ->assertSeeHtml('href="'.route('challenges.create', ['to' => $a->id]).'" class="inline-flex h-11 items-center rounded-md border border-line px-4');

    // A member sees no challenge against the own clan.
    $this->actingAs($a->clan->owner)->get(route('clans.show', $a->clan))->assertOk()->assertDontSeeHtml('data-test="challenge-team-match"');

    $boards = surfaceBoards($match);
    surfaceWhiteWins($boards[1]);
    surfaceWhiteWins($boards[2]);
    $match->refresh();

    // Board 1: the challenger has White and wins; board 2: the challenged clan has White and wins. 1 : 1, a team draw.
    expect($match->winner)->toBe('none');
    $this->get(route('clans.show', $a->clan))->assertOk()
        ->assertSee(__('No team match scheduled.'))
        ->assertSeeInOrder(['id="mt-h"', $match->label(), $b->clan->name, '1 : 1', 'rapid · 2 boards', 'draw'], false);

    Livewire::test('pages::clans.show', ['clan' => $a->clan])->assertOk()->call('$refresh')->assertOk();
});

test('the Clan Rating reads rapid: the clan pages say rapid Elos, never blitz', function () {
    $clan = Clan::factory()->create();

    $this->get(route('clans.index'))->assertOk()->assertSee(__('Clan Rating: the average of the 3 best solo rapid Elos'))->assertDontSee('blitz Elos');
    expect(ClanStats::RATING_MODE)->toBe('rapid');
    $this->actingAs($clan->owner)->get(route('clans.show', $clan))->assertOk()->assertDontSee('blitz Elo');
});

test('the Team filter on /matches lists the team matches only, and the table names their boards', function () {
    ['match' => $match] = surfaceTeamMatch();
    $solo = ChessGame::factory()->finished('1-0')->create();

    Livewire::withQueryParams(['game' => 'team'])->test('pages::matches.index')->assertOk()
        ->assertSeeHtml('data-test="game-team"')
        ->assertSee($match->label())
        ->assertSee('rapid · 2 boards')
        ->assertSeeHtml('data-test="match-row"')
        // The solo game stays in the strip above, never in the table.
        ->assertDontSeeHtml('data-test="chess-row"')
        ->call('$refresh')->assertOk();

    // Picked from "All": the solo game leaves the table, the team match stays.
    Livewire::withQueryParams([])->test('pages::matches.index')->assertSeeHtml('data-test="chess-row"')
        ->call('pickGame', 'team')->assertSet('game', 'team')->assertSee($match->label())->assertDontSeeHtml('data-test="chess-row"');
});

test('home shows a live team match with its score, and its result among the latest results', function () {
    ['match' => $match] = surfaceTeamMatch(start: true);

    $this->get(route('home'))->assertOk()
        ->assertSeeHtml('data-test="live-team-match"')
        ->assertSeeInOrder([$match->sideName('challenger'), '0 : 0', $match->sideName('challenged')]);

    $boards = surfaceBoards($match);
    surfaceWhiteWins($boards[1]);
    surfaceWhiteWins($boards[2]);

    $this->get(route('home'))->assertOk()
        ->assertDontSeeHtml('data-test="live-team-match"')
        ->assertSee(__(':a drew with :b', ['a' => $match->sideName('challenger'), 'b' => $match->sideName('challenged')]));
});

test('the profile names a board game as a board of its team match, and the team result with its board points', function () {
    ['match' => $match] = surfaceTeamMatch(start: true);
    $boards = surfaceBoards($match);
    surfaceWhiteWins($boards[1]);
    surfaceWhiteWins($boards[2]);
    $player = surfaceSeat($match, 'challenger', 1);

    $results = collect((new RecentResults($player))->latest());

    expect($results->firstWhere('key', 'chess-'.$boards[1]->id)['game'])->toStartWith('Board 1 · team match #'.$match->number)
        ->and($results->firstWhere('key', 'series-'.$match->id))->toMatchArray(['outcome' => 'draw', 'score' => '1 : 1']);
});

test('the dock shows a team match to the captains before the lock, and from the lock to the named players with their board', function () {
    ['match' => $match, 'a' => $a, 'captainA' => $captain] = surfaceTeamMatch(lock: false);
    $unnamed = User::factory()->create();
    $a->seats()->create(['user_id' => $unnamed->id, 'role' => 'player', 'accepted_at' => now()]);
    ClanMember::query()->create(['clan_id' => $a->clan_id, 'user_id' => $unnamed->id, 'role' => ClanRole::Member, 'joined_at' => now()]);
    $named = SeriesMatchBoard::query()->where(['series_match_id' => $match->id, 'side' => 'challenger'])->where('user_id', '!=', $captain->id)->with('user')->first()->user;
    $tab = fn (User $user) => app(OpenMatches::class)->for($user->fresh())->first(fn ($item) => $item->key === 'series-'.$match->number);

    expect($tab($captain)?->line)->toContain(__('Team match :number, lineup by :time', ['number' => $match->label(), 'time' => '']))
        ->and($tab($named))->toBeNull()
        ->and($tab($unnamed))->toBeNull();

    $this->travelTo(ChessTeamMatches::lockAt($match)->copy()->addSecond());
    app(ChessTeamMatches::class)->lock($match);
    $board = (int) SeriesMatchBoard::query()->where(['series_match_id' => $match->id, 'user_id' => $named->id])->value('board');

    expect($tab($named)?->title)->toBe(__('Team match'))
        ->and($tab($named)?->line)->toContain(__('board :n', ['n' => $board]))
        ->and($tab($unnamed))->toBeNull();
});

test('the lock tells the named players their board and the board order, the start their board, the end the result', function () {
    ['match' => $match] = surfaceTeamMatch();
    $one = surfaceSeat($match, 'challenger', 1);
    $two = surfaceSeat($match, 'challenged', 1);
    $title = fn (User $user, string $text): bool => $user->notifications()->get()->contains(fn ($row) => $row->data['title'] === $text);
    $locked = $one->notifications()->get()->first(fn ($row) => $row->data['title'] === __('Team match :number: lineups locked', ['number' => $match->label()]));

    expect($locked)->not->toBeNull()
        ->and($locked->data['body'])->toContain('board 1')->toContain($two->displayName())->toContain('1 '.$one->displayName().' – '.$two->displayName())
        ->and($locked->data['kind'] ?? NotificationKind::TeamMatch->value)->toBe(NotificationKind::TeamMatch->value);

    $this->travelTo($match->start_at->copy()->addSecond());
    app(ChessTeamMatches::class)->startDue();
    expect($title($one, __('Your board :board started', ['board' => 1])))->toBeTrue();

    $boards = surfaceBoards($match);
    surfaceWhiteWins($boards[1]);
    surfaceWhiteWins($boards[2]);
    expect($title($one, __('Team match :number ended', ['number' => $match->label()])))->toBeTrue();
});

test('a missed lineup lock tells both sides that the team match is lost by forfeit', function () {
    ['match' => $match, 'captainA' => $winner, 'captainB' => $loser] = surfaceTeamMatch(nameB: false);

    expect($match->winner)->toBe('challenger')
        ->and($loser->notifications()->get()->pluck('data.body')->implode(' '))->toContain(__('Your clan named no players by the lineup lock, :minutes minutes before the start. The team match is lost by forfeit.', ['minutes' => 30]))
        ->and($winner->notifications()->get()->pluck('data.title')->all())->toContain(__('Team match :number: lineup lock missed', ['number' => $match->label()]));
});

test('a won team match is a share moment with its board points, and its page card carries boards and score', function () {
    ['match' => $match] = surfaceTeamMatch(start: true);
    $boards = surfaceBoards($match);
    surfaceWhiteWins($boards[1]);
    // Board 2: the challenged clan has White; Black (the challenger) wins by White's resignation.
    $service = app(ChessGameService::class);
    $game = $boards[2]->refresh()->load(['white', 'black']);
    $service->move($game, $game->white, 'e2e4', 1);
    $service->move($game->refresh()->load(['white', 'black']), $game->black, 'e7e5', 2);
    $service->resign($game->refresh()->load(['white', 'black']), $game->white);
    $match->refresh();
    $winner = surfaceSeat($match, 'challenger', 1);

    expect($match->winner)->toBe('challenger')
        ->and(PageCardFacts::series($match))->toMatchArray(['boards' => 2, 'score' => ['2', '0'], 'winner' => 'challenger'])
        ->and(app(SharePosts::class)->post($winner, 'series', (string) $match->number)?->sentence)
        ->toBe(__('Won the chess team match :score against :side on TWENTY ONE Esports.', ['score' => '2–0', 'side' => $match->sideName('challenged')]));
});

test('the rules have the clan team match section with lock, board order, first move, forfeit, draw and pair limit', function () {
    $section = collect(RulesPage::sections())->firstWhere('id', 'clan-team-match');

    expect($section)->not->toBeNull()
        ->and(collect($section['facts'])->pluck(1)->all())->toBe(['2 / 3', '30 minutes before the start', '10 minutes', '1 per 7 days'])
        ->and(implode(' ', $section['items']))->toContain('rapid only')->toContain('loses the whole team match by forfeit')
        ->toContain('by rapid Elo')->toContain('at least 1 minute')->toContain('void and counts for nobody')->toContain('team draw')->toContain('without a bonus')->toContain('friendly is unrated');

    $this->get(route('rules'))->assertOk()->assertSeeHtml('id="clan-team-match"');
});

test('a team board has no abort and no rematch, is titled by its board and leads back to the team match', function () {
    ['match' => $match] = surfaceTeamMatch(start: true);
    $boards = surfaceBoards($match);
    $player = $boards[1]->white;

    $this->actingAs($player)->get(route('games.show', $boards[1]))->assertOk()
        ->assertSeeHtml('data-test="team-board-banner"')
        ->assertSeeHtml('href="'.route('matches.show', $match->number).'"')
        ->assertSee(__('Board :n', ['n' => 1]))
        ->assertDontSeeHtml('data-test="abort"')
        ->assertDontSeeHtml('data-test="rematch"')
        ->assertDontSeeHtml('data-test="find-next"');

    surfaceWhiteWins($boards[1]);
    $this->actingAs($player)->get(route('games.show', $boards[1]))->assertOk()
        ->assertSeeHtml('data-test="team-board-banner"')
        ->assertDontSeeHtml('data-test="challenge-again"')
        ->assertDontSeeHtml('data-test="play-again"');

    Livewire::actingAs($player)->test('pages::games.show', ['game' => $boards[1]])->assertOk()->call('$refresh')->assertOk();
});

test('the stream bot posts a live team match with its boards, never as a best-of series', function () {
    ['match' => $match] = surfaceTeamMatch(start: true);

    $messages = app(StreamBotBuilders::class)->build('live_series', CarbonImmutable::now());

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->content)->toContain($match->challenger_name.' vs '.$match->challenged_name)->toContain('2 boards')->not->toContain('best of');
});

test('the online list carries the rapid Elo, labelled', function () {
    $user = User::factory()->create();
    Rating::query()->create(['pool' => Rating::CASUAL, 'game' => 'chess', 'mode' => 'rapid', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1612, 'results' => 12]);
    Rating::query()->create(['pool' => Rating::CASUAL, 'game' => 'chess', 'mode' => 'blitz', 'subject' => 'user:'.$user->id, 'user_id' => $user->id, 'rating' => 1388, 'results' => 12]);

    $member = Broadcast::driver()->getChannels()['online']($user);

    expect($member['elo'])->toBe(1612)->and($member['eloMode'])->toBe('rapid');
});
