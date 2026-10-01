<?php

use App\Enums\InviteLinkType;
use App\Games\Blockfill;
use App\Games\GameRegistry;
use App\Games\NineMensMorris;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\InviteLink;
use App\Models\StackerRun;
use App\Models\User;
use App\Support\Invites\InviteGames;
use App\Support\Invites\InviteLinks;
use App\Support\Navigation\ShellNavigation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BlockfillOn;
use Tests\Support\FixtureBoardGame;
use Tests\Support\NineMensMorrisOn;

/*
 * The invite follows the game the player is in (the shell's active game,
 * the same "game opened last" as the context bar): a versus game gets an
 * invite to that game, a score game such as Blockfill a "beat my time"
 * challenge. The picker on /invite lists every game of the registry.
 */

beforeEach(function () {
    Queue::fake();
    Http::fake(fn () => Http::response([]));
});

test('in a Blockfill context the invite is a beat-my-time challenge for Blockfill, never chess', function () {
    BlockfillOn::play();
    $me = User::factory()->create(['name' => 'satsjäger']);
    StackerRun::factory()->for($me)->verified(5000)->create();

    $this->actingAs($me)->withSession([ShellNavigation::SESSION_KEY => Blockfill::SLUG])->get(route('dashboard'))->assertOk()
        ->assertSeeHtml('data-test="invite-module" data-place="me" data-state="score" data-game="blockfill"')
        ->assertDontSee('A daily chess game with whoever opens the link');

    Livewire::actingAs($me)->test('invite-link', ['game' => Blockfill::SLUG])->call('createLink');

    $link = InviteLink::query()->sole();
    expect($link->type)->toBe(InviteLinkType::Score)
        ->and($link->option('game'))->toBe(Blockfill::SLUG);

    $this->actingAs(User::factory()->create())->get($link->url())->assertOk()
        ->assertSee('satsjäger challenges you to beat 1:23.333 at Blockfill')
        ->assertSeeHtml('href="'.route('stacker.play').'"')
        ->assertSeeHtml('data-game-cover="blockfill"')
        ->assertDontSee('Chess board in the start position')
        ->assertDontSeeHtml('data-test="accept-invite"');

    // The preview card is Blockfill's too: its copy names the time, not a seat at a chess board.
    $this->get(route('invites.card', ['code' => $link->code, 'format' => 'wide']))->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs($me)->withSession([ShellNavigation::SESSION_KEY => Blockfill::SLUG])->get(route('invites.create'))->assertOk()
        ->assertSeeHtml('data-test="invite-picker" data-selected="blockfill"')
        ->assertSeeHtml('data-test="invite-panel" data-game="blockfill" data-kind="score"')
        ->assertSee('Your best this week: 1:23.333');
});

test('in a morris context the invite is a nine men\'s morris invite, and taking it starts a morris game', function () {
    NineMensMorrisOn::play();
    $me = User::factory()->create(['name' => 'satsjäger']);

    $this->actingAs($me)->withSession([ShellNavigation::SESSION_KEY => NineMensMorris::SLUG])->get(route('dashboard'))->assertOk()
        ->assertSeeHtml('data-test="invite-module" data-place="me" data-state="board" data-game="nine-mens-morris"');

    Livewire::actingAs($me)->test('invite-link', ['game' => NineMensMorris::SLUG])->call('createLink');

    $link = InviteLink::query()->sole();
    expect($link->type)->toBe(InviteLinkType::Board)
        ->and($link->option('game'))->toBe(NineMensMorris::SLUG)
        ->and($link->option('mode'))->toBe(BoardGame::CORRESPONDENCE);

    $friend = User::factory()->create();
    $this->actingAs($friend)->get($link->url())->assertOk()
        ->assertSee("satsjäger challenges you to Nine Men's Morris")
        ->assertSeeHtml('data-game-cover="nine-mens-morris"')
        ->assertDontSee('Chess board in the start position');

    $made = app(InviteLinks::class)->accept($link, $friend);

    expect($made)->toBeInstanceOf(BoardGame::class)
        ->and($made->game)->toBe(NineMensMorris::SLUG)
        ->and($made->mode)->toBe(BoardGame::CORRESPONDENCE)
        ->and($made->rated)->toBeFalse()
        ->and(ChessGame::query()->count())->toBe(0);

    $this->actingAs($friend)->get($link->url())->assertOk()->assertSeeHtml('href="'.route('board.show', $made).'"');
});

test('the picker lists every invitable registered game, a made-up one included, with the context game picked', function () {
    BlockfillOn::play();
    FixtureBoardGame::play();
    $registry = app(GameRegistry::class);
    $games = app(InviteGames::class)->all();

    expect(array_keys($games))->toBe(array_keys($registry->all()))
        ->and($games)->toHaveKey(FixtureBoardGame::SLUG);

    $html = $this->actingAs(User::factory()->create())->withSession([ShellNavigation::SESSION_KEY => FixtureBoardGame::SLUG])
        ->get(route('invites.create'))->assertOk()->getContent();

    foreach (array_keys($registry->all()) as $slug) {
        expect($html)->toContain('data-invite-game="'.$slug.'"');
    }

    expect($html)->toContain('data-test="invite-picker" data-selected="fixture-board"')
        ->and($html)->toContain('data-invite-game="blockfill" data-kind="score"');

    // ?game= wins over the context, an unknown game falls back to it.
    $this->get(route('invites.create', ['game' => 'chess']))->assertSeeHtml('data-selected="chess"');
    $this->get(route('invites.create', ['game' => 'no-such-game']))->assertSeeHtml('data-selected="fixture-board"');
});

test('a chess invite made before the picker still opens and starts its daily game', function () {
    $inviter = User::factory()->create(['name' => 'satsjäger']);
    $code = InviteLink::newCode();

    // The row exactly as P6b stored it: type `daily`, options with the colour only.
    DB::table('invite_links')->insert([
        'code' => $code, 'type' => 'daily', 'inviter_id' => $inviter->id, 'options' => json_encode(['color' => 'white']),
        'max_uses' => 1, 'uses' => 0, 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $friend = User::factory()->create();
    $this->actingAs($friend)->get('/i/'.$code)->assertOk()
        ->assertSee('satsjäger challenges you to daily chess')
        ->assertSee('Chess board in the start position');
    $this->get('/i/'.$code.'/card-wide.png')->assertOk();

    $game = app(InviteLinks::class)->accept(InviteLink::query()->where('code', $code)->sole(), $friend);

    expect($game)->toBeInstanceOf(ChessGame::class)
        ->and($game->white_id)->toBe($inviter->id)
        ->and($game->mode)->toBe(ChessGame::CORRESPONDENCE);
});
