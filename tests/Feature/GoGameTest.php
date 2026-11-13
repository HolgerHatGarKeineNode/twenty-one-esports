<?php

use App\Enums\BoardGameStatus;
use App\Games\GameRegistry;
use App\Games\Go;
use App\Games\Go13;
use App\Games\Go19;
use App\Games\Go9;
use App\Models\BoardGame;
use App\Models\User;
use App\Support\Badges\BadgeCopy;
use App\Support\Board\BoardChallenges;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Board\GoLabels;
use App\Support\Board\GoRules;
use App\Support\Engagement\HomeBoard;
use App\Support\GameChat\GameChannels;
use App\Support\GameNames;
use App\Support\Pages\RulesPage;
use App\Support\SeasonChain\ChainOverview;
use App\Support\Tournaments\GameProfile;
use Livewire\Livewire;
use Tests\Support\GoOn;

/*
|--------------------------------------------------------------------------
| Go in the league (DerCaddy, 2026-10-27)
|--------------------------------------------------------------------------
|
| Go comes as three board games, one per size (go-9, go-13, go-19), each with
| its own modes, ladders and tournaments, off until switched on. A game is
| played on the board game core like Blockli: the server judges every stone,
| two passes end the game and the count as it stands decides (DerCaddy chose
| "ausspielen, dann zählen"). Black moves first, so the core's first side is
| named Black everywhere. The rules themselves are tests/Unit/Board/GoRulesTest.
|
*/

beforeEach(function () {
    GoOn::play();
});

/** A running Go game between two new players, Black (the first side) and White. */
function goGame(string $slug = 'go-9', ?string $mode = null): BoardGame
{
    return app(BoardGameService::class)->start($slug, User::factory()->create(), User::factory()->create(), $mode);
}

/**
 * Plays the moves in turn, each by the player whose move it is.
 *
 * @param  list<string>  $moves
 */
function goPlay(BoardGame $game, array $moves): BoardGame
{
    foreach ($moves as $move) {
        $game->refresh();
        $game = app(BoardGameService::class)->move($game, $game->player($game->turn), $move, $game->ply + 1);
    }

    return $game->refresh();
}

function goRefusal(Closure $action): ?string
{
    try {
        $action();
    } catch (BoardRuleViolation $violation) {
        return $violation->reason;
    }

    return null;
}

