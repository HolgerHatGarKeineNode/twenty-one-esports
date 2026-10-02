<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\TournamentPayout;
use App\Models\TournamentSponsor;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PotTopUps;
use App\Support\Prizes\PotZaps;
use App\Support\Prizes\PrizePool;
use App\Support\Prizes\ZapReceipts;
use App\Support\Prizes\ZapSponsors;
use App\Support\SeasonChain\LeagueKey;
use App\Support\Tournaments\TournamentPrizePool;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Wallet\Ledger;
use Livewire\Livewire;
use Tests\Support\Bolt11Fixture;
use Tests\Support\FakeNwcWallet;
use Tests\Support\TestSigner;

/*
| Zap sponsors (user, 2026-10-02: „auf der Turnierseite selbst können durch
| Zaps auf das Turnier selbst Sponsoren dazukommen. Die werden dann mit ihren
| Nostr Avataren stolz präsentiert. Diese zahlen oben drauf auf den Topf
| also +"): a NIP-57 zap to a tournament's calendar event goes through the
| league's LNURL endpoint into the tournament's pot in the league wallet;
| once paid, the league signs its receipt, and only a receipt that verifies
| counts on top of the pot and on the wall, each once. Sponsors entered by
| the organizer are part of the pot as announced.
*/

/** A zap request of `$signer` to the tournament's event, `$sats` sats. */
function potZapRequest(TestSigner $signer, Tournament $tournament, int $sats, string $comment = '', array $extra = []): SignedEvent
{
    return SignedEvent::fromInput($signer->sign(9734, [
        ['relays', 'wss://relay.example.org'], ['amount', (string) ($sats * 1000)], ['p', (string) LeagueKey::poolPubkey()],
        ['a', (string) $tournament->address()], ['k', '31923'], ...$extra,
    ], $comment, now()->getTimestamp()));
}

/** A zap paid the real way: the league's invoice for the request, settled, its receipt signed by the league. */
function paidPotZap(FakeNwcWallet $league, TestSigner $signer, Tournament $tournament, int $sats, string $comment = ''): IncomingPayment
{
    $request = potZapRequest($signer, $tournament, $sats, $comment);
    $payment = app(PoolInvoices::class)->forZapRequest($request, $request->toJson(), $sats);
    $league->settleIncoming($payment->payment_hash);

    return app(IncomingPayments::class)->check($payment, 0);
}

/**
 * A receipt as the LNURL server key signs it, for a request with `$requestAmount` msats and an invoice of `$invoiceAmount` msats.
 *
 * @param  array<string, mixed>  $change
 */
function constructedReceipt(Tournament $tournament, int $requestAmount, int $invoiceAmount, array $change = []): array
{
    $request = json_encode((new TestSigner)->sign(9734, [['amount', (string) $requestAmount], ['p', (string) LeagueKey::poolPubkey()], ['a', $change['request_a'] ?? (string) $tournament->address()]], '', now()->getTimestamp()));
    $invoice = Bolt11Fixture::make($invoiceAmount, $change['hash'] ?? hash('sha256', (string) $request))['invoice'];
    $signer = $change['signer'] ?? new TestSigner((string) config('esports.wallet.lnurl_nsec'));

    return $signer->sign(9735, [['p', (string) LeagueKey::poolPubkey()], ['a', $change['receipt_a'] ?? (string) $tournament->address()], ['bolt11', $invoice], ['description', (string) $request]], '', $change['at'] ?? now()->getTimestamp());
}

