<?php

use App\Enums\BoardGameStatus;
use App\Enums\BoardInviteStatus;
use App\Models\BoardChallenge;
use App\Models\BoardGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Board\BlockspaceRules;
use App\Support\Board\BoardGameService;
use App\Support\Board\BoardRuleViolation;
use App\Support\Cards\PageCard;
use App\Support\Cards\PageCardFacts;
use App\Support\GameNames;
use App\Support\Notifications\BoardNotifications;
use App\Support\Tournaments\CasualCupNotices;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BlockspaceOn;

/*
| What leaves a Blockspace game: nothing of the hidden deal reaches a public output, and every surface calls the sides
| by the colours of their stones.
*/

beforeEach(function () {
    Queue::fake();
    BlockspaceOn::play();
});

function blockspaceSurfaceGame(string $mode = 'correspondence'): BoardGame
{
    BlockspaceOn::play();
    $game = app(BoardGameService::class)->start('blockspace', User::factory()->create(), User::factory()->create(), $mode);
    $rules = new BlockspaceRules;

    foreach (range(1, 3) as $ply) {
        $game->refresh();
        app(BoardGameService::class)->move($game, $game->player($game->turn), $rules->legalMoves($rules->deserialize($game->position))[0], $game->ply + 1);
    }

    return $game->refresh();
}

function blockspaceOgImage($test, BoardGame $game): string
{
    return Str::match('/property="og:image" content="([^"]+)"/', $test->get(route('board.show', $game))->assertOk()->getContent());
}

test('two games that differ only in what is hidden have the same public card', function () {
    $this->withoutVite();
    $this->app['env'] = 'production';
    $rules = new BlockspaceRules;
    $game = blockspaceSurfaceGame();
    $before = blockspaceOgImage($this, $game);
    $position = $rules->deserialize($game->position);

    expect($before)->toContain('v=');

    // The same board and ladder, another order of what is to come and another deal.
    $position['queue'] = strrev($position['queue']);
    $position['deck'] = strrev($position['deck']);
    $game->forceFill(['position' => $rules->serialize($position)])->save();

    expect(blockspaceOgImage($this, $game->refresh()))->toBe($before);

    // The positive control: what is public does change the card.
    $position['score']['w'] += 1;
    $game->forceFill(['position' => $rules->serialize($position)])->save();

    expect(blockspaceOgImage($this, $game->refresh()))->not->toBe($before);
});

test('the card facts carry how much is left, not what', function () {
    $game = blockspaceSurfaceGame();
    $position = (new BlockspaceRules)->deserialize($game->position);
    $facts = (new BlockspaceRules)->deserialize(PageCardFacts::board($game)['position']);

    expect(strlen($facts['queue']))->toBe(strlen($position['queue']))
        ->and($facts['queue'])->toBe(str_repeat('a', strlen($position['queue'])))
        ->and($facts['deck'])->not->toBe($position['deck'])
        ->and($facts['board'])->toBe($position['board'])
        ->and($facts['pool'])->toBe($position['pool']);
});

test('a refused move does not write the deal into its message', function () {
    $game = blockspaceSurfaceGame();
    $position = (new BlockspaceRules)->deserialize($game->position);
    $message = '';

    try {
        app(BoardGameService::class)->move($game, $game->player($game->turn), '0:a21', $game->ply + 1);
    } catch (BoardRuleViolation $violation) {
        $message = $violation->getMessage();
    }

    expect($message)->toContain('is illegal')
        ->not->toContain($position['deck'])
        ->not->toContain($position['queue']);
});

test('the tutorial has the game\'s context bar', function () {
    $this->withoutVite();

    $this->get(route('board.blockspace-practice'))->assertOk()
        ->assertSee('<span class="ctx-name">Blockspace</span>', false);
});

test('the invite link offers the colours of the stones', function () {
    Livewire::actingAs(User::factory()->create())->test('invite-link', ['game' => 'blockspace'])
        ->set('mode', BoardGame::CORRESPONDENCE)
        ->assertSeeHtml('data-state="board"')
        ->assertSee('Orange')->assertSee('Blue')
        ->assertDontSee('White')->assertDontSee('Black');
});

test('the card names the sides by their colours', function () {
    $game = blockspaceSurfaceGame();
    $card = PageCard::boardGame($game);

    expect($card->sideLabel('white'))->toBe('Orange')
        ->and($card->sideLabel('black'))->toBe('Blue')
        // Whose turn it is, as the card draws it: after Orange, Blue, Blue it is Orange again (A B B A).
        ->and($card->alt())->toContain('Orange to move')->not->toContain('White to move');

    $game->forceFill(['status' => BoardGameStatus::Finished, 'result' => '1-0'])->save();

    expect(PageCard::boardGame($game->refresh())->alt())->toContain('(Orange)')->toContain('(Blue)')
        ->not->toContain('white')->not->toContain('black');
});

test('the notifications name the colour a player has', function () {
    $game = blockspaceSurfaceGame();
    $white = $game->white;
    $black = $game->black;
    $notifications = app(BoardNotifications::class);

    $notifications->gameStarted($game, $white);
    $notifications->gameStarted($game, $black);

    $bell = fn (User $user): string => (string) json_encode($user->notifications()->get()->pluck('data'), JSON_UNESCAPED_UNICODE);

    expect($bell($white))->toContain('you play Orange, your move')
        ->and($bell($black))->toContain('you play Blue, their move');

    $challenge = BoardChallenge::query()->create(['game' => 'blockspace', 'mode' => BoardGame::CORRESPONDENCE, 'challenger_id' => $white->id, 'challenged_id' => $black->id, 'color' => 'white', 'rated' => false, 'status' => BoardInviteStatus::Pending, 'expires_at' => now()->addDay()]);
    $notifications->challengeReceived($challenge->refresh(), false);

    expect($bell($black))->toContain('you play Blue.');
});

test('the cup notice names the colour a player has', function () {
    $game = blockspaceSurfaceGame();
    $cup = Tournament::factory()->create(['name' => 'Blockspace Cup', 'game' => 'blockspace']);

    app(CasualCupNotices::class)->gameStarted($cup, $game);

    $bell = fn (User $user): string => (string) json_encode($user->notifications()->get()->pluck('data'), JSON_UNESCAPED_UNICODE);

    expect($bell($game->white))->toContain('You play Orange against')->not->toContain('You play White')
        ->and($bell($game->black))->toContain('You play Blue against')->not->toContain('You play Black');
});

test('the sides of the other board games keep their words, in German with their capitals', function () {
    app()->setLocale('de');

    expect(GameNames::sides('blockli'))->toBe(['w' => 'Weiß', 'b' => 'Schwarz'])
        ->and(GameNames::sideNotes('blockli'))->toBe(['w' => '(Weiß)', 'b' => '(Schwarz)'])
        ->and(GameNames::sideNotes('blockspace'))->toBe(['w' => '(Orange)', 'b' => '(Blau)']);
});
