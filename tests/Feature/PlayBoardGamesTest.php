<?php

/*
|--------------------------------------------------------------------------
| The board games next to chess on /play and home (plan "Mühle und Dame", P7)
|--------------------------------------------------------------------------
|
| „Bin ich blind? https://esports.einundzwanzig.space/play ich sehe Dame und
| Mühle nicht?" — they sat at the very end of /play and were missing from
| the casual block at the top. Now they are one "Board games" group right
| after chess, two tiles in the casual block, and next to chess on home and
| in the phone's game chips.
|
*/

use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Navigation\ShellNavigation;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CheckersGame;
use Tests\Support\NineMensMorrisOn;

beforeEach(function () {
    Queue::fake();
});

/**
 * The game slugs in the order their `data-test="<prefix><slug>"` hooks appear in the HTML.
 *
 * @return list<string>
 */
function boardHooks(string $html, string $prefix): array
{
    preg_match_all('/data-test="'.preg_quote($prefix, '/').'([a-z0-9-]+)"/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

test('/play shows the board games as one group right after chess, and as tiles to their lobbies in the casual block', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();

    $html = $this->get(route('play'))->assertOk()
        ->assertSeeInOrder(['data-test="casual-play"', 'data-test="casual-boards"', 'data-test="play-game-chess"', 'data-test="play-board-games"', 'Board games', 'data-test="play-game-nine-mens-morris"', 'data-test="play-game-checkers"', 'data-test="play-game-rocket-league"'], false)
        ->assertSee('href="'.route('board.lobby', NineMensMorris::SLUG).'" data-test="casual-board-nine-mens-morris"', false)
        ->assertSee('href="'.route('board.lobby', Checkers::SLUG).'" data-test="casual-board-checkers"', false)
        ->getContent();

    expect(boardHooks($html, 'play-game-'))->toBe(['chess', 'nine-mens-morris', 'checkers', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'])
        ->and(boardHooks($html, 'casual-board-'))->toBe(['nine-mens-morris', 'checkers'])
        // The game names inside the group are one level below its heading.
        ->and($html)->toMatch('~<h3 class="[^"]*"><a href="'.preg_quote(route('board.lobby', Checkers::SLUG), '~').'"~');

    // In German.
    $this->withSession(['locale' => 'de'])->get(route('play'))->assertOk()
        ->assertSeeInOrder(['Oder ein Brettspiel, direkt im Browser:', 'Mühle', 'Dame', 'Brettspiele'], false);
});

test('without board games /play keeps its list and the casual block has no board tiles', function () {
    $html = $this->get(route('play'))->assertOk()->getContent();

    // Without board games a player still reads the old line: their games do come first.
    $this->actingAs(User::factory()->create())->get(route('play'))->assertOk()->assertSee('Your games come first, the one you played last on top.');

    expect(boardHooks($html, 'play-game-'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'])
        ->and($html)->not->toContain('data-test="play-board-games"')
        ->and($html)->not->toContain('data-test="casual-boards"');
});

test('a player who played a board game after chess finds the group where that game stands, still before the series games not played', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $player = User::factory()->create();
    ChessGame::factory()->finished('1-0')->create(['white_id' => $player->id, 'created_at' => now()->subDays(3)]);
    $game = app(BoardGameService::class)->start(Checkers::SLUG, User::factory()->create(), $player);
    app(BoardGameService::class)->abort($game, $player);

    $html = $this->actingAs($player)->get(route('play'))->assertOk()->getContent();

    // Checkers first (played last), nine men's morris with it; chess next; the rest in registry order.
    expect(boardHooks($html, 'play-game-'))->toBe(['checkers', 'nine-mens-morris', 'chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'])
        // The line above the list says how it is sorted, board games included, not "your games come first".
        ->and($html)->toContain('Sorted by what you played, the latest on top; the board games always stand next to chess.')
        ->and($html)->not->toContain('Your games come first');
});

test('home and the phone game chips put the board games next to chess; the desktop tabs keep their order', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();

    $html = $this->get(route('home'))->assertOk()->getContent();
    preg_match_all('/data-test="play-tile" data-game="([a-z0-9-]+)"/', $html, $tiles);

    expect($tiles[1])->toBe(['chess', 'nine-mens-morris', 'checkers', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'])
        ->and(boardHooks($html, 'mobile-'))->toContain('nine-mens-morris')
        ->and(array_slice(array_values(array_filter(boardHooks($html, 'mobile-'), fn (string $hook): bool => in_array($hook, ['nine-mens-morris', 'checkers', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'], true))), 0, 3))
        ->toBe(['nine-mens-morris', 'checkers', 'rocket-league'])
        ->and(boardHooks($html, 'game-tab-'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27'])
        ->and(array_column(ShellNavigation::current()->games(), 'slug'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'nine-mens-morris', 'checkers']);
});