test('a zap to the tournament’s event lands in its pot, is receipted by the league, and shows on top with the zapper on the wall', function () {
    $league = fakeWallet();
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $tournament->forceFill(['prize_target_sats' => 100_000])->save();
    app(TournamentPublisher::class)->republish($tournament->refresh());
    $alice = new TestSigner;
    $bob = new TestSigner;
    $player = User::factory()->withPubkey($bob->pubkey)->create(['name' => 'bob_hodl']);

    // The calendar event routes zaps to the league's endpoint through the pool key (NIP-57 appendix G).
    expect(collect($tournament->refresh()->event->payload()['tags'])->firstWhere(0, 'zap'))->toBe(['zap', (string) LeagueKey::poolPubkey(), (string) (config('esports.relays')[0] ?? ''), '1']);

    $first = paidPotZap($league, $alice, $tournament, 21_000, 'For the winner');
    paidPotZap($league, $bob, $tournament, 5_000);
    paidPotZap($league, $bob, $tournament, 3_000);

    $receipt = SignedEvent::fromInput(json_decode((string) NostrEvent::query()->findOrFail($first->refresh()->receipt_event_id)->raw, true));
    expect($first->pot)->toBe($tournament->potAccount())
        ->and($first->status)->toBe(IncomingPaymentStatus::Settled)
        ->and($receipt->kind)->toBe(9735)->and($receipt->pubkey)->toBe(LeagueKey::lnurl()?->pubkey())
        ->and($receipt->tag('a'))->toBe($tournament->address())
        ->and(ZapReceipts::verify($receipt, $tournament, (string) LeagueKey::lnurl()?->pubkey(), (string) LeagueKey::poolPubkey()))->not->toBeNull()
        ->and(app(Ledger::class)->balance($tournament->potAccount()))->toBe(29_000);

    // On top of the 100 000 announced; the wall has the biggest zapper first.
    $wall = app(ZapSponsors::class)->wall($tournament);
    expect(app(PrizePool::class)->potSats($tournament))->toBe(129_000)
        ->and(array_map(fn (array $entry): array => [$entry['pubkey'], $entry['sats'], $entry['zaps']], $wall))->toBe([[$alice->pubkey, 21_000, 1], [$bob->pubkey, 8_000, 2]])
        ->and($wall[1]['user']?->id)->toBe($player->id)
        ->and(app(TournamentPrizePool::class)->for($tournament))->toMatchArray(['sats' => 129_000, 'base' => 100_000, 'zaps' => 29_000]);

    $this->get(route('tournaments.show', $tournament))->assertOk()
        ->assertSeeHtml('data-test="pool-sats">'.ShareCard::sats(129_000).'<')
        ->assertSeeHtml('data-test="pool-zaps-on-top"')->assertSee(__('+:sats sats from zaps on top', ['sats' => ShareCard::sats(29_000)]))
        ->assertSeeHtml('data-test="pool-zappers"')
        ->assertSeeHtml('data-pubkey="'.$alice->pubkey.'"')->assertSeeHtml('data-zapper-avatar="'.$alice->pubkey.'"')
        ->assertSeeHtml('data-avatar="'.$bob->pubkey.'"')->assertSee('bob_hodl')
        ->assertSee(__(':sats sats', ['sats' => ShareCard::sats(21_000)]))
        ->assertSeeHtml('data-test="pot-fill"')->assertSeeHtml('data-test="pool-fill"');
});

test('receipts are verified: a good one counts once, a wrong amount, signer, event, hash or a late one never', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $other = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $lnurl = (string) LeagueKey::lnurl()?->pubkey();
    $pool = (string) LeagueKey::poolPubkey();
    $verify = fn (array $receipt): ?array => ZapReceipts::verify($receipt, $tournament, $lnurl, $pool);

    $good = constructedReceipt($tournament, 21_000_000, 21_000_000, ['at' => now()->getTimestamp() - 60]);
    expect($verify($good))->toMatchArray(['id' => $good['id'], 'sats' => 21_000])
        // The bolt11 amount is not the request's amount.
        ->and($verify(constructedReceipt($tournament, 21_000_000, 20_000_000)))->toBeNull()
        // Signed by another key than the LNURL server's.
        ->and($verify(constructedReceipt($tournament, 21_000_000, 21_000_000, ['signer' => new TestSigner])))->toBeNull()
        // Another event: the request names another tournament, or the receipt is tagged to another one.
        ->and($verify(constructedReceipt($tournament, 21_000_000, 21_000_000, ['request_a' => (string) $other->address()])))->toBeNull()
        ->and($verify(constructedReceipt($tournament, 21_000_000, 21_000_000, ['receipt_a' => (string) $other->address()])))->toBeNull()
        // The invoice is not for this description.
        ->and($verify(constructedReceipt($tournament, 21_000_000, 21_000_000, ['hash' => str_repeat('0', 64)])))->toBeNull();

    // A duplicate counts once.
    expect(ZapSponsors::tally([json_encode($good), json_encode($good)], $tournament, $lnurl, $pool))->toHaveCount(1);

    // After the payout check the pot is closed: a receipt from then on is not on top of it.
    $tournament->forceFill(['pool_closed_at' => now()])->save();
    expect($verify(constructedReceipt($tournament, 21_000_000, 21_000_000, ['at' => now()->getTimestamp() + 1])))->toBeNull()
        ->and($verify($good))->not->toBeNull();
});

