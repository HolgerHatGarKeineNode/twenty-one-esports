<?php

use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\TournamentPayout;
use App\Models\User;
use App\Support\Players\PlayerTrophies;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The trophies of the player page (2026-10-04)
|--------------------------------------------------------------------------
|
| Places 1 to 3 of finished tournaments, newest first, with the prize paid;
| only the newest win is the recent one, and only within RECENT_DAYS. The
| places are cached per tournament, so a warm read costs the same four
| queries for one win or five, and a corrected result reads the bracket again.
|
*/

/** A finished chess cup of `$n` in which `$player` sits on seed `$seed` (seed 1 wins, 2 is second, 3 and 4 share third). */
function trophyCup(User $player, int $seed, int $n, string $name, CarbonImmutable $startsAt, ?int $prize = null): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    TournamentParticipant::query()->where('tournament_id', $tournament->id)->where('seed', $seed)
        ->update(['user_id' => $player->id, 'members' => json_encode([$player->id]), 'name' => $player->displayName()]);
    playOutAsDirector($tournament);
    $tournament->forceFill(['published_at' => $startsAt->subDays(3), 'name' => $name, 'starts_at' => $startsAt])->save();

    if ($prize !== null) {
        TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'user_id' => $player->id, 'pubkey' => $player->pubkey, 'name' => $player->displayName(),
            'place' => $seed, 'amount_sats' => $prize, 'idempotency_key' => TournamentPayout::keyFor($tournament->id, $player->pubkey, $seed), 'status' => PayoutStatus::Paid]);
    }

    return $tournament->refresh();
}

/** The number of queries `$read` runs. */
function trophyQueries(Closure $read): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $read();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('a player without a finished tournament has no trophies', function () {
    $player = User::factory()->create();
    // A cup entered but still running is no trophy.
    $running = runningChess(TournamentFormat::SingleElimination, 4);
    TournamentParticipant::query()->where('tournament_id', $running->id)->where('seed', 1)->update(['user_id' => $player->id, 'members' => json_encode([$player->id])]);
    $running->forceFill(['published_at' => now()])->save();

    expect(app(PlayerTrophies::class)->of($player))->toBe(['trophies' => [], 'wins' => 0, 'podiums' => 0]);
});

test('places 1 to 3 come newest first with their prize, a fifth place is none, and only the newest recent win is celebrated', function () {
    $player = User::factory()->create(['name' => 'Uwe']);
    $recent = trophyCup($player, 1, 8, '21,000 Sats, Zero Ball Control', CarbonImmutable::now()->subDays(2), 21_000);
    trophyCup($player, 5, 8, 'Lightning Night Cup', CarbonImmutable::now()->subDays(10));
    $third = trophyCup($player, 3, 4, 'Mempool Rapid Open', CarbonImmutable::now()->subDays(20));
    $older = trophyCup($player, 1, 4, 'Genesis Blitz Cup', CarbonImmutable::now()->subDays(25));
    $second = trophyCup($player, 2, 4, 'Halving Blitz Cup', CarbonImmutable::now()->subDays(60), 9_000);
    // An unpublished cup the player won is no trophy.
    trophyCup($player, 1, 4, 'Draft Cup', CarbonImmutable::now()->subDays(5))->forceFill(['published_at' => null])->save();

    $read = app(PlayerTrophies::class)->of($player);
    $rows = array_map(fn (array $t): array => [$t['tournament']->id, $t['place'], $t['shared'], $t['prize'], $t['recent']], $read['trophies']);

    expect($rows)->toBe([
        [$recent->id, 1, false, 21_000, true],
        // The third place is shared: the two losing semi-finalists without a match for third place.
        [$third->id, 3, true, null, false],
        // A second win inside RECENT_DAYS is not celebrated: only the newest.
        [$older->id, 1, false, null, false],
        [$second->id, 2, false, 9_000, false],
    ])->and($read['wins'])->toBe(2)->and($read['podiums'])->toBe(2)
        ->and($read['trophies'][0]['tournament']->getAttribute('participants_count'))->toBe(8);
});

