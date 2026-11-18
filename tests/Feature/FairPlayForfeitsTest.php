<?php

use App\Models\HyperMatch;
use App\Models\HyperSeat;
use App\Models\Season;
use App\Models\User;
use App\Support\Chess\RatedChess;
use App\Support\FairPlay\FairPlay;
use App\Support\Hyper\HyperLobby;
use App\Support\Hyper\HyperMatches;
use App\Support\Hyper\HyperRuleViolation;
use App\Support\LeagueTime;
use App\Support\SeasonChain\Seasons;
use App\Support\SeasonChain\TrustFacts;
use App\Support\Settings\LeagueSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\HyperOn;
use Tests\Support\TrustedFacts;

/*
|--------------------------------------------------------------------------
| Fair play: forfeited rated Hyperbitcoinization matches
|--------------------------------------------------------------------------
|
| A seat of a rated match its player left or let run out is a forfeit
| (`hyper_seats.takeover` = forfeit, `left_at` its time). Two of them within
| 30 days lock rated play in every game for 7 days from the last one; only
| forfeits since Block 0 of the live season count. The numbers are the
| settings `esports.fair_play.forfeits`, `forfeit_window_days` and
| `forfeit_lock_days`.
|
*/

beforeEach(function () {
    Queue::fake();
    $this->withoutVite();
    HyperOn::play();
});

/** A season live since `$days` days, long enough for every window of these tests. */
function ffSeason(int $days = 90): Season
{
    return openSeason(['slug' => 'season-1', 'genesis_at' => now()->subDays($days)->startOfSecond(), 'ends_at' => now()->addDays(90)->startOfSecond()]);
}

/** A seat `$player` gave up `$daysAgo` days ago: a forfeit of a rated match unless the arguments say otherwise. */
function ffSeat(User $player, float $daysAgo, string $takeover = HyperSeat::TAKEOVER_FORFEIT, bool $rated = true): HyperSeat
{
    return HyperSeat::factory()->create([
        'hyper_match_id' => HyperMatch::factory()->create(['rated' => $rated])->id,
        'seat' => 5,
        'user_id' => $player->id,
        'bot' => true,
        'takeover' => $takeover,
        'left_at' => now()->subSeconds((int) round($daysAgo * 86400)),
    ]);
}

test('one forfeit locks nobody; two within 30 days lock rated play until exactly 7 days after the last; farther apart they do not', function () {
    $this->freezeSecond();
    ffSeason();
    [$once, $close, $apart, $edge] = User::factory()->count(4)->create();

    ffSeat($once, 1);
    ffSeat($close, 20);
    $last = ffSeat($close, 2);
    // 34 days apart: the second one alone does not lock, recent as it is.
    ffSeat($apart, 35);
    ffSeat($apart, 1);
    // Exactly 30 days apart still counts.
    ffSeat($edge, 36);
    ffSeat($edge, 6);

    $barred = FairPlay::barred([$once->pubkey, $close->pubkey, $apart->pubkey, $edge->pubkey]);

    expect(array_keys($barred))->toEqualCanonicalizing([$close->pubkey, $edge->pubkey])
        ->and($barred[$close->pubkey]['kind'])->toBe('forfeits')
        ->and($barred[$close->pubkey]['until']?->equalTo(CarbonImmutable::instance($last->left_at)->addDays(7)))->toBeTrue()
        ->and($barred[$edge->pubkey]['until']?->equalTo(now()->addDay()))->toBeTrue()
        ->and(FairPlay::isBarred($once->pubkey))->toBeFalse()
        ->and(FairPlay::isBarred($apart->pubkey))->toBeFalse();

    // The lock ends 7 days after the last forfeit, to the second.
    $this->travelTo(CarbonImmutable::instance($last->left_at)->addDays(7)->subSecond());
    expect(FairPlay::isBarred($close->pubkey))->toBeTrue();

    $this->travel(1)->seconds();
    expect(FairPlay::isBarred($close->pubkey))->toBeFalse();
});