test('pot math: a sponsor is part of the pot as announced, zaps are on top, and the payout splits them like the pot', function () {
    $league = fakeWallet();
    fakeLightningAddresses($league);
    config(['esports.wallet.open_invoices_per_user' => 100]);
    $pool = app(PrizePool::class);

    // Percent, target 100 000: a sponsor's 100 000 by invoice fills it; a zap of 10 000 adds on top.
    $percent = finishedPoolTournament($league, 0, 2);
    $percent->forceFill(['prize_target_sats' => 100_000, 'pool_closed_at' => null])->save();
    $sponsor = TournamentSponsor::query()->create(['tournament_id' => $percent->id, 'name' => 'Hodl Bakery', 'pledged_sats' => 100_000]);
    $invoice = app(PotTopUps::class)->sponsorInvoice($sponsor, anAdmin());
    $league->settleIncoming($invoice->payment_hash);
    app(PotTopUps::class)->check($invoice, 0);
    expect($pool->potSats($percent->refresh()))->toBe(100_000);

    paidPotZap($league, new TestSigner, $percent, 10_000);
    expect($pool->potSats($percent))->toBe(110_000)->and($pool->fundedSats($percent))->toBe(110_000);

    app(PayoutApproval::class)->approve($percent, anAdmin());
    expect((int) $percent->payouts()->sum('amount_sats'))->toBe(intdiv(PrizePool::afterFeeReserve(110_000) * 50, 100) + intdiv(PrizePool::afterFeeReserve(110_000) * 30, 100));

    // Fixed 60 000 / 30 000: funded with their fee reserve, plus a zap of 10 000 split 2 : 1 after its own reserve.
    $fixed = finishedPoolTournament($league, 90_900, 2, fixed: [60_000, 30_000]);
    $fixed->forceFill(['pool_closed_at' => null])->save();
    paidPotZap($league, new TestSigner, $fixed, 10_000);
    $bonus = PrizePool::zapBonus([60_000, 30_000], 10_000);

    expect($bonus)->toBe([6_600, 3_300])
        ->and($pool->potSats($fixed->refresh()))->toBe(100_000)
        ->and(array_column($pool->projection($fixed), 'sats'))->toBe([66_600, 33_300])
        ->and(PrizePool::shortfall($fixed, $pool->fundedSats($fixed), $pool->zapSats($fixed)))->toBe(0);

    app(PayoutApproval::class)->approve($fixed, anAdmin());

    foreach ($fixed->payouts()->get() as $payout) {
        app(PayoutRunner::class)->run($payout, true);
    }

    expect($fixed->payouts()->orderBy('place')->get()->map(fn (TournamentPayout $p): array => [$p->place, $p->amount_sats, $p->status->value])->all())
        ->toBe([[1, 66_600, 'paid'], [2, 33_300, 'paid']])
        ->and(app(Ledger::class)->balance($fixed->potAccount()))->toBeGreaterThanOrEqual(0);

    // Zaps never fund the fixed prizes themselves: without the base, the pot is short.
    $short = finishedPoolTournament($league, 0, 2, fixed: [60_000, 30_000]);
    $short->forceFill(['pool_closed_at' => null])->save();
    paidPotZap($league, new TestSigner, $short, 95_000);
    expect(PrizePool::shortfall($short->refresh(), $pool->fundedSats($short), $pool->zapSats($short)))->toBe(90_900);
});

test('a zap paid after the payout check goes to the league reserve and is not on top', function () {
    $league = fakeWallet();
    fakeLightningAddresses($league);
    $tournament = finishedPoolTournament($league, 10_000, 2);
    $tournament->forceFill(['pool_closed_at' => null])->save();
    $request = potZapRequest(new TestSigner, $tournament, 4_000);
    $late = app(PoolInvoices::class)->forZapRequest($request, $request->toJson(), 4_000);

    app(PayoutApproval::class)->approve($tournament->refresh(), anAdmin());
    $this->travel(1)->minutes();
    $league->settleIncoming($late->payment_hash);
    app(IncomingPayments::class)->check($late, 0);

    expect($late->refresh()->late)->toBeTrue()
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(4_000)
        ->and(app(ZapSponsors::class)->zapSats($tournament->refresh()))->toBe(0)
        ->and(collect($tournament->event->payload()['tags'])->where(0, 'zap'))->toBeEmpty();
});

test('a signed-in player zaps the pot from its page: the request they sign names the tournament, and the invoice is the league’s', function () {
    $league = fakeWallet();
    [$player, $signer] = keyedPlayer();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $page = Livewire::actingAs($player)->test('tournament-pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pot-fill"')->assertSeeHtml('data-test="pot-zap-preview"')->assertSeeHtml('data-test="pot-zap-comment"');

    $template = $page->instance()->prepareZap($tournament->id, 2_100, 'GL', app(PotZaps::class))['template'];
    expect(collect($template['tags'])->firstWhere(0, 'a'))->toBe(['a', $tournament->address()])
        ->and(collect($template['tags'])->firstWhere(0, 'p'))->toBe(['p', LeagueKey::poolPubkey()])
        ->and(collect($template['tags'])->firstWhere(0, 'amount'))->toBe(['amount', '2100000']);

    $signed = $signer->sign($template['kind'], $template['tags'], $template['content'], $template['created_at']);
    $answer = $page->instance()->zapInvoice($tournament->id, 2_100, 'GL', json_encode($signed), app(PotZaps::class));
    $payment = IncomingPayment::query()->sole();

    expect($answer)->toHaveKeys(['invoice', 'qr'])->and($answer['invoice'])->toBe($payment->bolt11)
        ->and($payment->pot)->toBe($tournament->potAccount())->and($payment->payer_pubkey)->toBe($player->pubkey)
        ->and($league->invoices)->toHaveKey($payment->payment_hash);

    // A request that differs from the one prepared is refused.
    $forged = $signer->sign(9734, [...$template['tags'], ['e', str_repeat('ab', 32)]], $template['content'], $template['created_at']);
    expect($page->instance()->zapInvoice($tournament->id, 2_100, 'GL', json_encode($forged), app(PotZaps::class)))->toHaveKey('error');

    // A guest pays without Nostr or logs in to zap; never an address as text.
    auth()->logout();
    Livewire::test('tournament-pool', ['tournament' => $tournament])->assertSeeHtml('data-test="topup"')->assertSeeHtml('data-test="pot-zap-login"')
        ->assertDontSeeHtml('data-test="pot-zap-preview"')->assertDontSee(PoolInvoices::address());
});
