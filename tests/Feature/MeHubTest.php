<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentResultsMode;
use App\Models\TournamentMatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * "Your page", /me (P30): the player's own hub. A brand-new player gets the
 * four first steps instead of empty parts; a player with something going
 * sees what needs them (the dock's own list), what runs and comes, their
 * ratings, their last results with faces and scores, their clan, Looking
 * to play and the settings. A tournament match carries P18's countdown.
 * The header's "Your page" opens it; guests are sent to log in.
 */

test('a guest is sent to log in', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a brand-new player sees the three first steps first, and every part says what fills it', function () {
    $html = $this->actingAs(User::factory()->create(['name' => 'Newbie']))->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain('Coming soon')
        ->toContain('Newbie')
        ->toContain('data-test="me-steps"')
        ->toContain(__('Your first steps'))
        ->toContain(__(':done of :total done', ['done' => 0, 'total' => 3]))
        ->and(substr_count($html, 'data-done="false"'))->toBe(3)
        // Gamer tags are optional (P51): no step asks for them.
        ->and($html)->not->toContain('data-step="tags"')
        ->not->toContain(__('Add your gamer tags'))
        ->and(strpos($html, 'data-test="me-steps"'))->toBeLessThan(strpos($html, 'data-test="me-needs"'))
        ->and($html)->toContain('data-test="me-needs-empty"')
        ->toContain('data-test="me-going-empty"')
        ->toContain('data-test="me-ratings-empty"')
        ->toContain('data-test="me-results-empty"')
        ->toContain('data-test="me-clan-empty"')
        ->toContain(route('chess.lobby'))
        ->toContain(route('gaming.edit'))
        ->toContain(route('clans.index'))
        ->toContain(route('tournaments.index'));
});

test('a first step ticks off once done, and the steps go once all three are', function () {
    $player = meHubPlayer(results: 2);
    $html = $this->actingAs($player['me'])->get(route('dashboard'))->assertOk()->getContent();

    // Blitz played, a clan, a tournament: nothing left to show.
    expect($html)->not->toContain('data-test="me-steps"');

    // No gamer tags brings no step back: they are optional.
    $player['me']->forceFill(['gamer_tags' => null])->save();
    expect($this->actingAs($player['me']->refresh())->get(route('dashboard'))->assertOk()->getContent())->not->toContain('data-test="me-steps"');

    $player['me']->clanMember()->delete();
    $html = $this->actingAs($player['me']->refresh())->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('data-test="me-steps"')
        ->toContain(__('First steps'))
        ->not->toContain(__('Your first steps'))
        ->toContain(__(':done of :total done', ['done' => 2, 'total' => 3]))
        ->toContain('data-step="clan" data-done="false"')
        ->toContain('data-step="blitz" data-done="true"')
        // Not a new player: what needs them stays above the steps.
        ->and(strpos($html, 'data-test="me-needs"'))->toBeLessThan(strpos($html, 'data-test="me-steps"'));
});

