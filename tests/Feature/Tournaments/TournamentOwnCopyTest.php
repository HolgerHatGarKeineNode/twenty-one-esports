<?php

use App\Enums\TournamentFormat;
use App\Games\Contracts\PlayedOnOwnCopy;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\Tournament;
use App\Models\TournamentSignup;
use App\Support\Tournaments\FormatOptions;
use App\Support\Tournaments\GameProfile;
use App\Support\Tournaments\TournamentSignups;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
 * A game played outside the site needs the player's own copy (user,
 * 2026-10-03: players signed up who did not own the game): its tournament
 * page says so above the sign-up with the platforms, and the sign-up is
 * refused until the player ticked that they own it.
 */

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

function fc26Tournament(): Tournament
{
    return openTournament([
        'name' => 'FC Friday',
        'game' => 'ea-sports-fc-26',
        'mode' => '1v1',
        'format' => TournamentFormat::SingleElimination,
        'options' => FormatOptions::defaults(GameProfile::for('ea-sports-fc-26', '1v1'))->toArray(),
        'capacity' => 8,
    ]);
}

test('an FC 26 tournament page says you need your own copy, with its platforms linked, above the sign-up; a chess one does not', function () {
    $fc = fc26Tournament();

    $html = $this->get(route('tournaments.show', $fc))->assertOk()->getContent();

    expect($html)->toContain('data-test="own-copy"', 'You need your own copy of EA Sports FC 26 to play')
        ->toContain('href="https://store.steampowered.com/app/3405690/"', 'href="https://www.nintendo.com/us/store/products/ea-sports-fc-26-switch/"')
        ->toContain('data-test="own-copy-playstation"', 'data-test="own-copy-xbox"', 'data-test="own-copy-epic"')
        ->not->toContain('data-test="own-copy-free"')
        // Above the sign-up action.
        ->and(strpos($html, 'data-test="own-copy"'))->toBeLessThan(strpos($html, 'data-test="to-signup"'));

    $chess = openTournament();

    expect($this->get(route('tournaments.show', $chess))->assertOk()->getContent())
        ->toContain('data-test="to-signup"')
        ->not->toContain('data-test="own-copy"');
});

test('Rocket League says free to play and leaves out Steam, where new players cannot get it', function () {
    $html = $this->get(route('tournaments.show', openTournament(rocketLeague: true)))->assertOk()->getContent();

    expect($html)->toContain('You need your own copy of Rocket League to play', 'data-test="own-copy-free"', 'data-test="own-copy-epic"')
        ->not->toContain('data-test="own-copy-steam"');
});

test('the sign-up to an FC 26 tournament is refused without the ownership tick and accepted with it', function () {
    $tournament = fc26Tournament();
    [$user, $signer] = keyedPlayer();

    $page = Livewire::actingAs($user)->test('pages::tournaments.signup', ['tournament' => $tournament])
        ->assertSee('data-test="own-copy"', false)
        ->assertSee('I own EA Sports FC 26 on one of these platforms')
        ->assertSee('data-test="owns-game"', false);

    // Without the tick: nothing to sign, and a signed consent from elsewhere is not stored either.
    expect($page->instance()->prepareSolo())->toBeNull();
    $page->assertSet('error', 'Tick that you own EA Sports FC 26 first.');

    $service = app(TournamentSignups::class);
    $signed = $signer->signTemplates($service->prepareSolo($tournament, $user));
    $page->call('enterSolo', json_encode($signed))
        ->assertSet('justEntered', false)
        ->assertSee('Tick that you own EA Sports FC 26 first.');
    expect(TournamentSignup::query()->where('tournament_id', $tournament->id)->count())->toBe(0);

    // With the tick: the same sign-up goes through.
    $page->set('ownsGame', true);
    $templates = $page->instance()->prepareSolo();
    expect($templates)->toBeArray()->not->toBeEmpty();
    $page->call('enterSolo', json_encode($signer->signTemplates($templates)))
        ->assertSet('justEntered', true)
        ->assertSet('error', '');
    expect(TournamentSignup::query()->where('tournament_id', $tournament->id)->count())->toBe(1);
});

test('a chess sign-up asks for no ownership tick', function () {
    $tournament = openTournament();
    [$user, $signer] = keyedPlayer();

    $page = Livewire::actingAs($user)->test('pages::tournaments.signup', ['tournament' => $tournament])
        ->assertDontSee('data-test="owns-game"', false);

    $page->call('enterSolo', json_encode($signer->signTemplates($page->instance()->prepareSolo())))
        ->assertSet('justEntered', true);
});

test('every game played outside the site names its platforms, and none played on it does', function () {
    foreach (app(GameRegistry::class)->all() as $slug => $game) {
        // Played outside: a series, or a score game that reads a game account of the player.
        $outside = $game->kind() === GameKind::Series || ($game instanceof ScoreGame && $game->accountService() !== null);

        expect($game instanceof PlayedOnOwnCopy)->toBe($outside, $slug);

        if ($game instanceof PlayedOnOwnCopy) {
            expect($game->stores())->not->toBeEmpty($slug);

            foreach ($game->stores() as $store) {
                expect($store->url === null || str_starts_with($store->url, 'https://'))->toBeTrue($slug);
            }
        }
    }
});
