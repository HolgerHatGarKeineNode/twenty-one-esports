<?php

use App\Models\User;
use App\Support\Board\BoardGameService;
use App\Support\Cards\PageCard;
use App\Support\GameNames;
use App\Support\TwentyOne\Stream\BoardScene;
use App\Support\TwentyOne\Stream\StreamTexts;
use Dom\HTMLDocument;
use Tests\Support\BlockliOn;

/*
|--------------------------------------------------------------------------
| "by DerCaddy" wherever Blockli's name shows (plan "Blockli", P3)
|--------------------------------------------------------------------------
|
| The credit comes from config('esports.board_games.games.blockli.credit')
| and sits under the name on every surface that names the game: the /games
| card, the home tile, the games menu (the hub; the phone menu's "Other
| games" lists only the main games), the
| context bar, the lobby title, the board page, the rules section and its
| games table, the cover's alt text, the drawn cards, the stream texts and
| the stream's board scene. Each surface is checked inside its own box, so
| one surface losing the credit turns its case red even while the header
| still shows it.
|
*/

beforeEach(function () {
    BlockliOn::play();
});

/** Rebuilds the registry after a config change, as a deploy does. */
function blockliCredit(?string $credit, ?string $url = null): void
{
    config(['esports.board_games.games.blockli.credit' => $credit, 'esports.board_games.games.blockli.credit_url' => $url]);
    BlockliOn::play();
}

/**
 * The text of the Blockli credit inside each box of a page, by box.
 *
 * @param  array<string, string>  $boxes  name => CSS selector of the box
 * @return array<string, string|null>
 */
function blockliCredits(string $html, array $boxes): array
{
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $found = [];

    foreach ($boxes as $name => $selector) {
        // "inner < ancestor": the box is the nearest ancestor of the inner element (the HTML parser has no :has()).
        [$inner, $ancestor] = array_pad(explode(' < ', $selector), 2, null);
        $box = $document->querySelector($inner);
        $box = $ancestor === null ? $box : $box?->closest($ancestor);
        $found[$name] = $box?->querySelector('[data-game-credit="blockli"]')?->textContent;
    }

    return $found;
}

/** @return array<string, string> the HTML surfaces: page => [boxes] */
function blockliPages(User $player): array
{
    $game = app(BoardGameService::class)->start('blockli', $player, User::factory()->create());

    return [
        'games' => test()->actingAs($player)->get(route('play'))->assertOk()->getContent(),
        'home' => test()->actingAs($player)->get(route('home'))->assertOk()->getContent(),
        'lobby' => test()->actingAs($player)->get(route('board.lobby', 'blockli'))->assertOk()->getContent(),
        'board' => test()->actingAs($player)->get(route('board.show', $game))->assertOk()->getContent(),
        'rules' => test()->actingAs($player)->get(route('rules'))->assertOk()->getContent(),
    ];
}

const BLOCKLI_BOXES = [
    'games' => ['games card' => '[data-test="play-game-blockli"]', 'games menu (desktop hub)' => '#game-hub li.hub-card[data-name*="blockli"]'],
    'home' => ['home tile' => '[data-test="game-tile"][data-game="blockli"]', 'home phone row' => '[data-test="browser-games-list"] a[data-game="blockli"]'],
    'lobby' => ['lobby title' => '[data-test="lobby-title"]', 'context bar' => '[data-test="context-bar"][data-game="blockli"]'],
    'board' => ['board page head' => '[data-test="board-game"]'],
    'rules' => ['rules section' => 'section#blockli, #blockli', 'rules games table' => 'table [data-game-cover="blockli"] < tr'],
];

test('every page that names Blockli shows "by DerCaddy" in the same box, and its cover alt carries it', function () {
    $pages = blockliPages(User::factory()->create());

    foreach (BLOCKLI_BOXES as $page => $boxes) {
        foreach (blockliCredits($pages[$page], $boxes) as $box => $credit) {
            expect($credit)->toBe('by DerCaddy', "{$page}: {$box}");
        }
    }

    expect($pages['games'])->toContain('alt="Blockli cover by DerCaddy"');
});

test('the drawn cards, the stream texts and the stream board scene carry the credit after the name', function () {
    config(['twentyone.stream.texts.rotate_minutes' => 10]);
    expect(PageCard::page('board.blockli')->alt())->toContain('Blockli by DerCaddy')
        ->and(GameNames::credited('blockli'))->toBe('Blockli by DerCaddy')
        ->and(GameNames::fullCredited('blockli', 'blitz'))->toBe('Blockli Blitz 5+3 by DerCaddy')
        // Games without a credit keep their plain name.
        ->and(GameNames::credited('chess'))->toBe('Chess')
        ->and(StreamTexts::rotate(null, 0)['summary'])->toContain('Blockli by DerCaddy')
        ->and(collect(app(BoardScene::class)->data(0, [])['boards'])->pluck('name')->all())->toContain('Blockli by DerCaddy');
});

test('a credit changed in the config changes every surface, and a new card url; a blank one removes it everywhere', function () {
    config(['twentyone.stream.texts.rotate_minutes' => 10]);
    $before = PageCard::page('board.blockli')->url();
    blockliCredit('by Somebody Else');
    $pages = blockliPages(User::factory()->create());

    foreach (BLOCKLI_BOXES as $page => $boxes) {
        foreach (blockliCredits($pages[$page], $boxes) as $box => $credit) {
            expect($credit)->toBe('by Somebody Else', "{$page}: {$box}");
        }

        expect($pages[$page])->not->toContain('by DerCaddy');
    }

    expect(PageCard::page('board.blockli')->url())->not->toBe($before)
        ->and(StreamTexts::rotate(null, 0)['summary'])->toContain('Blockli by Somebody Else');

    blockliCredit('  ');
    expect(collect(blockliPages(User::factory()->create()))->every(fn (string $html): bool => ! str_contains($html, 'data-game-credit')))->toBeTrue()
        ->and(GameNames::credited('blockli'))->toBe('Blockli');
});

test('with credit_url set the credit links there, except inside a card that already links to the game', function () {
    blockliCredit('by DerCaddy', 'https://example.org/dercaddy');
    $pages = blockliPages(User::factory()->create());
    $lobby = HTMLDocument::createFromString($pages['lobby'], LIBXML_NOERROR);
    $games = HTMLDocument::createFromString($pages['games'], LIBXML_NOERROR);

    expect($lobby->querySelector('[data-test="lobby-title"] a[data-game-credit="blockli"]')?->getAttribute('href'))->toBe('https://example.org/dercaddy')
        ->and($games->querySelector('#game-hub li.hub-card[data-name*="blockli"] [data-game-credit="blockli"]')?->tagName)->toBe('SPAN');
});

test('the lobby and the board page answer a Livewire roundtrip', function () {
    $player = User::factory()->create();
    $game = app(BoardGameService::class)->start('blockli', $player, User::factory()->create());

    Livewire\Livewire::actingAs($player)->test('pages::board.lobby', ['board' => 'blockli'])->call('$refresh')->assertOk()->assertSee('by DerCaddy');
    Livewire\Livewire::actingAs($player)->test('pages::board.show', ['boardGame' => $game])->call('$refresh')->assertOk()->assertSee('by DerCaddy');
});

test('the Blockli chat box (no channel yet) answers a roundtrip and claims nothing public on Nostr', function () {
    Livewire\Livewire::test('game-channel', ['game' => 'blockli'])->call('$refresh')->assertOk()
        ->assertSeeHtml('data-test="game-chat-off"')->assertDontSee('Public on Nostr');
});