test('the newest win is not celebrated once it is older than RECENT_DAYS, and a member of the winning entry gets its trophy', function () {
    $player = User::factory()->create();
    $old = trophyCup($player, 1, 4, 'Old Cup', CarbonImmutable::now()->subDays(PlayerTrophies::RECENT_DAYS + 1));
    // The winning entry is a team: the player is one of its members, not the one who signed it up.
    $captain = User::factory()->create();
    $member = User::factory()->create();
    TournamentParticipant::query()->where('tournament_id', $old->id)->where('user_id', $player->id)
        ->update(['user_id' => $captain->id, 'members' => json_encode([$captain->id, $member->id])]);

    $team = app(PlayerTrophies::class)->of($member);

    expect(app(PlayerTrophies::class)->of($captain)['trophies'][0]['recent'])->toBeFalse()
        ->and($team['wins'])->toBe(1)->and($team['trophies'][0]['tournament']->id)->toBe($old->id)
        ->and(app(PlayerTrophies::class)->of($player)['trophies'])->toBe([]);
});

test('warm, the trophies cost four queries for one win and for five; a corrected result reads that bracket again', function () {
    $one = User::factory()->create();
    trophyCup($one, 1, 4, 'Cup A', CarbonImmutable::now()->subDays(3), 1_000);
    $five = User::factory()->create();
    $cups = [];
    foreach (range(1, 5) as $i) {
        $cups[] = trophyCup($five, 1, 4, "Cup {$i}", CarbonImmutable::now()->subDays($i * 7), 1_000);
    }

    $trophies = app(PlayerTrophies::class);
    $coldOne = trophyQueries(fn () => $trophies->of($one));
    $coldFive = trophyQueries(fn () => $trophies->of($five));
    $warmOne = trophyQueries(fn () => $trophies->of($one));
    $warmFive = trophyQueries(fn () => $trophies->of($five));

    // A director corrects a result of one cup: its fingerprint changes, the others stay cached.
    TournamentMatch::query()->where('tournament_id', $cups[2]->id)->whereNotNull('result')->first()->forceFill(['updated_at' => now()->addMinute()])->save();
    $corrected = trophyQueries(fn () => $trophies->of($five));

    expect($warmOne)->toBe(4)->and($warmFive)->toBe(4)
        ->and($coldFive)->toBeGreaterThan($coldOne)
        ->and($corrected)->toBe($warmFive + ($coldOne - $warmOne))
        ->and($trophies->of($five)['wins'])->toBe(5);
});

test('the player page shows the honours and the trophies, and its warm query count is the same for one win and for five', function () {
    $one = User::factory()->create(['name' => 'One Win']);
    trophyCup($one, 1, 4, 'Single Cup', CarbonImmutable::now()->subDays(3), 21_000);
    $five = User::factory()->create(['name' => 'Five Wins']);
    foreach (range(1, 5) as $i) {
        trophyCup($five, 1, 4, "Cup {$i}", CarbonImmutable::now()->subDays($i * 40), 1_000);
    }
    $nothing = User::factory()->create(['name' => 'Fresh Pleb']);

    $load = function (User $player): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('players.show', $player->npub))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $load($one);
    $load($five);
    $warmOne = $load($one);
    $warmFive = $load($five);

    $this->get(route('players.show', $one->npub))->assertOk()
        ->assertSee('data-test="player-honours-wins"', false)->assertSee('1 tournament win')
        ->assertSee("21\u{00A0}000 sats won")
        ->assertSee('data-test="player-trophy-featured" data-place="1" data-recent="1"', false)
        ->assertDontSee('data-test="player-trophy"', false);
    $this->get(route('players.show', ['npub' => $five->npub, 'lang' => 'de']))->assertOk()
        ->assertSee('5 Turniersiege')->assertSee('Trophäen')
        // The newest is 40 days old: no landing.
        ->assertSee('data-recent="0"', false);
    $this->get(route('players.show', $nothing->npub))->assertOk()
        ->assertDontSee('data-test="player-honours"', false)->assertDontSee('data-test="player-trophies"', false);

    // Measured 2026-10-04: 57 and 81. The 24 between them are PlayerStats::tournaments() reading the brackets of its
    // listed tournaments uncached (six queries each, four more cups); the trophies themselves add a constant four.
    expect($warmOne)->toBeLessThanOrEqual(57)
        ->and($warmFive)->toBeLessThanOrEqual(81);
});
