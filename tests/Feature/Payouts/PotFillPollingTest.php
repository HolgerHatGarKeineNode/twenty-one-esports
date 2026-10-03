<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\User;
use App\Support\PreSeason;
use App\Support\Prizes\PotZaps;
use Livewire\Attributes\On;
use Livewire\Livewire;
use Tests\Support\FakeNwcWallet;

/*
| "Fill the pot" notices a payment by itself (user, 2026-10-03: „21 sats
| gezapped, aber es kommt keine automatische Meldung, dass es ankam."):
| while the card shows an invoice, from "Zap with Nostr" or "Pay without
| Nostr", it polls that one invoice, asks the league wallet about it at most
| every 5 seconds, and switches to the thank-you once it settled, or to
| "expired" once it ran out. It only ever shows its viewer's own invoice.
*/

/** The lookups the league wallet received so far. */
function potLookups(FakeNwcWallet $league): int
{
    return collect($league->calls)->where('method', 'lookup_invoice')->count();
}

test('the card shows and looks up only its viewer’s own invoice', function () {
    $league = fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $alice = User::factory()->create();

    $card = Livewire::actingAs($alice)->test('tournament-pool', ['tournament' => $tournament])
        ->set('amount', 2100)->call('topUp')->assertSeeHtml('data-test="topup-qr"');
    IncomingPayment::query()->sole();

    // The same card replayed by someone else (another account, then a guest): no invoice, no lookup, no poll.
    $this->actingAs(User::factory()->create());
    $this->travel(10)->seconds();
    $card->call('checkInvoice')->assertDontSeeHtml('data-test="topup-invoice"')->assertDontSeeHtml('wire:poll');
    auth()->logout();
    $card->call('checkInvoice')->assertDontSeeHtml('data-test="topup-invoice"');

    expect(potLookups($league))->toBe(0);
});

test('a paid zap switches the card to the thank-you by itself and puts the zapper on the wall', function () {
    $league = fakeWallet();
    [$player, $signer] = keyedPlayer();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $card = Livewire::actingAs($player)->test('tournament-pool', ['tournament' => $tournament]);

    $template = $card->instance()->prepareZap($tournament->id, 2_100, 'GL', app(PotZaps::class))['template'];
    $signed = $signer->sign($template['kind'], $template['tags'], $template['content'], $template['created_at']);
    $card->call('zapInvoice', $tournament->id, 2_100, 'GL', json_encode($signed))
        ->assertSeeHtml('data-test="topup-qr"')->assertSeeHtml('wire:poll');
    $payment = IncomingPayment::query()->sole();

    $league->settleIncoming($payment->payment_hash);
    $this->travel(5)->seconds();
    $card->call('checkInvoice')
        ->assertSee(__('✓ :sats sats arrived, thank you!', ['sats' => PreSeason::formatSats(2_100)]))
        ->assertDontSeeHtml('data-test="topup-qr"')->assertDontSeeHtml('wire:poll')
        ->assertDispatched('pot-filled');

    expect($payment->refresh()->status)->toBe(IncomingPaymentStatus::Settled)->and($payment->zap_verified)->toBeTrue();

    // The page around the card listens and renders the pot and the wall again, the zapper on it.
    $page = Livewire::test('pages::tournaments.show', ['tournament' => $tournament]);
    expect(array_map(fn (ReflectionAttribute $on): string => $on->newInstance()->event, (new ReflectionMethod($page->instance(), 'potFilled'))->getAttributes(On::class)))->toBe(['pot-filled']);
    $page->dispatch('pot-filled')->assertSeeHtml('data-test="pool-zappers"')->assertSeeHtml('data-pubkey="'.$signer->pubkey.'"');
});

test('the card asks the league wallet about its invoice at most every 5 seconds', function () {
    $league = fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $card = Livewire::test('tournament-pool', ['tournament' => $tournament])->set('amount', 2100)->call('topUp');

    $card->call('checkInvoice');
    expect(potLookups($league))->toBe(1);

    // Polled every 3 seconds, or called by hand: the wallet is not asked again within 5 seconds.
    $this->travel(4)->seconds();
    $card->call('checkInvoice')->call('checkInvoice');
    expect(potLookups($league))->toBe(1);

    $this->travel(1)->seconds();
    $card->call('checkInvoice');
    expect(potLookups($league))->toBe(2);
});

test('an invoice that ran out unpaid shows as expired and the card stops polling', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $card = Livewire::test('tournament-pool', ['tournament' => $tournament])->set('amount', 2100)->call('topUp');
    $payment = IncomingPayment::query()->sole();

    $this->travelTo($payment->expires_at->copy()->subSeconds(10));
    $card->call('checkInvoice')->assertSeeHtml('data-test="topup-qr"')->assertSeeHtml('wire:poll');

    // The wallet still calls it pending (it is expired here only after a grace); the card asks once more, then says so.
    $this->travelTo($payment->expires_at->copy()->addSeconds(1));
    $card->call('checkInvoice')
        ->assertSee(__('Invoice expired — create a new one'))
        ->assertDontSeeHtml('data-test="topup-qr"')->assertDontSeeHtml('wire:poll');
});
