<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Games\GameLanding;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The Rocket League page opens on its prizes (plan "RL-Startseite", P2/P3)
|--------------------------------------------------------------------------
|
| "Ich habe auf der RL Seite nach den "Preisen" gesucht, und jetzt ist das
| doch etwas unübersichtlich finde ich." Under the head: tabs with
| "Tournaments & prizes" in orange, four start tiles with that one first and
| the only orange one; at the head of the main column the prize band (next
| tournament or "none open" with a watch, the last prize tournament with pot
| and podium, the casual cups as "no prize, Elo only") and one primary button
| to `/tournaments?game=rocket-league`. Every other game keeps its page.
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/**
 * Tournament #2 as it ran (2026-10-05): a finished Rocket League knockout
 * with a 23 310 sats pot, paid out to Industrie_KPI, AncapCrab and El
 * Presidento Ben. The bracket is a played-out four-entry knockout; only the
 * names, the game and the pot are set to match.
 *
 * @param  PayoutStatus  $status  the payouts' status (Forwarded: passed on to the next pot)
 */
function tournamentTwo(PayoutStatus $status = PayoutStatus::Paid, string $name = 'Rocket League Cup #2'): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    playOutAsDirector($tournament);

    foreach ($tournament->participants()->orderBy('seed')->get() as $i => $entry) {
        $entry->forceFill(['name' => ['Industrie_KPI', 'AncapCrab', 'El Presidento Ben', 'Mempool Max'][$i]])->save();
    }

    $tournament->forceFill(['name' => $name, 'game' => 'rocket-league', 'mode' => '3v3', 'published_at' => now(),
        'pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 23_310, 'starts_at' => now()->subDays(2)])->save();

    foreach ([1 => 11_655, 2 => 6_993, 3 => 4_662] as $place => $sats) {
        $pubkey = str_pad((string) $place, 64, 'a');
        TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'pubkey' => $pubkey, 'name' => "Place {$place}", 'place' => $place,
            'amount_sats' => $sats, 'idempotency_key' => TournamentPayout::keyFor($tournament->id, $pubkey, $place), 'status' => $status]);
    }

    return $tournament->refresh();
}

/** The markup of one data-test element up to the next one named. */
function between(string $html, string $from, string $to): string
{
    return str($html)->after($from)->before($to)->toString();
}

test('GameLanding: the last finished prize tournament of the game, its podium, and the prizes paid out, Forwarded not counted', function () {
    $two = tournamentTwo();
    // Newer, but not a prize tournament of this game: a finished casual cup with a pot, a chess one, one without a pot.
    Tournament::factory()->rocketLeague()->create(['name' => 'Cup', 'status' => TournamentStatus::Finished, 'cup_series' => 'rocket-league-eu', 'cup_number' => 3, 'pot_source' => Tournament::POT_LEAGUE, 'starts_at' => now()->subDay()]);
    Tournament::factory()->create(['name' => 'Chess', 'status' => TournamentStatus::Finished, 'pot_source' => Tournament::POT_LEAGUE, 'starts_at' => now()->subDay()]);
    Tournament::factory()->rocketLeague()->create(['name' => 'No pot', 'status' => TournamentStatus::Finished, 'starts_at' => now()->subDay()]);
    // A forwarded prize in another finished RL tournament is not paid.
    tournamentTwo(PayoutStatus::Forwarded, 'Rocket League Cup #1')->forceFill(['starts_at' => now()->subDays(9)])->save();

    $landing = app(GameLanding::class);

    expect($landing->lastFinishedTournament('rocket-league')?->id)->toBe($two->id)
        ->and($landing->paidSats('rocket-league'))->toBe(23_310)
        ->and($landing->paidSats('chess'))->toBe(0)
        ->and($landing->podium($two))->toBe([
            ['place' => 1, 'names' => ['Industrie_KPI']],
            ['place' => 2, 'names' => ['AncapCrab']],
            ['place' => 3, 'names' => ['El Presidento Ben', 'Mempool Max']],
        ]);
});

