<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\TournamentSponsor;
use App\Support\Prizes\PrizeChips;
use App\Support\Prizes\PrizePool;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestSigner;

/*
|--------------------------------------------------------------------------
| The prize chips of a list, in one go (performance plan P2, S7)
|--------------------------------------------------------------------------
|
| PrizeChips reads the sums of a whole list with four grouped queries and
| must show what PrizePool shows for each tournament on its own.
|
*/

beforeEach(function () {
    config(['esports.league.nsec' => (new TestSigner)->secret]);
});

/**
 * A published tournament with an open pot of the given kind, a paid prize, a verified zap, a late zap, a booking
 * and a sponsor's payment outside the wallet, so every sum PrizePool reads has a row.
 *
 * @param  array<string, mixed>  $pot
 */
function chipTournament(array $pot, int $n = 0): Tournament
{
    $tournament = openTournament();
    $tournament->forceFill(['pool_opened_at' => now(), ...$pot])->save();
    $zap = fn (bool $late, int $sats) => IncomingPayment::query()->create(['pot' => $tournament->potAccount(), 'tournament_id' => $tournament->id, 'source' => 'zap',
        'payment_hash' => hash('sha256', $tournament->id.'-'.$n.'-'.$sats), 'bolt11' => 'lnbc', 'amount_sats' => $sats, 'payer_pubkey' => str_repeat('b', 64),
        'status' => IncomingPaymentStatus::Settled, 'expires_at' => now()->addHour(), 'settled_at' => now(), 'late' => $late, 'zap_verified' => true]);
    $zap(false, 2_100);
    $zap(true, 500);
    LedgerTransfer::query()->create(['from_account' => 'wallet', 'to_account' => $tournament->potAccount(), 'sats' => 40_000, 'reason' => 'top-up', 'created_at' => now()]);
    TournamentSponsor::query()->create(['tournament_id' => $tournament->id, 'name' => 'Sponsor', 'pledged_sats' => 0, 'paid_outside_sats' => 7_000]);
    TournamentPayout::query()->create(['tournament_id' => $tournament->id, 'pubkey' => str_repeat('c', 64), 'name' => 'Winner', 'place' => 1, 'amount_sats' => 10_000,
        'idempotency_key' => 'k-'.$tournament->id, 'status' => PayoutStatus::Paid]);

    return $tournament->refresh();
}

test('a chip shows what PrizePool shows, for every kind of pot', function (array $pot) {
    $tournament = chipTournament($pot);
    $pool = app(PrizePool::class);

    expect(PrizeChips::of($tournament))->toBe(['sats' => $pool->potSats($tournament), 'left' => $pool->remainingSats($tournament)])
        ->and($pool->potSats($tournament))->toBeGreaterThan(0);
})->with([
    'fixed prizes, league wallet' => [['pot_source' => Tournament::POT_LEAGUE, 'prize_mode' => Tournament::PRIZES_FIXED, 'prize_fixed' => [30_000, 12_000]]],
    'a target, league wallet' => [['pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 100_000]],
    'what came in, league wallet' => [['pot_source' => Tournament::POT_LEAGUE]],
    'a target, own wallet' => [['pot_source' => Tournament::POT_WALLET, 'prize_target_sats' => 50_000, 'pot_balance_sats' => 9_000]],
    'what came in, own wallet' => [['pot_source' => Tournament::POT_WALLET, 'pot_balance_sats' => 9_000]],
]);

test('no chip without a pot, or before the pool opened', function () {
    $noPot = openTournament();
    $closed = openTournament();
    $closed->forceFill(['pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 100_000])->save();

    expect(PrizeChips::of($noPot->refresh()))->toBeNull()
        ->and(PrizeChips::of($closed->refresh()))->toBeNull();
});

test('/tournaments asks the same queries for one prize pot as for five', function () {
    $queries = function (): int {
        $this->get(route('tournaments.index'))->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $html = (string) $this->get(route('tournaments.index'))->assertOk()->getContent();
        DB::disableQueryLog();

        return count(DB::getQueryLog()) * 100 + substr_count($html, 'data-test="prize-chip"');
    };

    chipTournament(['pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 100_000], 1);
    $one = $queries();

    foreach (range(2, 5) as $n) {
        chipTournament(['pot_source' => Tournament::POT_LEAGUE, 'prize_target_sats' => 100_000], $n);
    }
    $five = $queries();

    // Queries times 100 plus the chips on the page: the chips grow from 1 to 5, the queries stay.
    expect($one % 100)->toBe(1)
        ->and($five % 100)->toBe(5)
        ->and(intdiv($five, 100))->toBe(intdiv($one, 100));
});