test('what needs the player, what runs and comes, ratings, results, clan and Looking to play', function () {
    $player = meHubPlayer();
    $me = $player['me'];
    $html = $this->actingAs($me)->get(route('dashboard'))->assertOk()->getContent();

    // Needs you now: the daily game on their move, with its clock; not the one on the other side's move.
    expect($html)->toContain('data-test="me-need" data-kind="daily" data-phase="your_move"')
        ->toContain('data-test="me-needs-count">1<')
        ->toContain(route('games.show', $player['myMove']))
        ->toContain('data-test="me-need-clock"');

    // Running and upcoming: the other daily game, the tournament with its countdown, the sent and the scheduled casual 1v1.
    $going = substr($html, strpos($html, 'data-test="me-going"'), strpos($html, 'data-test="me-ratings"') - strpos($html, 'data-test="me-going"'));
    expect($going)->toContain(route('games.show', $player['theirMove']))
        ->toContain('data-test="me-tournament" data-state="signup"')
        ->toContain('Halving Cup')
        ->toContain('data-test="me-tournament-clock"')
        ->toContain(__('Waiting for their answer'))
        ->toContain(route('matches.show', $player['sent']))
        ->toContain(route('matches.room', $player['scheduled']))
        ->and(substr_count($going, 'data-test="me-later"'))->toBe(2);

    // Ratings: chess blitz and the lineup's RL 3v3, both casual before Block 0 (no rank badge), each to its ladder.
    expect(substr_count($html, 'data-test="me-rating" data-pool="casual"'))->toBe(2)
        ->and($html)->toContain('data-test="me-rating-value">1042<')
        ->toContain('data-test="me-rating-value">1016<')
        ->toContain(route('ladder.show', ['game' => 'chess', 'mode' => 'blitz']))
        ->toContain(route('ladder.show', ['game' => 'rocket-league', 'mode' => '3v3']));

    // Results: seven games and the series, newest (the series) first, from Satoshi's side, with deltas.
    expect(substr_count($html, 'data-test="me-result"'))->toBe(8)
        ->and(strpos($html, route('matches.show', $player['won'])))->toBeLessThan(strpos($html, 'data-outcome="draw"'))
        ->and($html)->toContain('2 : 0')
        ->toContain('data-outcome="win"')
        ->toContain('data-outcome="loss"')
        ->toContain('data-test="me-result-delta">+12<')
        ->toContain('data-test="me-result-delta">−9<');

    // Clan with its lineup, Manage for the captain; Looking to play on for Rocket League only.
    expect($html)->toContain('data-test="me-clan-link"')
        ->toContain('Laser Eyes')
        ->toContain('data-test="me-lineup"')
        ->toContain(route('clans.manage', $player['clan']))
        ->toContain('data-game="rocket-league" data-on="true"')
        ->toContain('data-game="chess" data-on="false"')
        ->toContain('data-test="me-settings-notifications"')
        ->toContain(route('settings.notifications'))
        ->toContain('data-test="invite-module" data-place="me"');
});

test('only the last ten results show, and the page reads a fixed number of queries however many there are', function () {
    $queries = function (int $results): int {
        $player = meHubPlayer($results);
        // Once before counting: the shell caches its league-wide counts on the first page of a run.
        $this->actingAs($player['me'])->get(route('dashboard'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = $this->actingAs($player['me'])->get(route('dashboard'))->assertOk()->getContent();
        DB::disableQueryLog();

        expect(substr_count($html, 'data-test="me-result"'))->toBe(min(10, $results + 1));

        return count(DB::getQueryLog());
    };

    expect($queries(12))->toBe($queries(3));
});

test('a tournament game on the player carries the countdown to the league\'s decision', function () {
    $tournament = runningChess(TournamentFormat::SingleElimination, 2, TournamentResultsMode::Players, chessMode: 'correspondence');
    $game = TournamentMatch::query()->where('tournament_id', $tournament->id)->sole()->chessGame;

    $html = $this->actingAs($game->white)->get(route('dashboard'))->assertOk()->getContent();
    $needs = substr($html, strpos($html, 'data-test="me-needs"'), strpos($html, 'data-test="me-going"') - strpos($html, 'data-test="me-needs"'));

    // The first move is due: one card, the dock's daily tab with the auto-decision under it.
    expect(substr_count($needs, 'data-test="me-need"'))->toBe(1)
        ->and($needs)->toContain('data-test="auto-decision" data-at="'.intdiv((int) $game->deadline_ms, 1000).'"')
        ->toContain(route('games.show', $game))
        ->and($html)->toContain('data-test="me-tournament" data-state="running"');
});

test('the header\'s "Your page" opens the hub, which links the public player page', function () {
    $me = User::factory()->create();
    $html = $this->actingAs($me)->get(route('home'))->assertOk()->getContent();

    expect($html)->toMatch('#href="'.preg_quote(route('dashboard'), '#').'"[^>]*data-test="account-page"#');

    $this->get(route('dashboard'))->assertOk()->assertSee(route('players.show', $me->npub), false);
});