test('the band shows Tournament #2 with its 23 310 sats pot and podium, and its button leads to the Rocket League tournaments', function () {
    tournamentTwo();
    Tournament::factory()->rocketLeague()->create(['name' => 'Newer Casual Cup', 'status' => TournamentStatus::Finished, 'cup_series' => 'rocket-league-eu', 'cup_number' => 3, 'pot_source' => Tournament::POT_LEAGUE, 'starts_at' => now()->subDay()]);

    $html = (string) $this->get(route('games.rocket-league'))->assertOk()->getContent();
    $band = between($html, 'data-test="prize-band"', '</section>'."\n".'                    <div id="game-cups"');
    $last = between($html, 'data-test="prize-last"', '</article>');
    $sats = '23,310';

    expect($last)->toContain('Rocket League Cup #2')
        ->not->toContain('Newer Casual Cup')
        ->toContain('data-test="prize-last-pot">'.$sats.'</b>')
        ->toContain('data-test="prize-last-paid">'.$sats.'</b>')
        ->and(strpos($last, 'Industrie_KPI'))->toBeLessThan(strpos($last, 'AncapCrab'))
        ->and(strpos($last, 'AncapCrab'))->toBeLessThan(strpos($last, 'El Presidento Ben'))
        // The casual cups never look like a prize tournament.
        ->and(between($html, 'data-test="prize-cups"', '</article>'))->toContain('No prize, Elo only')->not->toContain('prize-chip')->not->toMatch('/\d[\x{00A0} ]?sats/u')
        // One primary button, to this game's tournaments, never the global list.
        ->and($html)->toMatch('#<a href="'.preg_quote(e(route('tournaments.index', ['game' => 'rocket-league'])), '#').'" class="[^"]*\bbg-btc\b[^"]*" data-test="prize-band-all"#')
        ->and($band)->toContain('All Rocket League tournaments');
});

test('the band without an open or a finished prize tournament says so, and a forwarded prize is no prize paid', function () {
    $html = (string) $this->actingAs(User::factory()->create())->get(route('games.rocket-league'))->assertOk()->getContent();

    expect($html)->toContain('data-test="next-tournament-empty"')
        ->toContain('data-test="prize-watch"')
        ->toContain('data-test="prize-last-empty"')
        ->not->toContain('data-test="start-tile-paid"');

    tournamentTwo(PayoutStatus::Forwarded);
    $html = (string) $this->get(route('games.rocket-league'))->assertOk()->getContent();

    expect($html)->toContain('data-test="prize-last-pot"')
        ->not->toContain('data-test="prize-last-paid"')
        ->not->toContain('data-test="start-tile-paid"');
});

test('an open Rocket League tournament stands in the band as its poster with its pot', function () {
    $open = Tournament::factory()->rocketLeague()->create(['name' => 'RL Friday Open', 'status' => TournamentStatus::Signup, 'published_at' => now(), 'slug' => 'rl-friday-open',
        'signup_closes_at' => now()->addDays(2), 'starts_at' => now()->addDays(3)]);

    $html = (string) $this->get(route('games.rocket-league'))->assertOk()->getContent();

    expect(between($html, 'data-test="prize-band"', 'data-test="prize-last"'))->toContain('data-test="next-tournament"')->toContain('RL Friday Open')
        ->and($open->id)->toBeInt();
});

test('the ways to play name the prizes paid and lead to the Rocket League tournaments; the game bar\'s Tournaments tab too (template T1, GameSeries.dc.html)', function () {
    tournamentTwo();
    $html = (string) $this->get(route('games.rocket-league'))->assertOk()->getContent();
    $filtered = e(route('tournaments.index', ['game' => 'rocket-league']));

    expect(between($html, 'data-test="play"', '</section>'))->toContain('data-test="play-tournaments"')
        ->and(between($html, 'data-test="play-tournaments"', '</a>'))->toContain('23,310 sats won')->toContain('Champion: Industrie_KPI')
        ->and($html)->toMatch('#<a\s+href="'.preg_quote($filtered, '#').'"[^>]*data-test="play-tournaments"#')
        ->and($html)->toMatch('#<a href="'.preg_quote($filtered, '#').'"[^>]*class="gs-tab"[^>]*data-test="ctx-tournaments"#')
        // One game bar, no second tab row and no start tiles on the page; the prize band follows the ways to play.
        ->and($html)->not->toContain('data-test="game-sections"')
        ->and($html)->not->toContain('data-test="start-tiles"')
        ->and(strpos($html, 'data-test="play"'))->toBeLessThan(strpos($html, 'data-test="prize-band"'));

    Livewire::test('pages::games.series', ['slug' => 'rocket-league'])->call('$refresh')->assertOk()->assertSeeHtml('data-test="prize-band"');
});

test('only Rocket League gets the new layout: the other series games keep the poster block and have no tabs, tiles or band', function (string $slug) {
    tournamentTwo();
    $html = (string) $this->get(route('games.series', $slug))->assertOk()->getContent();

    expect($html)->toContain('data-test="game-next-tournament"')
        ->not->toContain('data-test="game-start"')
        ->not->toContain('data-test="ctx-prizes"')
        ->not->toContain('data-test="start-tiles"')
        ->not->toContain('data-test="prize-band"')
        ->toContain('href="'.route('tournaments.index').'" class');

    Livewire::test('pages::games.series', ['slug' => $slug])->call('$refresh')->assertOk();
})->with(['ea-sports-fc-26', 'ea-sports-fc-27']);
