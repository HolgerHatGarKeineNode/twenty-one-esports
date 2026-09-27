<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Lightning\Bolt11;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutApproval;
use App\Support\PreSeason;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PrizePool;
use App\Support\Wallet\Ledger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestSigner;

/*
| P9 pages: the pool on the tournament page (total from paid invoices, the
| split, paid sponsors, the zap panel), the pool settings of the organizer,
| and the admins' payouts page.
*/

test('a guest zaps the pool without Nostr and the page counts it once it is paid', function () {
    $wallet = fakeWallet();
    $tournament = runningChess(TournamentFormat::SingleElimination, 4);
    publishForPool($tournament);

    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSeeHtml('data-test="pool-sats">0<');

    $panel = Livewire::test('tournament-pool', ['tournament' => $tournament->refresh()])
        ->set('amount', 21000)
        ->set('comment', 'Go go go')
        ->call('zapAnonymously')
        ->assertSeeHtml('data-test="zap-bolt11"');

    $payment = IncomingPayment::query()->sole();
    expect($payment->pot)->toBe('tournament:'.$tournament->id)
        ->and($payment->source)->toBe('anonymous')
        ->and($payment->amount_sats)->toBe(21000)
        ->and($payment->comment)->toBe('Go go go');

    // Not paid yet: nothing counted.
    $this->travel(5)->seconds();
    $panel->call('checkInvoice')->assertDontSeeHtml('data-test="zap-received"');

    $wallet->settleIncoming($payment->payment_hash);
    $this->travel(5)->seconds();
    $panel->call('checkInvoice')->assertSeeHtml('data-test="zap-received"')->assertSee(PreSeason::formatSats(21000).' sats');
    $this->get(route('tournaments.show', $tournament))->assertSeeHtml('data-test="pool-sats">'.ShareCard::sats(21000).'<');

    // The receipt (9735) is signed by the LNURL server key and names the tournament.
    $receipt = SignedEvent::fromInput(NostrEvent::query()->where('kind', 9735)->sole()->payload());
    expect($receipt->hasValidSignature())->toBeTrue()
        ->and($receipt->tag('a'))->toBe($tournament->refresh()->address())
        ->and(hash('sha256', (string) $receipt->tag('description')))->toBe(Bolt11::decode((string) $receipt->tag('bolt11'))?->descriptionHash)
        ->and(hash('sha256', (string) hex2bin((string) $receipt->tag('preimage'))))->toBe($payment->payment_hash);
});

test('a player zaps with their own key, and a request signed by another key is refused', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $signer = new TestSigner;
    $player = User::factory()->withPubkey($signer->pubkey)->create();

    $panel = Livewire::actingAs($player)->test('tournament-pool', ['tournament' => $tournament])->set('amount', 2100);
    $templates = $panel->instance()->prepareZap(app(PoolInvoices::class));

    $foreign = json_encode((new TestSigner)->signTemplates($templates));
    $panel->call('submitZap', $foreign)->assertHasErrors('zap');
    expect(IncomingPayment::query()->count())->toBe(0);

    $panel->call('submitZap', json_encode($signer->signTemplates($templates)))->assertHasNoErrors()->assertSeeHtml('data-test="zap-bolt11"');
    expect(IncomingPayment::query()->sole())->payer_pubkey->toBe($signer->pubkey)->source->toBe('zap');
});

test('without a receiving wallet no invoice is made and the panel says why', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    config(['esports.wallet.nwc_receive_uri' => null]);

    Livewire::test('tournament-pool', ['tournament' => $tournament])->call('zapAnonymously')->assertHasErrors('zap');
    expect(IncomingPayment::query()->count())->toBe(0);
});