test('a forfeit before Block 0 of the live season does not count, and outside a season no forfeit locks', function () {
    $this->freezeSecond();
    [$early, $late] = User::factory()->count(2)->create();
    ffSeat($early, 12);
    ffSeat($early, 1);
    ffSeat($late, 9);
    ffSeat($late, 1);

    expect(FairPlay::barred([$early->pubkey, $late->pubkey]))->toBe([]);

    // Block 0 ten days ago: the forfeit of twelve days ago is from before the release.
    ffSeason(days: 10);

    expect(array_keys(FairPlay::barred([$early->pubkey, $late->pubkey])))->toBe([$late->pubkey]);
});

test('only a forfeited seat of a rated match counts: a casual leave, a casual timeout takeover and a match that is not rated never do', function (string $takeover, bool $rated) {
    ffSeason();
    $player = User::factory()->create();
    ffSeat($player, 3);
    ffSeat($player, 1, $takeover, $rated);

    expect(FairPlay::isBarred($player->pubkey))->toBeFalse();

    ffSeat($player, 0.5);

    expect(FairPlay::isBarred($player->pubkey))->toBeTrue();
})->with([
    'casual leave' => [HyperSeat::TAKEOVER_LEFT, false],
    'casual timeout takeover' => [HyperSeat::TAKEOVER_TIMEOUTS, false],
    'forfeit mark on a match voided since (no longer rated)' => [HyperSeat::TAKEOVER_FORFEIT, false],
    // Never written by HyperMatches, which marks every give-up of a rated seat as a forfeit: the mark alone decides.
    'seat marked as left in a rated match' => [HyperSeat::TAKEOVER_LEFT, true],
    'seat marked as timed out in a rated match' => [HyperSeat::TAKEOVER_TIMEOUTS, true],
]);

test('the three settings decide: 3 forfeits in 10 days lock for 2 days', function () {
    $this->freezeSecond();
    ffSeason();
    $player = User::factory()->create();
    ffSeat($player, 9);
    ffSeat($player, 1);

    expect(FairPlay::isBarred($player->pubkey))->toBeTrue();

    config(['esports.fair_play.forfeits' => 3, 'esports.fair_play.forfeit_window_days' => 10, 'esports.fair_play.forfeit_lock_days' => 2]);
    LeagueSettings::forget();

    expect(FairPlay::isBarred($player->pubkey))->toBeFalse();

    ffSeat($player, 0.5);

    expect(FairPlay::barred([$player->pubkey])[$player->pubkey]['until']?->equalTo(now()->subHours(12)->addDays(2)))->toBeTrue();

    // A window of 5 days no longer holds the three; a lock of 1 day would still run, the window decides.
    config(['esports.fair_play.forfeit_window_days' => 5]);
    LeagueSettings::forget();

    expect(FairPlay::isBarred($player->pubkey))->toBeFalse();
});

test('the three settings are edited with the other fair-play values on the settings page', function () {
    $definitions = LeagueSettings::definitions();

    expect(array_map(fn (string $key): array => [$definitions[$key]['group'], $definitions[$key]['board_only'], LeagueSettings::get($key)], ['esports.fair_play.forfeits', 'esports.fair_play.forfeit_window_days', 'esports.fair_play.forfeit_lock_days']))
        ->toBe([['fair_play', true, 2], ['fair_play', true, 30], ['fair_play', true, 7]]);
});

