<?php

use App\Enums\PayoutStatus;
use App\Support\Payouts\PayoutApproval;
use App\Support\Wallet\Ledger;
use Livewire\Livewire;

/*
| A player passes a prize on to the reserve for the next pot (user, 2026-10-04): it stays in the split, is never
| paid, the reserve gets the sats, and the tournament page says so.
*/

test('an admin passes a prize on: never paid, the reserve gets it, the tournament page shows it', function () {
    fakeWallet();
    $wallet = ownPotWallet(0);
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 100_000);
    $admin = anAdmin();
    app(PayoutApproval::class)->approve($tournament, $admin);
    $second = $tournament->payouts()->where('place', 2)->sole();
    $reserve = app(Ledger::class)->balance(Ledger::RESERVE);

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->call('forward', $second->id)->assertSee('passed');

    expect($second->refresh()->status)->toBe(PayoutStatus::Forwarded)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe($reserve + $second->amount_sats)
        ->and($wallet->paid)->toBeEmpty();

    // Passing on twice changes nothing; a forwarded prize is never paid.
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('forward', $second->id)->call('pay', $second->id);
    expect(app(Ledger::class)->balance(Ledger::RESERVE))->toBe($reserve + $second->amount_sats)
        ->and($second->refresh()->status)->toBe(PayoutStatus::Forwarded);

    Livewire::test('tournament-pool', ['tournament' => $tournament])->assertSeeHtml('data-test="payout-forwarded"');
});
