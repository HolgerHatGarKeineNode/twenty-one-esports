<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Tournaments\TournamentChampion;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The champion moment on a finished tournament page (user, 2026-10-03)
|--------------------------------------------------------------------------
|
| "Das mini Ding als Stolz-Moment für den Sieger??? NEINN!!!!! Das muss
| krasser werden!!!!" A finished tournament opens on its champion: trophy,
| the avatar large, the name in display type, "Champion · <tournament>",
| the podium of places 1 to 3, the champion's record and path, the prize
| when it is paid, "Share the win" and "Results". The winner reads "You
| won!" and shares the win on Nostr with the champion card.
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/** A chess knockout of four played out by the better seeds: "Player 1" wins, "Player 2" is second. */
function championMomentTournament(): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);
    $tournament->forceFill(['name' => 'Friday Blitz Cup', 'published_at' => now()])->save();

    return $tournament->refresh();
}

/** The champion hero's markup alone. */
function championHero(string $html): string
{
    $start = strpos($html, 'data-test="champion-hero"');
    expect($start)->not->toBeFalse();
    $open = strrpos(substr($html, 0, $start), '<section');
    $end = strpos($html, '<!-- /champion-hero -->', $start);
    expect($end)->not->toBeFalse();

    return substr($html, $open, $end - $open);
}

test('a visitor sees the champion first: trophy, name, "Champion · <tournament>", the podium, the record and path, and the way to the results', function () {
    $tournament = championMomentTournament();
    $champion = app(TournamentChampion::class)->of($tournament);
    $html = $this->get(route('tournaments.show', $tournament))->assertOk()->getContent();
    $hero = championHero($html);

    expect($champion->name)->toBe('Player 1')
        // At the very top: before the tournament's own hero, and the tiny "Finished. Winner" line under the button is gone.
        ->and(strpos($html, 'data-test="champion-hero"'))->toBeLessThan(strpos($html, 'data-test="tournament-hero"'))
        ->and($html)->not->toContain('data-test="cta-note"')
        ->and($hero)->toContain('data-test="tournament-winner"')
        ->toContain('data-test="champion-name"')
        ->toContain('Champion · Friday Blitz Cup')
        ->toContain('Player 1')
        ->toContain('data-test="champion-trophy"')
        ->toContain('data-test="champion-podium"')
        ->toContain('data-test="podium-1"')
        ->toContain('data-test="podium-2"')
        ->toContain('data-test="podium-3"')
        // Two rounds, two wins, the path names whom they beat.
        ->toContain('data-test="champion-record"')
        ->toContain('2 wins')
        ->toContain('data-test="champion-path"')
        ->toContain('Player 2')
        ->toContain('href="#bracket"')
        ->toContain('data-test="champion-results"')
        ->toContain('data-test="champion-share"')
        ->toContain('Share the win')
        ->not->toContain('You won!')
        ->not->toContain('data-test="champion-prize"');
});

test('the winner reads "You won!" and shares the win on Nostr; a beaten player reads their place', function () {
    $tournament = championMomentTournament();
    $champion = app(TournamentChampion::class)->of($tournament);
    $winner = User::query()->findOrFail($champion->user_id);
    $hero = championHero($this->actingAs($winner)->get(route('tournaments.show', $tournament))->assertOk()->getContent());

    expect($hero)->toContain('You won!')
        ->toContain('data-viewer="won"')
        ->toContain('data-test="share-button" data-type="tournament"')
        ->toContain('Share the win');

    $second = User::query()->findOrFail($tournament->participants()->where('name', 'Player 2')->sole()->user_id);
    $other = championHero($this->actingAs($second)->get(route('tournaments.show', $tournament))->assertOk()->getContent());

    expect($other)->toContain('data-viewer="placed"')
        ->toContain('You finished in place 2')
        ->not->toContain('You won!')
        ->not->toContain('data-test="share-button"');
});

test('the prize is named in sats once the league paid it', function () {
    $tournament = championMomentTournament();
    $winner = app(TournamentChampion::class)->of($tournament)->user;
    $payout = TournamentPayout::query()->create([
        'tournament_id' => $tournament->id, 'pubkey' => $winner->pubkey, 'name' => 'Player 1', 'place' => 1, 'amount_sats' => 21000,
        'idempotency_key' => TournamentPayout::keyFor($tournament->id, $winner->pubkey, 1), 'status' => PayoutStatus::Open,
    ]);

    expect(championHero($this->get(route('tournaments.show', $tournament))->getContent()))->not->toContain('data-test="champion-prize"');

    $payout->forceFill(['status' => PayoutStatus::Paid])->save();

    expect(championHero($this->get(route('tournaments.show', $tournament))->getContent()))->toContain('data-test="champion-prize"')->toContain('21,000');
});

test('the champion moment survives a Livewire roundtrip, for the winner and a guest', function () {
    $tournament = championMomentTournament();
    $winner = User::query()->findOrFail(app(TournamentChampion::class)->of($tournament)->user_id);

    // The guest first: actingAs() stays for every later test() of this case.
    Livewire::test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()->assertSeeHtml('data-test="champion-share"');
    Livewire::actingAs($winner)->test('pages::tournaments.show', ['tournament' => $tournament])
        ->call('$refresh')->assertOk()->assertSeeHtml('data-test="champion-hero"')->assertSee('You won!');
});

test('the German page says it in German', function () {
    $tournament = championMomentTournament();
    $hero = championHero($this->get(route('tournaments.show', ['tournament' => $tournament, 'lang' => 'de']))->assertOk()->getContent());

    expect($hero)->toContain('Champion · Friday Blitz Cup')->toContain('Sieg teilen')->toContain('Ergebnisse')->not->toContain('Share the win');
});
