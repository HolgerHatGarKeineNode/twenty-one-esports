<?php

/*
|--------------------------------------------------------------------------
| The board games on /play and home (plan "Mühle und Dame", P7)
|--------------------------------------------------------------------------
|
| „Bin ich blind? https://esports.einundzwanzig.space/play ich sehe Dame und
| Mühle nicht?" — they were missing from the casual block at the top. They
| are one "Board games" group and two tiles in the casual block. Since
| 2026-10-03 the group stands at the very end again, on home and in the
| phone's game chips too (user: „bitte stelle [Mühle, Dame] überall ganz
| nach hinten in der Reihenfolge, weil es die schwächsten Games sind").
|
*/

use App\Games\Blockli;
use App\Games\Checkers;
use App\Games\NineMensMorris;
use App\Models\ChessGame;
use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Navigation\ShellNavigation;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BlockliOn;
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

test('/play shows the board games as one group at the end, and as tiles to their lobbies in the casual block', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();

    $html = $this->get(route('play'))->assertOk()
        ->assertSeeInOrder(['data-test="casual-play"', 'data-test="casual-boards"', 'data-test="play-game-chess"', 'data-test="play-game-rocket-league"', 'data-test="play-board-games"', 'Board games', 'data-test="play-game-nine-mens-morris"', 'data-test="play-game-checkers"'], false)
        ->assertSee('href="'.route('board.lobby', NineMensMorris::SLUG).'" data-test="casual-board-nine-mens-morris"', false)
        ->assertSee('href="'.route('board.lobby', Checkers::SLUG).'" data-test="casual-board-checkers"', false)
        ->getContent();

    expect(boardHooks($html, 'play-game-'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers'])
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

    expect(boardHooks($html, 'play-game-'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2'])
        ->and($html)->not->toContain('data-test="play-board-games"')
        ->and($html)->not->toContain('data-test="casual-boards"');
});

test('a player who played a board game after chess still finds the board games at the end', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    $player = User::factory()->create();
    ChessGame::factory()->finished('1-0')->create(['white_id' => $player->id, 'created_at' => now()->subDays(3)]);
    $game = app(BoardGameService::class)->start(Checkers::SLUG, User::factory()->create(), $player);
    app(BoardGameService::class)->abort($game, $player);

    $html = $this->actingAs($player)->get(route('play'))->assertOk()->getContent();

    // Chess first (played), the rest in registry order, the board games last although Checkers was played last.
    expect(boardHooks($html, 'play-game-'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers'])
        // The line above the list says how it is sorted, board games included, not "your games come first".
        ->and($html)->toContain('Sorted by what you played, the latest on top; the board games always come last.')
        ->and($html)->not->toContain('Your games come first');
});

test('home and the phone game chips put the board games at the end; the desktop tabs keep their order', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();

    $html = $this->get(route('home'))->assertOk()->getContent();
    preg_match_all('/data-test="play-tile" data-game="([a-z0-9-]+)"/', $html, $tiles);

    expect($tiles[1])->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers'])
        ->and(boardHooks($html, 'mobile-'))->toContain('nine-mens-morris')
        ->and(array_values(array_filter(boardHooks($html, 'mobile-'), fn (string $hook): bool => in_array($hook, ['nine-mens-morris', 'checkers', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2'], true))))
        ->toBe(['rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers'])
        ->and(boardHooks($html, 'game-tab-'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27'])
        ->and(array_column(ShellNavigation::current()->games(), 'slug'))->toBe(['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26', 'age-of-empires-2', 'nine-mens-morris', 'checkers']);
});

test('the board game tiles on home play correspondence: "Play correspondence" is the one button, no blitz, no second link (user, 2026-10-07)', function () {
    NineMensMorrisOn::play();
    CheckersGame::play();
    BlockliOn::play();

    $html = $this->actingAs(User::factory()->create())->withSession(['locale' => 'de'])->get(route('home'))->assertOk()->getContent();

    foreach ([NineMensMorris::SLUG, Checkers::SLUG, Blockli::SLUG] as $slug) {
        expect(preg_match('~data-game="'.$slug.'".*?</li>~s', $html, $tile))->toBe(1)
            ->and($tile[0])->toContain('Fernpartie spielen')
            ->and($tile[0])->not->toContain('data-test="play-daily"')
            ->and($tile[0])->not->toContain('Blitz');
    }
});
