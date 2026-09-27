<?php

use App\Enums\LineupRole;
use App\Models\ChessGame;
use App\Models\Lineup;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Clans\ClanDraft;
use App\Support\Clans\ClanService;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
| EA Sports FC 26 and 27 on the pages of a series game: their own game page,
| the match list filter, the challenge form and the lineup builder. Every
| surface shows the game's cover, so a player sees which game is meant.
*/

function fcLineup(string $game, string $mode = '1v1'): Lineup
{
    return Lineup::factory()->game($game, $mode)->ready()->create()->load('clan');
}

test('each series game has its own page with its cover and only its own series; chess and unknown games have none', function () {
    $fc27 = SeriesMatch::factory()->create(['challenger_lineup_id' => fcLineup('ea-sports-fc-27')->id, 'challenged_lineup_id' => fcLineup('ea-sports-fc-27')->id]);
    $rl = SeriesMatch::factory()->create();

    $this->get(route('games.series', 'ea-sports-fc-27'))->assertOk()
        ->assertSee('EA Sports FC 27')
        ->assertSee('images/games/ea-sports-fc-27-480.webp', false)
        ->assertSee('Modes: 1v1, 2v2 · best of 1 / 3')
        ->assertSee(route('matches.show', $fc27), false)
        ->assertDontSee(route('matches.show', $rl), false);

    $this->get(route('games.series', 'ea-sports-fc-26'))->assertOk()->assertSee('images/games/ea-sports-fc-26-480.webp', false);
    $this->get(route('games.rocket-league'))->assertOk()->assertSee('images/games/rocket-league-382.webp', false)
        ->assertSee(route('matches.show', $rl), false)->assertDontSee(route('matches.show', $fc27), false);
    $this->get('/games/chess')->assertNotFound();
    $this->get('/games/tetris')->assertNotFound();
});

test('the games menu links every game with its cover', function () {
    $html = $this->actingAs(User::factory()->create())->get(route('home'))->assertOk()->getContent();

    foreach (['chess', 'rocket-league', 'ea-sports-fc-27', 'ea-sports-fc-26'] as $game) {
        expect($html)->toContain('data-game-cover="'.$game.'"');
    }

    expect($html)->toContain('href="'.route('games.series', 'ea-sports-fc-27').'"')
        ->toContain('href="'.route('challenges.create', ['game' => 'ea-sports-fc-26']).'"')
        ->toContain('data-test="games-menu-rocket-league"');
});

test('the match list filters by an FC game and shows a cover in every row', function () {
    $fc = SeriesMatch::factory()->create(['challenger_lineup_id' => fcLineup('ea-sports-fc-26')->id, 'challenged_lineup_id' => fcLineup('ea-sports-fc-26')->id]);
    $rl = SeriesMatch::factory()->create();
    $chess = ChessGame::factory()->create();
    $row = fn (string $key) => 'wire:key="'.$key.'"';

    Livewire::test('pages::matches.index')
        ->assertSeeHtml('data-game-cover="ea-sports-fc-26"')->assertSeeHtml('data-game-cover="chess"')
        ->call('pickGame', 'ea-sports-fc-26')->assertSet('game', 'ea-sports-fc-26')
        ->assertSeeHtml($row('m-'.$fc->id))->assertDontSeeHtml($row('m-'.$rl->id))->assertDontSeeHtml($row('c-'.$chess->id))
        ->call('pickGame', 'rocket-league')
        ->assertSeeHtml($row('m-'.$rl->id))->assertDontSeeHtml($row('m-'.$fc->id));
});

test('the challenge form offers a captain\'s FC lineup with its cover and the lengths FC allows', function () {
    $mine = fcLineup('ea-sports-fc-27');
    $theirs = fcLineup('ea-sports-fc-27');
    fcLineup('ea-sports-fc-26');

    Livewire::actingAs($mine->clan->owner)->withQueryParams(['game' => 'ea-sports-fc-27'])->test('pages::challenges.create')
        ->assertSet('lineupId', $mine->id)
        ->assertSet('bestOf', 3)
        ->assertSeeHtml('data-test="lineup-ea-sports-fc-27-1v1"')
        ->assertSeeHtml('data-test="challenge-game-cover"')
        ->assertSeeHtml('data-test="bo-1"')->assertSeeHtml('data-test="bo-3"')->assertDontSeeHtml('data-test="bo-5"')
        ->assertSee($theirs->clan->name)
        ->assertSee('EA Sports FC 27 ladder, Pre-Season');
});

test('the owner builds an FC lineup next to the Rocket League ones', function () {
    $signer = new TestSigner;
    $owner = User::factory()->withPubkey($signer->pubkey)->create();
    $service = app(ClanService::class);
    $draft = new ClanDraft('Kick-off Kings', 'KOK');
    $clan = $service->create($owner, $draft, $signer->signTemplates($service->prepareCreate($owner, $draft)));
    $seats = [$owner->id => LineupRole::Captain];
    $signed = $signer->signTemplates($service->prepareLineup($owner, $clan, 'ea-sports-fc-27', '1v1', $seats));

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])
        ->assertSeeHtml('data-test="lineups-ea-sports-fc-27"')
        ->assertSeeHtml('data-test="edit-lineup-2v2"')
        ->call('editLineup', '1v1', 'ea-sports-fc-27')
        ->assertSet('editingGame', 'ea-sports-fc-27')
        ->set("picks.{$owner->id}", 'captain')
        ->call('saveLineup', json_encode($signed))
        ->assertHasNoErrors()
        ->assertSet('editing', null);

    expect(Lineup::query()->where('clan_id', $clan->id)->sole()->only(['game', 'mode']))->toBe(['game' => 'ea-sports-fc-27', 'mode' => '1v1']);

    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])->call('editLineup', '3v3', 'ea-sports-fc-27')->assertForbidden();
    Livewire::actingAs($owner)->test('pages::clans.manage', ['clan' => $clan])->call('editLineup', 'blitz', 'chess')->assertForbidden();
});
