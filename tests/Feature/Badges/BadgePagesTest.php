<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Badges\ProfileBadges;
use App\Support\Cards\SharePosts;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/**
 * Where the P11 UI lives and how it is reached: the "Badges and sharing"
 * settings tab (account menu, every settings tab bar), the rank badges on
 * the player page, the share button on a won tournament, the links after a
 * rated game and a rated series. Plus the Livewire roundtrips of both
 * signed actions.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->season = openSeason(['slug' => 'pre-season']);
    $this->signer = new TestSigner;
    $this->user = User::factory()->withPubkey($this->signer->pubkey)->create(['name' => 'satsjäger']);
    $this->moments = shareMoments($this->user, $this->season);
});

test('the badges tab lists every moment with a share button and the rank badges, and survives a roundtrip', function () {
    $this->actingAs($this->user)->get(route('settings.badges'))
        ->assertOk()
        ->assertSeeInOrder(['data-test="share-moment" data-type="wrapped"', 'data-type="rank-up"', 'data-type="rank-up"', 'data-type="block"', 'data-type="block"', 'data-type="tournament"'], false)
        ->assertSee('data-test="share-button"', false)
        ->assertSee('data-test="rank-badge" data-tier="gold-2"', false)
        ->assertSee('data-test="badge-show"', false)
        ->assertSee('/cards/en/wrapped/pre-season/'.$this->user->npub.'-story.png', false);

    Livewire::actingAs($this->user)->test('pages::settings.badges')->call('$refresh')->assertOk();
});

test('the badges tab is linked from the account menu and from every settings tab bar', function () {
    $home = $this->actingAs($this->user)->get(route('dashboard'))->assertOk();
    $home->assertSee('data-test="account-badges"', false)->assertSee('data-test="mobile-badges"', false);

    foreach (['gaming.edit', 'settings.chess', 'settings.opponents', 'settings.badges'] as $route) {
        $this->actingAs($this->user)->get(route($route))->assertOk()->assertSee('data-test="settings-badges-tab"', false);
    }
});

test('the player page shows the rank badges to everyone, the profile button and share link only on the own page', function () {
    $this->actingAs($this->user)->get(route('players.show', $this->user->npub))
        ->assertOk()->assertSee('data-test="rank-badges"', false)->assertSee('data-test="badge-show"', false)->assertSee('data-test="to-share"', false);

    $this->actingAs($this->moments['opponent'])->get(route('players.show', $this->user->npub))
        ->assertOk()->assertSee('data-test="rank-badge" data-tier="gold-2"', false)->assertDontSee('data-test="badge-show"', false)->assertDontSee('data-test="to-share"', false);

    auth()->logout();
    $this->get(route('players.show', $this->user->npub))->assertOk()->assertSee('data-test="rank-badge"', false);
});

test('a finished tournament shows its winner, and the share button only to the winners', function () {
    $url = route('tournaments.show', $this->moments['tournament']);

    $this->actingAs($this->user)->get($url)->assertOk()->assertSee('data-test="tournament-winner"', false)->assertSee('data-test="share-button" data-type="tournament"', false);
    $this->actingAs($this->moments['opponent'])->get($url)->assertOk()->assertSee('data-test="tournament-winner"', false)->assertDontSee('data-test="share-button"', false);

    $this->moments['tournament']->forceFill(['status' => TournamentStatus::Running])->save();
    $this->actingAs($this->user)->get($url)->assertOk()->assertDontSee('data-test="tournament-winner"', false);
});

test('the end of a rated game links to the share cards', function () {
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $this->user->id]);

    $this->actingAs($this->user)->get(route('games.show', $game))->assertOk()
        ->assertSee('data-test="game-done-share"', false)
        ->assertSee(route('settings.badges').'#share', false);

    // A casual game has no badge or block to share.
    $casual = ChessGame::factory()->finished('1-0')->create(['white_id' => $this->user->id]);
    $this->actingAs($this->user)->get(route('games.show', $casual))->assertOk()->assertDontSee('data-test="game-done-share"', false);
});

test('a finished rated series links its players to the share cards, a casual one does not', function () {
    $series = SeriesMatch::factory()->accepted()->create(['status' => SeriesStatus::Confirmed, 'rated' => true, 'winner' => 'challenger']);
    $captain = $series->lineup('challenger')->clan->owner;

    $this->actingAs($captain)->get(route('matches.show', $series))->assertOk()->assertSee('data-test="match-share"', false);
    $this->actingAs($this->user)->get(route('matches.show', $series))->assertOk()->assertDontSee('data-test="match-share"', false);

    $series->forceFill(['rated' => false])->save();
    $this->actingAs($captain)->get(route('matches.show', $series))->assertOk()->assertDontSee('data-test="match-share"', false);
});

test('"Show on my Nostr profile" and "Post on Nostr" go through their Livewire components', function () {
    $badge = $this->moments['versions'][1]->badge;
    $list = $this->signer->sign(ProfileBadges::KIND, [['a', '30009:'.str_repeat('a', 64).':bravery'], ['e', str_repeat('1', 64)]], '', now()->getTimestamp() - 60);

    $component = Livewire::actingAs($this->user)->test('rank-badges', ['player' => $this->user]);
    $prepared = $component->call('prepareProfile', $badge->id, json_encode([$list]), true)->effects['returns'][0];
    $signed = $this->signer->sign($prepared['template']['kind'], $prepared['template']['tags'], $prepared['template']['content']);
    $component->call('submitProfile', $badge->id, json_encode([$list]), true, json_encode($signed))->assertHasNoErrors();

    expect($prepared['kept'])->toBe(1)
        ->and(NostrEvent::query()->where('kind', 10008)->where('event_id', $signed['id'])->exists())->toBeTrue();
    $component->call('$refresh')->assertSee('data-test="badge-listed"', false);

    $share = Livewire::actingAs($this->user)->test('share-button', ['type' => 'block', 'moment' => (string) $this->moments['block']->id]);
    $template = $share->call('prepareShare')->effects['returns'][0];
    $note = $this->signer->sign($template['kind'], $template['tags'], $template['content']);
    $share->call('submitShare', json_encode($note))->assertHasNoErrors();

    expect(NostrEvent::query()->where('kind', SharePosts::KIND)->where('event_id', $note['id'])->exists())->toBeTrue();

    // Somebody else's moment renders nothing and cannot be prepared.
    Livewire::actingAs($this->moments['opponent'])->test('share-button', ['type' => 'block', 'moment' => (string) $this->moments['block']->id])
        ->assertDontSee('data-test="share-button"', false)
        ->call('prepareShare')->assertHasErrors('share');
});
