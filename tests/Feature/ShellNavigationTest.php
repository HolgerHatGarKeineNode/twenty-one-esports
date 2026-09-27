<?php

use App\Games\GameRegistry;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Navigation\ShellNavigation;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeGame;

/*
| The lists behind the shell navigation (header concept B): "your games"
| order, the active game of a page, the /play page and "Start playing".
| The rendered bars are measured in tests/Browser/ShellNavigationTest.php.
*/

test('your games come first, by the last match, then the registry order; guests get the registry order', function () {
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    ChessGame::factory()->create(['white_id' => $player->id, 'created_at' => now()->subDays(3)]);
    SeriesMatch::factory()->accepted()->create(['challenger_lineup_id' => $lineup->id, 'created_at' => now()->subDay()]);
    $registryOrder = array_keys(app(GameRegistry::class)->all());

    $this->actingAs($player)->get('/clans')->assertOk()
        ->assertSeeInOrder(['data-test="game-tab-rocket-league"', 'data-test="game-tab-chess"'], false)
        ->assertSeeInOrder(['data-test="hub-game-rocket-league"', 'data-test="hub-yours"', 'data-test="hub-game-chess"', 'data-test="hub-yours"', 'data-test="hub-game-ea-sports-fc-27"'], false);

    auth()->logout();
    expect(array_column(ShellNavigation::current()->games(), 'slug'))->toBe($registryOrder);
});

test('the viewer\'s games cost two queries, however many matches there are', function () {
    $lineup = Lineup::factory()->mode('3v3')->ready()->create();
    $player = $lineup->clan->owner;
    SeriesMatch::factory()->accepted()->count(3)->create(['challenger_lineup_id' => $lineup->id]);
    ChessGame::factory()->count(3)->create(['white_id' => $player->id]);
    $this->actingAs($player);

    DB::flushQueryLog();
    DB::enableQueryLog();
    ShellNavigation::current()->games();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect(count(array_filter(array_column($queries, 'query'), fn (string $sql) => str_contains($sql, 'chess_games') || str_contains($sql, 'series_matches'))))->toBe(2);
});

test('row 2 names the game of the page, else the game opened last, else the first registered game', function () {
    $player = User::factory()->create();
    $this->actingAs($player);
    $contextOf = fn (string $uri): string => str($this->get($uri)->assertOk()->getContent())->match('/data-test="context-bar" data-game="([^"]+)"/')->toString();

    expect($contextOf('/clans'))->toBe('chess')
        ->and($contextOf(route('games.series', 'ea-sports-fc-26')))->toBe('ea-sports-fc-26')
        ->and($contextOf('/clans'))->toBe('ea-sports-fc-26')
        ->and($contextOf(route('matches.index', ['game' => 'rocket-league'])))->toBe('rocket-league')
        ->and($contextOf(route('ladder.show', ['chess', 'blitz'])))->toBe('chess')
        ->and($contextOf(route('matches.index', ['game' => 'not-a-game'])))->toBe('chess');
});

test('a match page belongs to the game of its match', function () {
    $series = SeriesMatch::factory()->create();

    $this->get(route('matches.show', $series->number))->assertOk()
        ->assertSee('data-test="context-bar" data-game="'.$series->game.'"', false);
});

test('the Season item carries the Block 0 tag before the first season', function () {
    $this->get('/rules')->assertOk()
        ->assertSeeInOrder(['data-test="nav-mining"', 'Season', 'data-test="season-tag"', 'Block 0 soon'], false);
});

test('/play lists every registered game with its modes, public, with "Log in to play" only for guests', function () {
    $games = app(GameRegistry::class)->all();

    $guest = $this->get(route('play'))->assertOk()->assertSee('data-test="play-login"', false);
    foreach ($games as $slug => $game) {
        $guest->assertSee('data-test="play-game-'.$slug.'"', false);
        foreach ($game->modes() as $mode) {
            $guest->assertSee(e($mode->name), false);
        }
    }

    $this->actingAs(User::factory()->create())->get(route('play'))->assertOk()
        ->assertDontSee('data-test="play-login"', false)
        ->assertSee('href="'.route('chess.challenge').'"', false);
});

test('"Start playing" logs a guest in and lands in the chess lobby; a plain login keeps its default', function () {
    $this->get(route('login', ['then' => 'play']))->assertOk()->assertSessionHas('url.intended', route('chess.lobby'));

    $this->flushSession();
    $this->get(route('login'))->assertOk()->assertSessionMissing('url.intended');
    $this->get(route('login', ['then' => 'https://evil.example']))->assertOk()->assertSessionMissing('url.intended');
});

test('a test-only registry of 12 games renders a hub tile for each, and the real registry has none of them', function () {
    app()->instance(GameRegistry::class, FakeGame::registry(12));
    $html = $this->get('/rules')->assertOk()->getContent();
    app()->forgetInstance(GameRegistry::class);

    expect(substr_count($html, 'data-test="hub-game-'))->toBe(12)
        ->and(array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug) => str_starts_with($slug, 'fake-')))->toBe([]);
});

test('Tournaments in row 1 and the tab bar counts the tournaments open for sign-up, and shows no badge when none is', function () {
    // Not open: a draft, and a sign-up whose deadline has passed.
    Tournament::factory()->create();
    Tournament::factory()->signup()->create(['signup_closes_at' => now()->subHour()]);

    $this->get('/rules')->assertOk()
        ->assertSee('data-test="nav-tournaments"', false)
        ->assertDontSee('data-test="tournaments-open"', false)
        ->assertDontSee('data-test="tab-tournaments-dot"', false);

    Tournament::factory()->signup()->count(2)->create(['signup_closes_at' => now()->addDay()]);
    Cache::forget(ShellNavigation::OPEN_TOURNAMENTS_KEY);

    $html = $this->get('/rules')->assertOk()->getContent();
    expect($html)->toMatch('/data-test="tournaments-open"><span class="sr-only">, <\/span>2<span class="sr-only"> open for sign-up<\/span>/')
        ->toContain('aria-label="Tournaments, 2 open for sign-up"')
        ->toContain('data-test="tab-tournaments-dot"')
        ->and(ShellNavigation::current()->tournaments()['open'])->toBe(2);
});

test('the active game holds the first tab, so narrow widths that hide the last tabs still show it', function () {
    $html = $this->get(route('games.series', 'ea-sports-fc-26'))->assertOk()->getContent();

    preg_match_all('/data-test="game-tab-([a-z0-9-]+)"/', $html, $tabs);

    expect($tabs[1][0] ?? null)->toBe('ea-sports-fc-26')
        ->and(array_unique($tabs[1]))->toHaveCount(count($tabs[1]));
});
