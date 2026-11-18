<?php

use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\User;
use App\Support\FairPlay\FairPlay;
use App\Support\Hyper\HyperMatches;
use Tests\Support\HyperOn;

/*
| Leaving a rated Hyperbitcoinization match warns first (fair play, forfeits): the match page renders a dialog with
| what the leave costs (no season block for the table, a loss, the player's own count against the settings), and the
| server takes a rated leave only with `confirm`. A casual leave is as before: the button asks twice, no dialog.
*/

beforeEach(function () {
    $this->withoutVite();
    HyperOn::play();
});

/** A forfeited seat of `$player` in another rated match, `$daysAgo` days ago. */
function hfwForfeit(User $player, float $daysAgo): HyperSeat
{
    return HyperSeat::factory()->create([
        'hyper_match_id' => HyperMatch::factory()->create(['rated' => true])->id,
        'seat' => 5,
        'user_id' => $player->id,
        'bot' => true,
        'takeover' => HyperSeat::TAKEOVER_FORFEIT,
        'left_at' => now()->subSeconds((int) round($daysAgo * 86400)),
    ]);
}

test('a rated leave without the confirmation forfeits nothing and answers what it would cost; with it the seat is forfeited', function () {
    openSeason(ladders: false);
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert);

    foreach ([[], ['confirm' => false], ['confirm' => 'no']] as $body) {
        $this->actingAs($anna)->postJson(route('hyper.leave', $match), $body)->assertStatus(422)
            ->assertJsonPath('reason', 'confirm_forfeit')
            ->assertJsonPath('message', 'Leaving a rated match needs your confirmation.')
            ->assertJsonPath('warning.count', 1)
            ->assertJsonPath('warning.limit', 2)
            ->assertJsonPath('warning.locks', false)
            ->assertJsonMissingPath('snapshot');
    }

    expect($match->refresh()->seats->firstWhere('user_id', $anna->id)->only(['bot', 'takeover', 'left_at']))->toBe(['bot' => false, 'takeover' => null, 'left_at' => null])
        ->and(FairPlay::forfeitsInWindow($anna))->toBe(0);

    $this->actingAs($anna)->postJson(route('hyper.leave', $match), ['confirm' => true])->assertOk()->assertJsonPath('snapshot.seats.0.bot', true);

    $seat = $match->refresh()->seats->firstWhere('user_id', $anna->id);
    expect($seat->takeover)->toBe(HyperSeat::TAKEOVER_FORFEIT)
        ->and($seat->left_at)->not->toBeNull()
        ->and(FairPlay::forfeitsInWindow($anna))->toBe(1);

    // Nothing left to confirm: the seat is gone, and the refusal is the old one.
    $this->actingAs($anna)->postJson(route('hyper.leave', $match))->assertForbidden()->assertJsonPath('reason', 'already_left');
    $this->actingAs(User::factory()->create())->postJson(route('hyper.leave', $match))->assertForbidden()->assertJsonPath('reason', 'not_seated');
});

test('the dialog of a rated match shows the player\'s own count and the numbers in force, and the clock line says what turns that run out cost', function () {
    openSeason(['genesis_at' => now()->subDays(60)->startOfSecond(), 'ends_at' => now()->addDays(60)->startOfSecond()], ladders: false);
    [$anna, $bert] = User::factory()->count(2)->create();
    hfwForfeit($bert, 3);
    // Outside the window of 30 days: not in the count.
    hfwForfeit($bert, 31);
    $match = HyperOn::versus($anna, $bert);

    $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('data-test="hyper-leave-dialog" data-count="1" data-limit="2" data-locks="0"', false)
        ->assertSeeInOrder([
            'Leave this rated match?',
            'If you leave, nobody at this table mines a season block.',
            'The match counts as a loss for you.',
            'It would be rated match no. 1 you left within 30 days. The limit is 2.',
            'The next one locks rated play in every game for 7 days.',
            'data-test="hyper-leave-stay"',
            'data-test="hyper-leave-confirm"',
        ], false)
        ->assertSeeInOrder(['data-test="hyper-forfeit-note"', '3 turns in a row that run out count as leaving: a loss, no season block at this table, and it counts toward the lock from rated play.'], false);

    $this->actingAs($bert)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('data-test="hyper-leave-dialog" data-count="2" data-limit="2" data-locks="1"', false)
        ->assertSee('It would be rated match no. 2 you left within 30 days. The limit is 2.')
        ->assertSee('This one locks rated play in every game for 7 days.')
        ->assertDontSee('The next one locks rated play');

    // The settings decide every number.
    config(['esports.fair_play.forfeits' => 4, 'esports.fair_play.forfeit_window_days' => 45, 'esports.fair_play.forfeit_lock_days' => 3, 'esports.hyper.takeover_timeouts' => 2]);

    $this->actingAs($bert)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('data-count="3" data-limit="4" data-locks="0"', false)
        ->assertSee('It would be rated match no. 3 you left within 45 days. The limit is 4.')
        ->assertSee('The next one locks rated play in every game for 3 days.')
        ->assertSee('2 turns in a row that run out count as leaving');

    $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('From no. 4 on, rated play is locked in every game for 3 days.');
});

