<?php

namespace App\Support\Prizes;

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Models\IncomingPayment;
use App\Models\LedgerTransfer;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\TournamentSponsor;
use App\Support\RequestMemo;
use ArrayObject;

/**
 * The figures of <x-prize-chip> for a whole list at once (performance plan
 * P2, S7): each chip used to ask for the pot and again for what is left,
 * five queries a tournament. A list primes its tournaments here, four
 * grouped queries for any number of them; a chip outside a primed list
 * works out its own tournament the same way.
 *
 * The arithmetic is PrizePool::potSats() and remainingSats() for display,
 * on sums read in bulk: the pot as set (the fixed prizes' sum or the target)
 * plus the verified zaps, or without either what came in (league ledger, or
 * a legacy own wallet's balance) plus what was paid outside; less the paid
 * prizes. tests/Feature/PrizeChipsTest.php holds it equal to PrizePool for
 * every kind of pot. PrizePool itself is untouched: payouts read it.
 */
final class PrizeChips
{
    /**
     * The chip of one tournament: what is left of how much, or null without an open pot.
     *
     * @return array{sats: int, left: int}|null
     */
    public static function of(Tournament $tournament): ?array
    {
        $known = self::known();

        if (! $known->offsetExists($tournament->id)) {
            $known[$tournament->id] = self::compute([$tournament])[$tournament->id];
        }

        return $known[$tournament->id];
    }

    /**
     * Works out the chips of these tournaments in one go and keeps them for this request.
     *
     * @param  iterable<Tournament>  $tournaments
     */
    public static function prime(iterable $tournaments): void
    {
        $known = self::known();
        $missing = [];

        foreach ($tournaments as $tournament) {
            if (! $known->offsetExists($tournament->id)) {
                $missing[$tournament->id] = $tournament;
            }
        }

        foreach (self::compute(array_values($missing)) as $id => $chip) {
            $known[$id] = $chip;
        }
    }

    /**
     * @param  list<Tournament>  $tournaments
     * @return array<int, array{sats: int, left: int}|null>
     */
    private static function compute(array $tournaments): array
    {
        $chips = [];
        $pots = [];

        foreach ($tournaments as $tournament) {
            $chips[$tournament->id] = null;

            if ($tournament->pool_opened_at !== null && $tournament->hasPot()) {
                $pots[$tournament->id] = $tournament;
            }
        }

        if ($pots === []) {
            return $chips;
        }

        $ids = array_keys($pots);
        $accounts = array_map(fn (Tournament $tournament): string => $tournament->potAccount(), array_values($pots));

        /** @var array<int, int> $paid */
        $paid = TournamentPayout::query()->whereIn('tournament_id', $ids)->where('status', PayoutStatus::Paid)
            ->groupBy('tournament_id')->selectRaw('tournament_id, sum(amount_sats) as sats')->pluck('sats', 'tournament_id')->map(fn (mixed $sats): int => (int) $sats)->all();
        // ZapSponsors::zapSats(): verified, settled, on-time zaps with a payer, booked into the pot's account.
        /** @var array<int, int> $zaps */
        $zaps = IncomingPayment::query()->whereIn('tournament_id', $ids)->whereIn('pot', $accounts)->where('zap_verified', true)
            ->where('source', 'zap')->where('status', IncomingPaymentStatus::Settled)->where('late', false)->whereNotNull('payer_pubkey')
            ->groupBy('tournament_id', 'pot')->selectRaw('tournament_id, pot, sum(amount_sats) as sats')->get()
            ->filter(fn (IncomingPayment $row): bool => $row->pot === 'tournament:'.$row->tournament_id)
            ->mapWithKeys(fn (IncomingPayment $row): array => [(int) $row->tournament_id => (int) $row->getAttribute('sats')])->all();
        /** @var array<string, int> $credited */
        $credited = LedgerTransfer::query()->whereIn('to_account', $accounts)->groupBy('to_account')
            ->selectRaw('to_account, sum(sats) as sats')->pluck('sats', 'to_account')->map(fn (mixed $sats): int => (int) $sats)->all();
        /** @var array<int, int> $outside */
        $outside = TournamentSponsor::query()->whereIn('tournament_id', $ids)->groupBy('tournament_id')
            ->selectRaw('tournament_id, sum(paid_outside_sats) as sats')->pluck('sats', 'tournament_id')->map(fn (mixed $sats): int => (int) $sats)->all();

        foreach ($pots as $id => $tournament) {
            // Zaps count toward the pot only in the league wallet (ZapSponsors::wall()).
            $zapped = $tournament->hasLeaguePot() ? ($zaps[$id] ?? 0) : 0;
            $funded = $tournament->hasOwnWallet() ? (int) $tournament->pot_balance_sats : ($credited[$tournament->potAccount()] ?? 0);

            $sats = match (true) {
                $tournament->prizeMode() === Tournament::PRIZES_FIXED => PrizePool::fixedTotal($tournament) + $zapped,
                $tournament->prize_target_sats === null => $funded + ($outside[$id] ?? 0),
                default => $tournament->prize_target_sats + $zapped,
            };

            $chips[$id] = ['sats' => $sats, 'left' => max(0, $sats - ($paid[$id] ?? 0))];
        }

        return $chips;
    }

    /** @return ArrayObject<int, array{sats: int, left: int}|null> */
    private static function known(): ArrayObject
    {
        return RequestMemo::remember('prize-chips', fn (): ArrayObject => new ArrayObject);
    }
}