test('Go is three board games, one per size, each with its modes, Black first, off until switched on', function () {
    $registry = GoOn::play();

    foreach ([Go9::SLUG => [9, ['blitz', 'rapid', 'correspondence']], Go13::SLUG => [13, ['rapid', 'correspondence']], Go19::SLUG => [19, ['rapid', 'correspondence']]] as $slug => [$size, $modes]) {
        $game = $registry->find($slug);

        expect($game)->toBeInstanceOf(Go::class)
            ->and($game->name())->toBe("Go {$size}×{$size}")
            ->and(array_keys($game->modes()))->toBe($modes)
            ->and($game->rules())->toBeInstanceOf(GoRules::class)
            ->and($game->rules()->size())->toBe($size)
            ->and($game->playsTwoLegs())->toBeFalse()
            ->and($game->sideColours())->toBe(['w' => 'black', 'b' => 'white'])
            ->and(GameNames::sides($slug))->toBe(['w' => 'Black', 'b' => 'White'])
            ->and($registry->isBoard($slug))->toBeTrue();

        // Every mode can be a tournament's: the estimator plans it.
        foreach ($modes as $mode) {
            expect(GameProfile::for($slug, $mode)->game)->toBe($slug);
        }
    }

    expect($registry->find(Go9::SLUG)->modes()['blitz']->timeControl)->toBe('300+3')
        ->and($registry->find(Go19::SLUG)->modes()['rapid']->timeControl)->toBe('900+10')
        // Chess keeps its colours.
        ->and(GameNames::sides('chess'))->toBe(['w' => 'White', 'b' => 'Black']);

    // One size alone: the others stay off.
    config(['esports.board_games.games.go-9.enabled' => false, 'esports.board_games.games.go-19.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    $alone = app(GameRegistry::class);
    expect($alone->find(Go13::SLUG))->toBeInstanceOf(Go13::class)
        ->and($alone->find(Go9::SLUG))->toBeNull()
        ->and($alone->find(Go19::SLUG))->toBeNull();
});

test('a game is played out and counted: two passes end it, the count decides, and the clocks stop', function () {
    $game = goPlay(goGame(), ['e5', 'c4']);
    // An ending on the board: Black's wall on the e file, White's on the f file; Black's area is the bigger one.
    $game = GoOn::setUp($game, '4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3 x - 0');

    $game = goPlay($game, ['pass']);
    expect($game->status)->toBe(BoardGameStatus::Active)
        ->and(app(BoardGameService::class)->snapshot($game)['lastMove']['notation'])->toBe('Pass');

    $game = goPlay($game, ['pass']);
    $count = app(BoardGameService::class)->snapshot($game)['count'];

    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($game->result)->toBe('1-0')
        ->and($game->end_reason)->toBe('score')
        ->and($count['w'])->toBe(['stones' => 9, 'area' => 36, 'score' => 45.0])
        ->and($count['b'])->toBe(['stones' => 9, 'area' => 27, 'score' => 43.5])
        ->and($count['lead'])->toBe('w')
        ->and($count['owners']['w'])->toHaveCount(36);
});

test('White wins by the komi when the areas are equal, and the snapshot carries the count all game long', function () {
    $game = goGame('go-13', 'rapid');
    $snapshot = app(BoardGameService::class)->snapshot($game);

    expect($snapshot['count'])->toMatchArray(['komi' => 7.5, 'margin' => -7.5, 'lead' => 'b'])
        ->and($snapshot['legal'])->toHaveCount(13 * 13 + 1)
        ->and($snapshot['legal'][0])->toBe(['move' => 'a1', 'path' => ['a1']])
        ->and(end($snapshot['legal']))->toBe(['move' => 'pass', 'path' => []]);

    $game = goPlay($game, ['pass', 'pass']);

    expect($game->result)->toBe('0-1')
        ->and($game->end_reason)->toBe('score');
});

test('the server refuses what the rules forbid: a stone on a stone, suicide, retaking a ko at once', function () {
    $service = app(BoardGameService::class);
    $game = goPlay(goGame(), ['e5', 'c4']);

    expect(goRefusal(fn () => $service->move($game->refresh(), $game->player('w'), 'e5', 3)))->toBe('illegal_move')
        ->and(goRefusal(fn () => $service->move($game->refresh(), $game->player('w'), 'i5', 3)))->toBe('illegal_move');

    // White's corner a9 would have no liberty and take nothing.
    $game = GoOn::setUp($game, '1x7/x8/9/9/9/9/9/9/9 o - 0');
    expect(goRefusal(fn () => $service->move($game->refresh(), $game->player('b'), 'a9', 3)))->toBe('illegal_move');

    // Black takes on e5 (a ko): White may not take back on d5 at once.
    $game = GoOn::setUp($game, '9/9/9/3xo4/2xo1o3/3xo4/9/9/9 x - 0');
    $game = goPlay($game, ['e5']);
    expect(goRefusal(fn () => $service->move($game->refresh(), $game->player('b'), 'd5', $game->ply + 1)))->toBe('illegal_move')
        ->and(collect($service->snapshot($game)['legal'])->pluck('move'))->not->toContain('d5');
});

test('a stone and a pass can be taken back, as every board game move', function () {
    $service = app(BoardGameService::class);
    $game = goPlay(goGame(), ['e5', 'c4', 'g5']);
    $game = $service->takeBack($game, $game->player('w'))->refresh();

    expect($game->ply)->toBe(2)
        ->and($service->snapshot($game)['pieces'])->not->toHaveKey('g5');

    $game = goPlay($game, ['pass']);
    $game = $service->takeBack($game, $game->player('w'))->refresh();
    expect($game->ply)->toBe(2)
        ->and($game->turn)->toBe('w');
});

test('the board is a go board: stones by colour, star points, the same way round for both, the words of the stone input', function () {
    $game = goPlay(goGame('go-19', 'correspondence'), ['q16', 'd4']);
    $service = app(BoardGameService::class);
    $layout = $service->layout($game);

    expect($layout)->toMatchArray(['width' => 2000, 'height' => 2000, 'input' => 'stones', 'upright' => true, 'stars' => GoRules::STARS[19]])
        ->and($layout['points'])->toHaveCount(361)
        ->and($service->snapshot($game)['pieces'])->toBe(['q16' => ['side' => 'w', 'kind' => 'black'], 'd4' => ['side' => 'b', 'kind' => 'white']]);

    $config = Livewire::actingAs($game->player('w'))->test('pages::board.show', ['boardGame' => $game])->instance()->config();

    expect($config['labels']['white'])->toBe('Black')
        ->and($config['labels']['black'])->toBe('White')
        ->and($config['labels']['stones'])->toBe(GoLabels::board()['stones'])
        ->and($config['labels']['count'])->toBe(GoLabels::board()['count'])
        ->and($config['labels']['reasons'])->toMatchArray(['score' => 'Counted after both passed', 'move_limit' => 'Counted at the move limit']);

    $this->actingAs($game->player('w'))->get(route('board.show', $game))->assertOk()
        ->assertSee('data-test="stone-input"', false)->assertSee('data-test="pass"', false)->assertSee('data-test="count"', false)
        ->assertSee('Go 19×19');
});

test('the lobby of each size offers the practice, and the rules page explains Go', function () {
    foreach (['go-9', 'go-13', 'go-19'] as $slug) {
        $this->get(route('board.lobby', $slug))->assertOk()
            ->assertSee(route('board.go-practice'))->assertSee('Learn Go and practice');
    }

    $section = collect(RulesPage::sections())->firstWhere('id', 'go');

    expect($section['title'])->toBe('Go')
        ->and($section['facts'])->toContain(['Time control · Go 9×9', 'Blitz 5+3, Rapid 10+5, 1 move a day'], ['Komi for White', '7.5'])
        ->and(implode(' ', $section['items']))->toContain('Two passes in a row end the game', 'nothing marked dead', 'Take back');

    $this->get(route('rules'))->assertOk()->assertSee('The old game of surrounding');
});

test('a correspondence challenge names the colours of Go: the challenger who takes the first side plays Black', function () {
    [$challenger, $challenged] = User::factory()->count(2)->create();
    $challenges = app(BoardChallenges::class);

    // The form's "white" is the first side, which lays the black stones in Go: the one challenged plays White.
    $challenge = $challenges->challenge($challenger, $challenged, 'go-9', 'white');
    expect($challenged->notifications()->where('type', 'challenge')->sole()->data['body'])->toContain('you play White');

    $game = $challenges->accept($challenge->refresh(), $challenged);

    expect($game->colorOf($challenger))->toBe('w')
        ->and($game->mode)->toBe('correspondence')
        ->and($challenger->notifications()->where('type', 'game_started')->sole()->data['body'])->toBe('Go 9×9 correspondence · you play Black, your move.');

    app()->setLocale('de');

    expect(GameNames::sides($game->game))->toBe(['w' => 'Schwarz', 'b' => 'Weiß'])
        ->and(__(':game correspondence · you play :color, your move.', ['game' => 'Go 9×9', 'color' => GameNames::sides($game->game)['w']]))->toBe('Fern-Go 9×9 · du spielst Schwarz, du bist am Zug.');
});

test('Go takes its place where every board game has one: the ladders\' names, the start page\'s order, a chat channel per size', function () {
    config(['esports.game_chat.creator' => str_repeat('a', 64)]);

    foreach (['go-9' => 'Go 9×9', 'go-13' => 'Go 13×13', 'go-19' => 'Go 19×19'] as $slug => $name) {
        expect(BadgeCopy::ladder($slug, 'correspondence'))->toBe("{$name} correspondence")
            ->and(ChainOverview::keyLabel("{$slug}/correspondence"))->toBe("{$name} correspondence")
            ->and(GameChannels::has($slug))->toBeTrue()
            ->and(GameChannels::createEvent($slug)['content'] ?? null)->toBe('{"name":"TWENTY ONE esports · '.$name.'","about":"The global chat of '.$name.' in the TWENTY ONE esports league: talk and vote."}');
    }

    // The start page's browser games: Go's three sizes right after Checkers, as everywhere (esports.game_order).
    expect(array_slice(HomeBoard::BROWSER_ORDER, -4))->toBe(['checkers', 'go-9', 'go-13', 'go-19']);

    // The list of one's correspondence games holds Go's too, and says so.
    $game = goGame('go-13', 'correspondence');
    $this->actingAs($game->player('w'))->get(route('me.correspondence'))->assertOk()
        ->assertSee('Chess, Blockli, nine men&#039;s morris, checkers, Go: one list', false)->assertSee('Go 13×13');

    app()->setLocale('de');
    expect(BadgeCopy::ladder('go-19', 'correspondence'))->toBe('Fern-Go 19×19');
});

test('every word of Go is there in German', function () {
    $german = json_decode((string) file_get_contents(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);
    $keys = array_values((new GoRules)->reasons());

    foreach ([app_path('Support/Board/GoLabels.php'), resource_path('views/pages/board/partials/stone-input.blade.php'), resource_path('views/pages/board/partials/area-count.blade.php'),
        resource_path('views/pages/board/partials/lobby-play.blade.php')] as $file) {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $found);
        array_push($keys, ...array_map(stripslashes(...), $found[1]));
    }

    // The rules page's Go section.
    $rules = (string) file_get_contents(app_path('Support/Pages/RulesPage.php'));
    $go = substr($rules, (int) strpos($rules, 'private static function go()'), (int) strpos($rules, 'private static function nineMensMorris()') - (int) strpos($rules, 'private static function go()'));
    preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $go, $found);
    array_push($keys, ...array_map(stripslashes(...), $found[1]));

    expect(array_values(array_diff(array_unique($keys), array_keys($german))))->toBe([]);

    $this->withSession(['locale' => 'de'])->get(route('rules'))->assertOk()->assertSee('Das alte Spiel des Umzingelns')->assertSee('Komi für Weiß');
});
