<?php

/*
| Board games next to chess (plan "Mühle und Dame", P1): every game names its
| kind, board games come in only through `esports.board_games` (off by
| default), and every switch between chess and the series treats a board
| game as neither: it leaves it out until P5 (play, ladders, cups) or P6
| (mining) opens the feature. FixtureBoardGame stands in for the real games.
*/

use App\Games\BoardGame;
use App\Games\Chess;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\PageCard;
use App\Support\Cards\PageCardFacts;
use App\Support\GameNames;
use App\Support\Navigation\ShellNavigation;
use App\Support\Nostr\NostrKeys;
use App\Support\SeasonChain\ChainDraft;
use App\Support\SeasonChain\LadderEvents;
use App\Support\SeasonChain\LeagueKey;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonReleaseRefused;
use App\Support\Series\Ladders;
use App\Support\StreamBot\StreamBotBuilders;
use App\Support\Tournaments\CasualCups;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentGames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\FixtureBoardGame;
use Tests\Support\TestSigner;

const FIXTURE_BOARD = FixtureBoardGame::SLUG;

test('every registered game names its kind, and no board game is registered while the switch is off', function () {
    $registry = app(GameRegistry::class);

    expect(array_map(fn ($game): GameKind => $game->kind(), $registry->all()))->toBe([
        'chess' => GameKind::Chess,
        'rocket-league' => GameKind::Series,
        'ea-sports-fc-27' => GameKind::Series,
        'ea-sports-fc-26' => GameKind::Series,
    ])
        ->and(config('esports.board_games.enabled'))->toBeFalse()
        ->and($registry->boards())->toBe([])
        ->and(array_filter(array_keys($registry->all()), $registry->isBoard(...)))->toBe([])
        // Every series game is of kind Series, and nothing else is.
        ->and(array_keys($registry->series()))->toBe(array_keys(array_filter($registry->all(), fn ($game): bool => $game->kind() === GameKind::Series)));
});

test('the board game slugs are reserved: nine men\'s morris is never "mill"', function () {
    expect(array_keys(config('esports.board_games.games')))->toBe(BoardGame::RESERVED_SLUGS)
        ->and(BoardGame::RESERVED_SLUGS)->toBe(['nine-mens-morris', 'checkers'])
        ->and(BoardGame::RESERVED_SLUGS)->not->toContain('mill')
        ->and(app(GameRegistry::class)->find('mill'))->toBeNull();
});

test('a board game is registered only with the switch and its own entry on, and a wrong entry stays off without touching chess', function () {
    $rebuild = function (array $boardGames): GameRegistry {
        config(['esports.board_games' => $boardGames]);
        app()->forgetInstance(GameRegistry::class);

        return app(GameRegistry::class);
    };
    $entry = ['enabled' => true, 'class' => FixtureBoardGame::class];

    $on = $rebuild(['enabled' => true, 'games' => [FIXTURE_BOARD => $entry]]);

    expect(array_keys($on->all()))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', FIXTURE_BOARD])
        ->and(array_keys($on->boards()))->toBe([FIXTURE_BOARD])
        ->and($on->isBoard(FIXTURE_BOARD))->toBeTrue()
        ->and($on->isSeries(FIXTURE_BOARD))->toBeFalse()
        ->and($on->series())->not->toHaveKey(FIXTURE_BOARD)
        ->and($on->isBoard('chess'))->toBeFalse();

    expect($rebuild(['enabled' => false, 'games' => [FIXTURE_BOARD => $entry]])->boards())->toBe([])
        ->and($rebuild(['enabled' => true, 'games' => [FIXTURE_BOARD => ['enabled' => false, 'class' => FixtureBoardGame::class]]])->boards())->toBe([])
        // The shipped entries have no class yet (P3, P4): switched on, they stay off.
        ->and($rebuild(['enabled' => true, 'games' => ['checkers' => ['enabled' => true, 'class' => null]]])->boards())->toBe([]);

    // A class under another slug, or no board game at all: left off, chess still there.
    $wrongSlug = $rebuild(['enabled' => true, 'games' => ['checkers' => $entry]]);
    $notBoard = $rebuild(['enabled' => true, 'games' => [FIXTURE_BOARD => ['enabled' => true, 'class' => Chess::class]]]);

    expect($wrongSlug->boards())->toBe([])
        ->and(array_keys($notBoard->all()))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26']);
});

test('the page of a board game is the list of all games, never the chess lobby', function () {
    FixtureBoardGame::register();

    expect(GameNames::page(FIXTURE_BOARD))->toBe(route('play'))
        ->and(GameNames::page('chess'))->toBe(route('chess.lobby'))
        ->and(GameNames::page('rocket-league'))->toBe(route('games.series', 'rocket-league'));
});

