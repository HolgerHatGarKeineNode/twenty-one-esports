<?php

/*
| Board games next to chess (plan "Mühle und Dame", P1): every game names its
| kind, board games come in only through `esports.board_games` (off by
| default), and every switch between chess and the series treats a board
| game as neither. P5 opened play, ladders, navigation, rules, cups and
| tournaments (tests/Feature/BoardLeagueTest.php); what stays closed until
| P6 (mining, the event admin) or P7 (the stream) is still left out here.
| FixtureBoardGame stands in for the real games.
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
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\FixtureBoardGame;
use Tests\Support\NineMensMorrisOn;
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

test('the page of a board game is its lobby, or the list of all games while its route is not there, never the chess lobby', function () {
    FixtureBoardGame::register();

    // Registered, but the routes of the board games were loaded at boot with the switch off.
    expect(GameNames::page(FIXTURE_BOARD))->toBe(route('play'));

    FixtureBoardGame::play();

    expect(GameNames::page(FIXTURE_BOARD))->toBe(route('board.lobby', FIXTURE_BOARD))
        ->and(GameNames::page('chess'))->toBe(route('chess.lobby'))
        ->and(GameNames::page('rocket-league'))->toBe(route('games.series', 'rocket-league'));
});

test('the shell navigation lists a board game with its own actions (P5), while the match list files none under it', function () {
    FixtureBoardGame::play();

    $game = collect(ShellNavigation::current()->games())->firstWhere('slug', FIXTURE_BOARD);

    expect(array_column(ShellNavigation::current()->games(), 'slug'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', FIXTURE_BOARD])
        ->and(array_column($game['actions'], 'key'))->toBe(['play', 'ladder', 'rules', 'strongest'])
        ->and($game['actions'][0]['href'])->toBe(route('board.lobby', FIXTURE_BOARD));

    $this->get(route('matches.index', ['game' => FIXTURE_BOARD]))->assertOk()
        ->assertSee('data-test="hub-game-'.FIXTURE_BOARD.'"', false);
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

test('a board game has its ladder page, card and sitemap entries (P5); the event admin still leaves it out (P6)', function () {
    FixtureBoardGame::play();
    $admin = User::factory()->create();
    config(['esports.board' => [NostrKeys::hexToNpub($admin->pubkey)]]);

    $this->get(route('ladder.show', [FIXTURE_BOARD, 'blitz']))->assertOk();
    $this->get(route('ladder.show', ['chess', 'blitz']))->assertOk();

    expect(PageCard::resolve('ladder', FIXTURE_BOARD.'.blitz'))->not->toBeNull()
        ->and(PageCard::resolve('ladder', 'chess.blitz'))->not->toBeNull();

    $sitemap = $this->get(route('sitemap.section', ['pages', 1]))->assertOk()->getContent();
    expect($sitemap)->toContain(route('ladder.show', ['chess', 'blitz']))
        ->toContain(route('ladder.show', [FIXTURE_BOARD, 'blitz']))
        ->toContain(route('board.lobby', FIXTURE_BOARD));

    $this->get(route('ladder.strongest'))->assertOk()->assertSee('Fixture Board');
    Livewire::actingAs($admin)->test('pages::admin.events')
        ->assertSeeHtml('value="chess/blitz"')->assertDontSeeHtml('value="'.FIXTURE_BOARD.'/blitz"');
});

test('the rules, the games list, the tournaments list and their cards name a board game once it is on (P5)', function () {
    FixtureBoardGame::play();
    Cache::flush();

    $this->get(route('rules'))->assertOk()->assertSee('Rocket League')->assertSee('Fixture Board');
    $this->get(route('play'))->assertOk()->assertSee('Rocket League')->assertSee('Fixture Board');
    $this->get(route('tournaments.index'))->assertOk()->assertSee('Fixture Board');

    expect(PageCardFacts::page('play')['games'])->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', FIXTURE_BOARD])
        ->and(PageCardFacts::page('rules')['figures'][0])->toBe(['games', 5]);
});

test('the stream bot does not announce a board game before it is playable', function () {
    FixtureBoardGame::register();
    $builders = app(StreamBotBuilders::class);
    $builders->pickVariantsWith(fn (int $variants): int => 0);

    $message = $builders->build('all_games', CarbonImmutable::now())[0]->content;

    expect($message)->toContain('Rocket League')->not->toContain('Fixture Board');
});

test('a tournament plays only a board game with a profile: nine men\'s morris and checkers have one (P5), the fixture has none', function () {
    FixtureBoardGame::register();

    expect(fn () => GameProfile::for(FIXTURE_BOARD, 'blitz'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Tournament::factory()->make(['game' => FIXTURE_BOARD, 'mode' => 'blitz'])->profile())->toThrow(InvalidArgumentException::class)
        // The organizers' chooser offers board games with P6 (the admin pages), not before.
        ->and(array_column(TournamentGames::all(), 0))->not->toContain(FIXTURE_BOARD);

    foreach (BoardGame::RESERVED_SLUGS as $slug) {
        $profile = GameProfile::for($slug, 'blitz');

        expect($profile->isBoard())->toBeTrue()
            ->and($profile->isChess())->toBeFalse()
            ->and($profile->isSeries())->toBeFalse()
            ->and($profile->bestOfOptions)->toBe([1]);
    }

    // Switched off, a board game's cup does not open; chess's does.
    config(['esports.casual_cups.enabled' => ['chess', 'nine-mens-morris']]);
    expect(CasualCups::enabledGames())->toBe(['chess']);
});

test('switched off, a board game has no route and no place in the navigation', function () {
    // The test app boots with the switch off: routes/board.php is never loaded.
    expect(Route::has('board.lobby'))->toBeFalse()->and(Route::has('board.show'))->toBeFalse();

    $this->get('/games/nine-mens-morris')->assertNotFound();
    $this->get('/games/checkers')->assertNotFound();
    $this->get('/board/1')->assertNotFound();
    $this->get(route('ladder.show', ['nine-mens-morris', 'blitz']))->assertNotFound();
    $this->get(route('play'))->assertOk()->assertDontSee("Nine Men's Morris")->assertDontSee('data-test="play-game-checkers"', false);
    expect(array_column(ShellNavigation::current()->games(), 'slug'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26']);

    // The switch on, but one board game's own entry off: its lobby is not found, the other one's is there.
    NineMensMorrisOn::play();
    $this->get(route('board.lobby', 'nine-mens-morris'))->assertOk();
    $this->get(route('board.lobby', 'checkers'))->assertNotFound();
    expect(array_column(ShellNavigation::current()->games(), 'slug'))->not->toContain('checkers');
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