test('leaving two rated matches through the game locks the player; the locked player is refused rated chess and rated tables with the reason and the end', function () {
    $this->freezeSecond();
    ffSeason();
    app()->bind(TrustFacts::class, TrustedFacts::class);
    config(['esports.chess.rated_queue' => true]);
    [$anna, $bert] = User::factory()->count(2)->create();
    $matches = app(HyperMatches::class);

    $matches->leave(HyperOn::versus($anna, $bert), $anna);

    expect(FairPlay::isBarred($anna->pubkey))->toBeFalse()
        ->and(FairPlay::forfeitsInWindow($anna))->toBe(1);

    $this->travel(3)->days();
    $matches->leave(HyperOn::versus($anna, $bert), $anna);
    $own = 'You cannot play rated until '.LeagueTime::stamp(now()->addDays(7)).': you left 2 rated Hyperbitcoinization matches within 30 days. Casual play stays open.';
    $this->actingAs($anna);

    expect(FairPlay::isBarred($anna->pubkey))->toBeTrue()
        ->and(FairPlay::isBarred($bert->pubkey))->toBeFalse()
        ->and(app(RatedChess::class)->refusal($anna, 'blitz'))->toBe($own)
        ->and(fn () => app(HyperLobby::class)->open($anna, 2, HyperMatch::LIVE, 0))->toThrow(HyperRuleViolation::class, $own)
        ->and(fn () => app(HyperLobby::class)->join(app(HyperLobby::class)->open($bert, 2, HyperMatch::LIVE, 0), $anna))->toThrow(HyperRuleViolation::class, $own);

    $this->get(route('chess.lobby'))->assertOk()->assertSee($own);
    Livewire::actingAs($anna)->test('hyper-lobby')->call('openTable')->assertSee($own);

    // Casual stays open: a friendly table takes the locked player.
    Livewire::actingAs($anna)->test('hyper-lobby')->set('friendly', true)->call('openTable')->assertSet('error', null);

    // Another player reads no count and no days, only that and until when.
    $this->actingAs($bert);
    expect(FairPlay::message($anna->pubkey))->toBe($anna->displayName().' cannot play rated until '.LeagueTime::stamp(now()->addDays(7)).' after leaving rated matches.');
});

test('a locked player asks for no rated rematch, and the refusal says why', function () {
    $this->freezeSecond();
    ffSeason();
    [$anna, $bert] = User::factory()->count(2)->create();
    ffSeat($anna, 2);
    $old = HyperOn::finish(HyperOn::endgame($anna, $bert), $anna);

    $this->actingAs($anna)->postJson(route('hyper.rematch', $old))->assertOk();

    ffSeat($bert, 2);
    ffSeat($bert, 1);

    $this->actingAs($bert)->postJson(route('hyper.rematch', $old))->assertStatus(422)
        ->assertJsonPath('reason', 'fair_play')
        ->assertJsonPath('message', 'You cannot play rated until '.LeagueTime::stamp(now()->subDay()->addDays(7)).': you left 2 rated Hyperbitcoinization matches within 30 days. Casual play stays open.');
});

test('barred() asks the database a fixed number of times, for one player or a whole table of them', function () {
    ffSeason();
    $players = User::factory()->count(12)->create();
    $players->take(6)->each(function (User $player): void {
        ffSeat($player, 2);
        ffSeat($player, 1);
    });
    $count = function (array $pubkeys): int {
        LeagueSettings::forget();
        Seasons::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $barred = FairPlay::barred($pubkeys);
        DB::disableQueryLog();

        expect(count($barred))->toBe(min(6, count($pubkeys)));

        return count(DB::getQueryLog());
    };

    $one = $count([$players[0]->pubkey]);

    expect($count($players->pluck('pubkey')->all()))->toBe($one)
        ->and($one)->toBeLessThanOrEqual(8);
});

test('the rules name agreed games and the lock after left rated matches, with the numbers in force, in English and German', function () {
    $this->get(route('rules'))->assertOk()
        ->assertSeeInOrder([
            'id="fair-play"',
            'Agreed or deliberately lost games cost their blocks at the season review and can lock rated play.',
            'From match no. 2 you leave within 30 days, rated play is locked in every game for 7 days.',
        ], false);

    config(['esports.fair_play.forfeits' => 3, 'esports.fair_play.forfeit_window_days' => 14, 'esports.fair_play.forfeit_lock_days' => 5]);

    $this->get(route('rules'))->assertOk()
        ->assertSee('Leaving a rated Hyperbitcoinization match, or letting its turns run out, counts as a loss. From match no. 3 you leave within 14 days, rated play is locked in every game for 5 days.');

    $this->get(route('rules', ['lang' => 'de']))->assertOk()
        ->assertSee('Abgesprochene oder absichtlich verlorene Partien kosten bei der Season-Prüfung ihre Blöcke und können gewertetes Spielen sperren.')
        ->assertSee('Ein gewertetes Hyperbitcoinization-Match zu verlassen oder seine Züge ablaufen zu lassen, zählt als Niederlage. Ab dem 3. verlassenen Match innerhalb von 14 Tagen ist gewertetes Spielen in allen Spielen 5 Tage gesperrt.');
});
