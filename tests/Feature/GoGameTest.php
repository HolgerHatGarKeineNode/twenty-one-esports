<?php

use App\Enums\BoardGameStatus;
use App\Games\BoardSide;
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
use App\Support\Cards\Canvas;
use App\Support\Cards\PageCard;
use App\Support\Cards\StoneBoardArt;
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
            ->and(array_map(fn (BoardSide $side): string => $side->name, $game->sides()))->toBe(['w' => 'Black', 'b' => 'White'])
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
        // Each side's area as one character per point, rows from the top: four files of Black's, the stones, three of White's.
        ->and($count['owners'])->toBe(str_repeat('wwww..bbb', 9));
});

test('White wins by the komi when the areas are equal, and the snapshot carries the count all game long', function () {
    $game = goGame('go-13', 'rapid');
    $snapshot = app(BoardGameService::class)->snapshot($game);

    expect($snapshot['count'])->toMatchArray(['komi' => 7.5, 'margin' => -7.5, 'lead' => 'b'])
        // A board of stones is written short: the stones as text, no list of every legal point, only the refused ones.
        ->and($snapshot['pieces'])->toBe(str_repeat('.', 13 * 13))
        ->and($snapshot['legal'])->toBe([])
        ->and($snapshot['illegal'])->toBe([])
        ->and($snapshot['pass'])->toBe('pass');

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
        ->and($service->snapshot($game)['illegal'])->toBe(['d5']);
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
        // The stones as text, one character per point in the layout's order: `x` the first side's (black), `o` the other's.
        ->and(array_filter(array_combine(array_column($layout['points'], 'id'), str_split($service->snapshot($game)['pieces'])), fn (string $stone): bool => $stone !== '.'))->toBe(['q16' => 'x', 'd4' => 'o'])
        ->and($service->snapshot($game)['moves'])->each->not->toHaveKey('pieces')
        ->and($service->snapshot($game)['moves'][1])->toMatchArray(['move' => 'd4', 'path' => ['d4'], 'side' => 'b', 'taken' => []]);

    $config = Livewire::actingAs($game->player('w'))->test('pages::board.show', ['boardGame' => $game])->instance()->config();

    expect($config['labels']['white'])->toBe('Black')
        ->and($config['labels']['black'])->toBe('White')
        ->and($config['labels']['stones'])->toBe(GoLabels::board()['stones'])
        ->and($config['labels']['count'])->toBe(GoLabels::board()['count'])
        ->and($config['labels']['reasons'])->toMatchArray(['score' => 'Counted after both passed', 'move_limit' => 'Drawn at the move limit']);

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

/**
 * The colour in the middle of the square beside a player on a finished board game's card (PageCard::gamePlayer()): the
 * first side sits at the bottom, the other at the top, and the winner's picture is the larger one.
 */
function goCardSwatch(string $png, string $side, bool $won): string
{
    $size = $won ? 104 : 80;
    $top = $side === 'w' ? 48 + 446 - $size : 48;
    $rgb = imagecolorat(imagecreatefromstring($png), 64 + 446 + 56 + $size + 28 + 12, (int) ($top + $size / 2 + 38 - 10));

    return sprintf('#%06X', $rgb & 0xFFFFFF);
}

test('the card of a finished Go game says who won by the stones, with each side\'s own colour beside its player', function (string $result, string $headline, string $wrong) {
    $game = goGame();
    $game->forceFill(['status' => BoardGameStatus::Finished, 'result' => $result])->save();
    $card = PageCard::boardGame($game->refresh());
    [$black, $white] = [$game->player('w')->name, $game->player('b')->name];

    expect($card->gameHeadline())->toBe($headline)->not->toBe($wrong)
        ->and($card->sideLabel('white'))->toBe('Black')
        ->and($card->sideLabel('black'))->toBe('White')
        // The first side (`w`) laid the black stones: its player is the one named Black, and the winner by name.
        ->and($card->alt())->toContain(($result === '1-0' ? $black : $white).' won. ')
        ->toContain($black.' (black), '.$white.' (white).');
})->with([
    'Black won' => ['1-0', 'Black wins', 'White wins'],
    'White won' => ['0-1', 'White wins', 'Black wins'],
]);

test('the drawn card of a finished Go game shows a black stone beside Black and a white one beside White, lit as on the board', function (string $result) {
    $game = goGame();
    $game->forceFill(['status' => BoardGameStatus::Finished, 'result' => $result])->save();
    $png = PageCard::boardGame($game->refresh())->render();
    // The middle of each stone: the board's own light there (StoneBoardArt::shade()), not a flat square.
    $near = fn (string $hex, string $kind): bool => max(array_map(fn (int $got, int $want): int => abs($got - $want), sscanf($hex, '#%02X%02X%02X'), StoneBoardArt::shade($kind, 0.5, 0.5))) <= 6;

    expect($near(goCardSwatch($png, 'w', $result === '1-0'), 'black'))->toBeTrue(goCardSwatch($png, 'w', $result === '1-0'))
        ->and($near(goCardSwatch($png, 'b', $result === '0-1'), 'white'))->toBeTrue(goCardSwatch($png, 'b', $result === '0-1'));
})->with(['Black won' => ['1-0'], 'White won' => ['0-1']]);

test('the card of a Go game draws the board of the game\'s page: its wood, the stone set last ringed, and a counted game\'s points and margin', function () {
    // One source of colours: the card's material is the page's, value for value.
    $script = file_get_contents(resource_path('js/boardGame.js'));
    expect($script)->toContain("wood: ['".StoneBoardArt::WOOD[0]."', '".StoneBoardArt::WOOD[1]."']")
        ->toContain("side: '".StoneBoardArt::SIDE."'")
        ->toContain("grain: '".StoneBoardArt::GRAIN."'")
        ->toContain("sheen: '".StoneBoardArt::SHEEN."'")
        ->toContain("line: '".StoneBoardArt::LINE."'")
        ->toContain("ring: { black: '".StoneBoardArt::RING['black']."', white: '".StoneBoardArt::RING['white']."' }")
        ->toContain("const STONE_EDGE = { black: '".StoneBoardArt::EDGE['black']."', white: '".StoneBoardArt::EDGE['white']."' }");

    foreach (StoneBoardArt::STONE as $kind => $light) {
        $stops = implode('', array_map(fn (array $stop): string => '<stop offset="'.$stop[0].'" stop-color="'.$stop[1].'"/>', $light['stops']));
        expect($script)->toContain('<radialGradient id="go-stone-'.$kind.'" cx="'.($light['cx'] * 100).'%" cy="'.($light['cy'] * 100).'%" r="'.($light['r'] * 100).'%">'.$stops.'</radialGradient>');
    }

    // The board's stylesheet names each of these colours once, as a custom property, and uses it by that name alone.
    $css = (string) preg_replace('#/\*.*?\*/#s', '', file_get_contents(resource_path('js/go/board.css')));
    preg_match_all('/^\s*(--go-[a-z-]+):\s*(#[0-9a-fA-F]{6});$/m', $css, $defined);
    preg_match('/guide: \'(#[0-9A-F]{6})\'/', $script, $guide);
    $colours = [
        '--go-wood-from' => StoneBoardArt::WOOD[0],
        '--go-wood-to' => StoneBoardArt::WOOD[1],
        '--go-side' => StoneBoardArt::SIDE,
        '--go-line' => StoneBoardArt::LINE,
        '--go-guide' => $guide[1] ?? null,
        '--go-black' => Canvas::GROUND,
        '--go-white' => StoneBoardArt::STONE['white']['stops'][1][1],
    ];

    expect(array_map(strtoupper(...), array_combine($defined[1], $defined[2])))->toBe($colours);

    foreach ($colours as $property => $hex) {
        expect(substr_count(strtoupper($css), (string) $hex))->toBe(1, "{$hex} stands in board.css once, as {$property}")
            ->and($css)->toContain("var({$property})");
    }

    // A running game: the stone set last carries the ring, a pass sets none.
    $game = goPlay(goGame(), ['e5', 'c3']);
    $pixel = fn (string $png, int $x, int $y): string => sprintf('#%06X', imagecolorat(imagecreatefromstring($png), $x, $y) & 0xFFFFFF);
    expect(PageCard::boardGame($game)->facts['last'])->toBe('c3')
        // The slab's upper left corner is the wood, not the grey frame of the other boards.
        ->and(hexdec(substr($pixel(PageCard::boardGame($game)->render(), 66, 52), 1, 2)))->toBeGreaterThan(150);

    $game = goPlay($game, ['pass', 'pass']);
    $card = PageCard::boardGame($game);
    Canvas::$drawn = [];
    $card->render();
    $drawn = Canvas::$drawn;
    Canvas::$drawn = null;

    // One stone each and no area on the open board: Black has 1 point, White 1 and the komi of 7.5, so White wins by 7.5.
    expect($game->status)->toBe(BoardGameStatus::Finished)
        ->and($card->facts['last'])->toBeNull()
        ->and($card->gameHeadline())->toBe('White wins')
        ->and($drawn)->toContain('White wins', '+7.5', '8.5', '1', 'Counted after both passed')
        ->not->toContain('0–1');

    app()->setLocale('de');
    Canvas::$drawn = [];
    PageCard::boardGame($game)->render();
    $german = Canvas::$drawn;
    Canvas::$drawn = null;

    expect($german)->toContain('Weiß gewinnt', '+7,5', '8,5');
});

test('Go takes its place where every board game has one: the ladders\' names, the start page\'s order, one chat channel for all sizes', function () {
    config(['esports.game_chat.creator' => str_repeat('a', 64)]);

    foreach (['go-9' => 'Go 9×9', 'go-13' => 'Go 13×13', 'go-19' => 'Go 19×19'] as $slug => $name) {
        expect(BadgeCopy::ladder($slug, 'correspondence'))->toBe("{$name} correspondence")
            ->and(ChainOverview::keyLabel("{$slug}/correspondence"))->toBe("{$name} correspondence")
            ->and(GameChannels::has($slug))->toBeTrue()
            ->and(GameChannels::createEvent($slug)['content'] ?? null)->toBe('{"name":"TWENTY ONE esports · Go","about":"The global chat of Go in the TWENTY ONE esports league: talk and vote."}')
            // One channel for the three sizes: every lobby's chat is configured with the same id.
            ->and(GameChannels::config($slug, null)['channel'] ?? null)->toBe('b107d16f48bd8e217071cc8d5b73c18365c837c796c43f37898a9f4e4387342e');
    }

    // Only 13×13 on: the channel is open and published under the group, and a size switched off has none.
    config(['esports.board_games.games.go-9.enabled' => false, 'esports.board_games.games.go-19.enabled' => false]);
    app()->forgetInstance(GameRegistry::class);
    expect(GameChannels::has('go-13'))->toBeTrue()
        ->and(GameChannels::config('go-13', null)['channel'] ?? null)->toBe('b107d16f48bd8e217071cc8d5b73c18365c837c796c43f37898a9f4e4387342e')
        ->and(GameChannels::open())->toContain('go')
        ->and(GameChannels::has('go-9'))->toBeFalse();
    GoOn::play();

    // The start page's browser games: Go, one tile for its sizes, right after Checkers, as everywhere (esports.game_order).
    expect(array_slice(HomeBoard::BROWSER_ORDER, -2))->toBe(['checkers', Go::GROUP]);

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

    // Go's own partials: the score, the area switch, the player rows, and the parts of its practice page.
    foreach ([app_path('Support/Board/GoLabels.php'), resource_path('views/pages/board/partials/stone-input.blade.php'), ...glob(resource_path('views/pages/board/partials/go-*.blade.php')),
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