test('the shell navigation leaves a board game out: no hub tile, no active game', function () {
    FixtureBoardGame::register();

    expect(array_column(ShellNavigation::current()->games(), 'slug'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26']);

    $this->get(route('matches.index', ['game' => FIXTURE_BOARD]))->assertOk()
        ->assertDontSee('data-test="hub-game-'.FIXTURE_BOARD.'"', false)
        ->assertDontSee('Fixture Board');
    expect(ShellNavigation::current()->pageGame())->toBeNull();
});

test('the match list never files chess games under a board game', function () {
    FixtureBoardGame::register();

    Livewire::withQueryParams(['game' => FIXTURE_BOARD])->test('pages::matches.index')
        ->assertSet('game', 'all')
        ->assertDontSeeHtml('data-test="game-'.FIXTURE_BOARD.'"')
        ->call('pickGame', FIXTURE_BOARD)->assertSet('game', 'all')
        ->call('pickGame', 'chess')->assertSet('game', 'chess');
});

test('the invite link module shows nothing for a board game, not the chess daily link', function () {
    FixtureBoardGame::register();

    $player = User::factory()->create();

    expect(Livewire::actingAs($player)->test('invite-link', ['game' => FIXTURE_BOARD])->assertDontSeeHtml('data-state=')->instance()->state)->toBe('hidden')
        ->and(Livewire::actingAs($player)->test('invite-link', ['game' => 'chess'])->assertSeeHtml('data-state="daily"')->instance()->state)->toBe('daily');

    auth()->logout();
    expect(Livewire::test('invite-link', ['game' => FIXTURE_BOARD])->instance()->state)->toBe('hidden');
});

test('a board game has no ladder page, no ladder card and no ladder in the sitemap, the hubs or the event admin', function () {
    FixtureBoardGame::register();
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);

    $this->get(route('ladder.show', [FIXTURE_BOARD, 'blitz']))->assertNotFound();
    $this->get(route('ladder.show', ['chess', 'blitz']))->assertOk();

    expect(PageCard::resolve('ladder', FIXTURE_BOARD.'.blitz'))->toBeNull()
        ->and(PageCard::resolve('ladder', 'chess.blitz'))->not->toBeNull();

    $sitemap = $this->get(route('sitemap.section', ['pages', 1]))->assertOk()->getContent();
    expect($sitemap)->toContain(route('ladder.show', ['chess', 'blitz']))->not->toContain(FIXTURE_BOARD);

    $this->get('/')->assertOk()->assertDontSee(FIXTURE_BOARD)->assertDontSee('Fixture Board');
    $this->get(route('ladder.strongest'))->assertOk()->assertDontSee(FIXTURE_BOARD)->assertDontSee('Fixture Board');
    Livewire::actingAs($admin)->test('pages::admin.events')
        ->assertSeeHtml('value="chess/blitz"')->assertDontSeeHtml('value="'.FIXTURE_BOARD.'/blitz"');
});

test('the rules, the games list, the tournaments list and their cards leave a board game out', function () {
    FixtureBoardGame::register();
    Cache::flush();

    $this->get(route('rules'))->assertOk()->assertSee('Rocket League')->assertDontSee('Fixture Board');
    $this->get(route('play'))->assertOk()->assertSee('Rocket League')->assertDontSee('Fixture Board');
    $this->get(route('tournaments.index'))->assertOk()->assertDontSee('Fixture Board');

    expect(PageCardFacts::page('play')['games'])->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'])
        ->and(PageCardFacts::page('rules')['figures'][0])->toBe(['games', 4]);
});

test('the stream bot does not announce a board game before it is playable', function () {
    FixtureBoardGame::register();
    $builders = app(StreamBotBuilders::class);
    $builders->pickVariantsWith(fn (int $variants): int => 0);

    $message = $builders->build('all_games', CarbonImmutable::now())[0]->content;

    expect($message)->toContain('Rocket League')->not->toContain('Fixture Board');
});

test('no tournament and no cup runs a board game: it has no tournament profile', function () {
    FixtureBoardGame::register();
    config(['esports.casual_cups.enabled' => ['chess', FIXTURE_BOARD], 'esports.casual_cups.games.'.FIXTURE_BOARD => config('esports.casual_cups.games.chess')]);

    expect(fn () => GameProfile::for(FIXTURE_BOARD, 'blitz'))->toThrow(InvalidArgumentException::class)
        ->and(array_column(TournamentGames::all(), 0))->not->toContain(FIXTURE_BOARD)
        ->and(CasualCups::enabledGames())->toBe(['chess'])
        ->and(fn () => Tournament::factory()->make(['game' => FIXTURE_BOARD, 'mode' => 'blitz'])->profile())->toThrow(InvalidArgumentException::class);
});

test('a board game mines nothing before P6: no ladder event, no chain row, no parameter change', function () {
    FixtureBoardGame::register();
    $season = openSeason();
    $trust = new TestSigner;
    config(['esports.trust.nsec' => $trust->secret]);

    app(LadderEvents::class)->publish($season, LeagueKey::required(), $trust->pubkey);

    expect(NostrEvent::query()->where('kind', Ladders::KIND)->where('d', 'like', FIXTURE_BOARD.'/%')->count())->toBe(0)
        ->and(NostrEvent::query()->where(['kind' => Ladders::KIND, 'd' => 'chess/blitz/'.$season->slug])->count())->toBe(1)
        ->and(array_keys(ChainDraft::table(ChainDraft::defaults())))->not->toContain(FIXTURE_BOARD)
        ->and(ChainDraft::table(ChainDraft::defaults()))->toHaveKey('chess');

    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);
    $this->travel(1)->minutes();

    foreach ([['weights' => [FIXTURE_BOARD.'/blitz' => 1000]], ['shares' => [FIXTURE_BOARD => 10]], ['daily' => [FIXTURE_BOARD => 4]]] as $change) {
        expect(fn () => app(SeasonChains::class)->changeParameters($admin, $change, 'Board games mine.', CarbonImmutable::now()))
            ->toThrow(SeasonReleaseRefused::class);
    }

    // The same change for chess goes through: the refusal is the board game's, not the form's.
    app(SeasonChains::class)->changeParameters($admin, ['daily' => ['chess' => 4]], 'Long blitz evenings.', CarbonImmutable::now());
    expect(NostrEvent::query()->where('kind', Ladders::KIND)->where('d', 'like', FIXTURE_BOARD.'/%')->count())->toBe(0);
});
