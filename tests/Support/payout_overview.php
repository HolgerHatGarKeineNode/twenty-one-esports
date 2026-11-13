<?php

/*
 * Helpers of the payouts overview tests (admin payouts page), loaded from tests/Pest.php: tournaments with a pot in
 * every payout state of App\Support\Payouts\PayoutOverview, built from the columns the state reads, without playing
 * a bracket out.
 */

use App\Enums\PayoutStatus;
use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Support\Payouts\PayoutOverview;

/**
 * A tournament with a league pot in the given payout state. `$payouts` overrides the payouts written for it:
 * a list of [status, sats, reason].
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array{0: PayoutStatus, 1: int, 2?: string|null}>|null  $payouts
 */
function potTournamentIn(string $state, array $attributes = [], ?array $payouts = null): Tournament
{
    $approved = ! in_array($state, [PayoutOverview::UNCHECKED, PayoutOverview::NOT_DUE], true);

    $tournament = Tournament::factory()->create([
        'status' => $state === PayoutOverview::NOT_DUE ? TournamentStatus::Signup : TournamentStatus::Finished,
        'pool_opened_at' => now()->subDays(3),
        'pool_closed_at' => $approved ? now()->subDay() : null,
        'payouts_approved_at' => $approved ? now()->subDay() : null,
        'pot_source' => Tournament::POT_LEAGUE,
        'prize_target_sats' => 100_000,
        ...$attributes,
    ]);

    $payouts ??= match ($state) {
        PayoutOverview::NEEDS_CHECK => [[PayoutStatus::Paying, 50_000, 'needs_check'], [PayoutStatus::Pending, 30_000], [PayoutStatus::Paid, 20_000]],
        PayoutOverview::FAILED => [[PayoutStatus::Failed, 50_000, 'wallet_error'], [PayoutStatus::Pending, 30_000], [PayoutStatus::Open, 20_000, 'no_lud16']],
        PayoutOverview::READY => [[PayoutStatus::Pending, 50_000], [PayoutStatus::Open, 30_000, 'no_lud16'], [PayoutStatus::Paid, 20_000]],
        PayoutOverview::OPEN => [[PayoutStatus::Open, 50_000, 'no_lud16'], [PayoutStatus::Paying, 30_000], [PayoutStatus::Paid, 20_000]],
        PayoutOverview::PAYING => [[PayoutStatus::Paying, 50_000], [PayoutStatus::Paid, 30_000], [PayoutStatus::Forwarded, 20_000]],
        PayoutOverview::PAID => [[PayoutStatus::Paid, 50_000], [PayoutStatus::Paid, 30_000], [PayoutStatus::Forwarded, 20_000]],
        default => [],
    };

    foreach ($payouts as $index => $payout) {
        $pubkey = hash('sha256', 'payout-overview-'.$tournament->id.'-'.$index);

        TournamentPayout::query()->create([
            'tournament_id' => $tournament->id,
            'pubkey' => $pubkey,
            'name' => 'Player '.($index + 1),
            'place' => $index + 1,
            'amount_sats' => $payout[1],
            'idempotency_key' => TournamentPayout::keyFor($tournament->id, $pubkey, $index + 1),
            'lud16' => $payout[0] === PayoutStatus::Open ? null : 'player'.($index + 1).'@wallet.example',
            'status' => $payout[0],
            'reason' => $payout[2] ?? null,
        ]);
    }

    return $tournament;
}