test('the dialog reads in German', function () {
    openSeason(ladders: false);
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert);

    $this->actingAs($anna)->get(route('hyper.match', [$match, 'lang' => 'de']))->assertOk()
        ->assertSeeInOrder([
            'Dieses gewertete Match verlassen?',
            'Wenn du gehst, schürft an diesem Tisch niemand einen Season-Block.',
            'Das Match zählt für dich als Niederlage.',
            'Es wäre dein 1. verlassenes gewertetes Match in 30 Tagen. Die Grenze liegt bei 2.',
            'Das nächste sperrt dich 7 Tage für gewertetes Spielen in allen Spielen.',
            'Weiterspielen',
            'Verlassen und Niederlage hinnehmen',
        ], false)
        ->assertSee('3 abgelaufene Züge in Folge zählen wie Verlassen: Niederlage, kein Season-Block an diesem Tisch, und es zählt für die Sperre gewerteter Partien.');
});

test('only a player who still plays a seat of a running rated match gets the dialog and the clock line', function () {
    openSeason(ladders: false);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = HyperOn::versus($anna, $bert);

    foreach ([$carl, null] as $viewer) {
        ($viewer ? $this->actingAs($viewer) : $this)->get(route('hyper.match', $match))->assertOk()
            ->assertDontSee('data-test="hyper-leave-dialog"', false)
            ->assertDontSee('data-test="hyper-forfeit-note"', false);
    }

    $this->actingAs($anna)->postJson(route('hyper.leave', $match), ['confirm' => true])->assertOk();

    $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk()
        ->assertDontSee('data-test="hyper-leave-dialog"', false)
        ->assertDontSee('data-test="hyper-forfeit-note"', false);
});

test('a seat knocked out of a rated match gets no dialog and no clock line, and leaves without the confirmation and without a forfeit', function () {
    openSeason(ladders: false);
    [$anna, $bert, $carl] = User::factory()->count(3)->create();
    $match = HyperOn::ending(app(HyperMatches::class)->create([['user' => $anna], ['user' => $bert], ['user' => $carl]], seed: 7), winner: 0, runnerUp: 1, out: [2 => 3]);

    $this->actingAs($carl)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('data-test="hyper-leave"', false)
        ->assertDontSee('data-test="hyper-leave-dialog"', false)
        ->assertDontSee('data-test="hyper-forfeit-note"', false);

    // Bert is still in the game: he is asked as before.
    $this->actingAs($bert)->get(route('hyper.match', $match))->assertOk()->assertSee('data-test="hyper-leave-dialog"', false);
    $this->actingAs($bert)->postJson(route('hyper.leave', $match))->assertStatus(422)->assertJsonPath('reason', 'confirm_forfeit');

    $this->actingAs($carl)->postJson(route('hyper.leave', $match))->assertOk()->assertJsonPath('snapshot.seats.2.bot', true);

    expect($match->refresh()->seats->firstWhere('user_id', $carl->id)->takeover)->toBe(HyperSeat::TAKEOVER_LEFT)
        ->and(FairPlay::forfeitsInWindow($carl))->toBe(0);
});

test('a casual leave is as before: no dialog, no confirmation, a bot takes over and nothing counts', function () {
    openSeason(ladders: false);
    [$anna, $bert] = User::factory()->count(2)->create();
    $match = HyperOn::versus($anna, $bert, bots: 1);

    expect($match->rated)->toBeFalse();

    $this->actingAs($anna)->get(route('hyper.match', $match))->assertOk()
        ->assertSee('data-test="hyper-leave"', false)
        ->assertDontSee('data-test="hyper-leave-dialog"', false)
        ->assertDontSee('data-test="hyper-forfeit-note"', false);

    $this->actingAs($anna)->postJson(route('hyper.leave', $match))->assertOk()->assertJsonPath('snapshot.seats.0.bot', true);

    expect($match->refresh()->seats->firstWhere('user_id', $anna->id)->takeover)->toBe(HyperSeat::TAKEOVER_LEFT)
        ->and(FairPlay::forfeitsInWindow($anna))->toBe(0)
        ->and(FairPlay::isBarred($anna->pubkey))->toBeFalse();
});