test('the organizer opens the pool, sets the split and a sponsor whose logo shows once paid', function () {
    Storage::fake('public');
    $wallet = fakeWallet();
    $tournament = openTournament();
    $organizer = $tournament->creator;

    $page = Livewire::actingAs($organizer)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->call('openPool')->assertHasNoErrors();
    $tournament->refresh();

    // The pool opened with a new 31923 version that names the pool key in `zap`.
    expect($tournament->pool_opened_at)->not->toBeNull()
        ->and($tournament->event->payload()['tags'])->toContain(['zap', PrizePool::poolPubkey(), '', '1']);

    $page->assertSet('potEnabled', true)->assertSet('potSource', Tournament::POT_LEAGUE);
    $page->set('potSplit', [60, 40, 10])->call('savePotSettings')->assertNotSet('potError', '');
    $page->set('potSplit', [30, 70])->call('savePotSettings')->assertNotSet('potError', '');
    $page->set('potSplit', [70, 30])->set('potTarget', '500000')->call('savePotSettings')->assertSet('potError', '');
    expect($tournament->refresh()->prizeSplit())->toBe([70, 30])
        ->and($tournament->prize_target_sats)->toBe(500000)
        ->and($tournament->event->payload()['content'])->toContain('place 1 70 %');

    $page->set('sponsorName', 'Satoshi’s Pizza')->set('sponsorSats', '50000')->set('sponsorLogo', UploadedFile::fake()->image('logo.png', 600, 200))
        ->call('addSponsor')->assertHasNoErrors();
    $sponsor = $tournament->sponsors()->sole();
    expect($sponsor->logo_path)->toStartWith('sponsor-logos/');
    Storage::disk('public')->assertExists($sponsor->logo_path);

    // A pledge alone shows nothing on the tournament page.
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertDontSee('Satoshi’s Pizza');

    $page->call('sponsorInvoice', $sponsor->id)->assertSeeHtml('data-test="sponsor-invoice"');
    $payment = IncomingPayment::query()->sole();
    expect($payment->source)->toBe('sponsor')->and($payment->sponsor_id)->toBe($sponsor->id)
        ->and(json_decode((string) $payment->zap_request, true)['content'])->toBe('Sponsor: Satoshi’s Pizza');

    $wallet->settleIncoming($payment->payment_hash);
    $this->travel(10)->seconds();
    $page->call('checkInvoice');

    $this->get(route('tournaments.show', $tournament))->assertSee('Satoshi’s Pizza')->assertSeeHtml('data-test="pool-sats">'.ShareCard::sats(50000).'<');
});

test('the split is frozen once sign-up closed, and only the organizer or an admin manages the pool', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $tournament->forceFill(['created_by_id' => organizer()->id])->save();

    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->set('potSplit', [100])->call('savePotSettings')->assertNotSet('potError', '');
    expect($tournament->refresh()->prizeSplit())->toBe(Tournament::DEFAULT_SPLIT);

    $this->actingAs(User::factory()->create())->get(route('tournaments.pool', $tournament))->assertForbidden();
    $this->actingAs(organizer())->get(route('tournaments.pool', $tournament))->assertForbidden();
    $this->actingAs(anAdmin())->get(route('tournaments.pool', $tournament))->assertOk();
});

test('the payouts page is for admins, says when no wallet is set up, and pays through the fake wallet', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 20_000, 2);
    $admin = anAdmin();

    $this->actingAs($tournament->creator ?? organizer())->get(route('admin.payouts'))->assertForbidden();

    config(['esports.wallet.nwc_uri' => null]);
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSee('ESPORTS_NWC_URI')
        ->assertDontSeeHtml('data-test="approve-payouts"')
        ->call('approve')->assertHasErrors('payouts');

    config(['esports.wallet.nwc_uri' => $wallet->uri('pay')]);
    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="payouts-preview"')
        ->call('approve')->assertHasNoErrors();

    $winner = $tournament->payouts()->where('place', 1)->sole();
    $page->call('pay', $winner->id)->call('pay', $winner->id)->call('payAll');

    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($wallet->payRequests())->toHaveCount(2);

    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSeeHtml('data-test="pool-payouts"');
});

test('a payment that settles after the pool closed goes to the reserve, not to the tournament', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    $late = app(PoolInvoices::class)->anonymousZap($tournament, 5_000, '');

    app(PayoutApproval::class)->approve($tournament, anAdmin());
    // 50 + 30 % of 10 000 are paid out; the other 2 000 went to the reserve at the check.
    expect(app(Ledger::class)->balance('reserve'))->toBe(2_000);
    $this->travel(1)->minutes();
    $wallet->settleIncoming($late->payment_hash);
    app(IncomingPayments::class)->check($late, 0);

    expect($late->refresh()->status)->toBe(IncomingPaymentStatus::Settled)
        ->and($late->late)->toBeTrue()
        ->and(app(PrizePool::class)->fundedSats($tournament))->toBe(10_000)
        ->and(app(Ledger::class)->balance('reserve'))->toBe(7_000);
});
