<?php

use App\Enums\ClanRole;
use App\Enums\InviteLinkType;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\InviteLink;
use App\Models\SeriesMatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Where "Invite a friend by link" sits (engagement placement): the top of
 * /chess and of every series game page, the own profile, the own clan, an
 * empty ladder and a finished game. A guest gets the login state, a series
 * page gives a captain the clan's join link.
 */

beforeEach(function () {
    Http::fake(fn () => Http::response([]));
});

dataset('game pages', [
    'chess' => ['/chess', 'daily'],
    'rocket league' => ['/games/rocket-league', 'no-clan'],
    'ea sports fc 26' => ['/games/ea-sports-fc-26', 'no-clan'],
    'ea sports fc 27' => ['/games/ea-sports-fc-27', 'no-clan'],
]);

test('every game page carries the invite module, for a player and as a guest login state', function (string $path, string $state) {
    $this->get($path)->assertOk()
        ->assertSeeHtml('data-state="guest"')
        ->assertSeeHtml('data-test="invite-login"');

    $this->actingAs(User::factory()->create())->get($path)->assertOk()
        ->assertSeeHtml('data-state="'.$state.'"')
        ->assertDontSeeHtml('data-test="invite-login"');
})->with('game pages');

test('a series game page offers its captain the clan join link, and makes it', function () {
    $clan = Clan::factory()->create(['name' => 'Laser Eyes']);

    $this->actingAs($clan->owner)->get('/games/rocket-league')->assertOk()
        ->assertSeeHtml('data-state="clan"')
        ->assertSee('Invite a friend to Laser Eyes');

    Livewire::actingAs($clan->owner)->test('invite-link', ['game' => 'rocket-league'])
        ->call('createLink')
        ->assertRedirect(InviteLink::query()->sole()->url());

    expect(InviteLink::query()->sole()->only(['type', 'clan_id', 'max_uses']))->toBe(['type' => InviteLinkType::Clan, 'clan_id' => $clan->id, 'max_uses' => null]);
});

test('a guest and a player of a clan they do not captain make no link', function () {
    Livewire::test('invite-link')->assertSeeHtml('data-state="guest"')->call('createLink')->assertForbidden();

    $clan = Clan::factory()->create();
    $member = User::factory()->create();
    ClanMember::query()->create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'joined_at' => now()]);

    Livewire::actingAs($member)->test('invite-link', ['game' => 'rocket-league'])
        ->assertSeeHtml('data-state="member"')
        ->call('createLink')
        ->assertForbidden();

    expect(InviteLink::query()->count())->toBe(0);
});

test('the own clan page and the own profile carry the invite, other people\'s do not', function () {
    $clan = Clan::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($clan->owner)->get(route('clans.show', $clan))->assertOk()->assertSeeHtml('data-place="clan"');
    $this->actingAs($stranger)->get(route('clans.show', $clan))->assertOk()->assertDontSeeHtml('data-place="clan"');

    $this->actingAs($stranger)->get(route('players.show', $stranger->npub))->assertOk()->assertSeeHtml('data-place="profile"');
    $this->actingAs($stranger)->get(route('players.show', $clan->owner->npub))->assertOk()->assertDontSeeHtml('data-place="profile"');
});

test('a finished game offers its players a rematch and the invite, not the spectators', function () {
    $game = ChessGame::factory()->finished('1-0')->create();

    $this->actingAs($game->white)->get(route('games.show', $game))->assertOk()
        ->assertSeeHtml('data-test="play-again"')
        ->assertSeeHtml('href="'.e(route('chess.challenge', ['to' => $game->black->npub])).'"')
        ->assertSeeHtml('data-place="game-done"');

    $this->actingAs(User::factory()->create())->get(route('games.show', $game))->assertOk()
        ->assertDontSeeHtml('data-test="play-again"')
        ->assertDontSeeHtml('data-place="game-done"');
});

test('a finished series offers its captains the next challenge against the same lineup', function () {
    $match = SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Confirmed, 'winner' => 'challenger', 'finished_at' => now()]);
    $captain = $match->challengerLineup->clan->owner;

    $this->actingAs($captain)->get(route('matches.room', $match))->assertOk()
        ->assertSeeHtml('data-test="series-again"')
        ->assertSeeHtml('href="'.e(route('challenges.create', ['lineup' => $match->challenger_lineup_id, 'to' => $match->challenged_lineup_id, 'game' => $match->game])).'"');
});

test('an empty ladder invites friends', function () {
    $this->actingAs(User::factory()->create())->get(route('ladder.show', ['chess', 'blitz']).'?pool=casual')->assertOk()
        ->assertSeeHtml('data-test="ladder-empty-invite"')
        ->assertSeeHtml('data-place="ladder"');
});
