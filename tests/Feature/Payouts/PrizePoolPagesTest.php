<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Cards\ShareCard;
use App\Support\Payouts\PayoutApproval;
use App\Support\PreSeason;
use App\Support\Prizes\PotTopUps;
use App\Support\Wallet\Ledger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| P9 pages: the pot on the tournament page (the pot as set, the prizes, paid
| sponsors, "Add to the pot"), the pool settings of the organizer, and the
| admins' payouts page. Every pot is booked in the league wallet (user,
| 2026-10-02).
*/

test('anyone adds sats to the pot: the league wallet makes the invoice, shown as a QR code, and the pot grows once it is paid', function () {
    fakeWallet();
    $pot = ownPotWallet(0);
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4), $pot);

    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSeeHtml('data-test="pool-sats">0<');

    $panel = Livewire::test('tournament-pool', ['tournament' => $tournament->refresh()])
        ->set('amount', 21000)
        ->call('topUp')
        ->assertSeeHtml('data-test="topup-qr"')
        ->assertSeeHtml('<svg');

    $payment = IncomingPayment::query()->sole();
    expect($payment->pot)->toBe('tournament:'.$tournament->id)
        ->and($payment->source)->toBe('topup')
        ->and($payment->amount_sats)->toBe(21000)
        ->and(collect($pot->calls)->pluck('method')->all())->toContain('make_invoice');
    // The invoice is a QR code and a button, never text on the page.
    $panel->assertDontSeeHtml('>'.$payment->bolt11.'<');

    // Not paid yet: nothing counted.
    $this->travel(5)->seconds();
    $panel->call('checkInvoice')->assertDontSeeHtml('data-test="topup-received"');

    $pot->settleIncoming($payment->payment_hash);
    $this->travel(5)->seconds();
    $panel->call('checkInvoice')->assertSeeHtml('data-test="topup-received"')->assertSee(PreSeason::formatSats(21000).' sats');
    $this->get(route('tournaments.show', $tournament))->assertSeeHtml('data-test="pool-sats">'.ShareCard::sats(21000).'<');

    // Booked for this pot; a plain top-up gets no zap receipt (it carries no zap request).
    expect(app(Ledger::class)->balance($tournament->potAccount()))->toBe(21000)
        ->and(NostrEvent::query()->where('kind', 9735)->count())->toBe(0);
});

test('top-ups are hidden when the league wallet cannot make invoices', function () {
    fakeWallet();
    $tournament = publishForPool(openTournament());
    config(['esports.wallet.nwc_receive_uri' => null]);

    Livewire::test('tournament-pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="topup-off"')->assertSee(__('Top-ups not enabled for this pot.'))
        ->assertDontSeeHtml('data-test="topup"')
        ->call('topUp')->assertHasErrors('topup');
    expect(IncomingPayment::query()->count())->toBe(0);

    // The league wallet connected again: top-ups are on, no new save needed.
    fakeWallet();
    Livewire::test('tournament-pool', ['tournament' => $tournament])->assertDontSeeHtml('data-test="topup-off"');
});

test('the organizer sets the prizes and a sponsor whose invoice comes from the league wallet and whose logo shows once paid', function () {
    Storage::fake('public');
    fakeWallet();
    $pot = ownPotWallet(0);
    $tournament = openTournament();
    $organizer = $tournament->creator;

    $page = Livewire::actingAs($organizer)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->assertSeeHtml('data-test="pool-no-pot"')
        ->assertDontSeeHtml('data-test="pot-source-league"')
        ->set('potEnabled', true)->call('savePotSettings')->assertSet('potError', '');
    $tournament->refresh();

    // Published already: the pot opened at once, with a new 31923 that names the prizes and has no `zap` tag.
    expect($tournament->pool_opened_at)->not->toBeNull()
        ->and(collect($tournament->event->payload()['tags'])->where(0, 'zap'))->toBeEmpty()
        ->and($tournament->event->payload()['content'])->toContain('league wallet');

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

    // A pledge alone shows nothing on the tournament page.
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertDontSee('Satoshi’s Pizza');

    $page->call('sponsorInvoice', $sponsor->id)->assertSeeHtml('data-test="sponsor-qr"');
    $payment = IncomingPayment::query()->sole();
    expect($payment->source)->toBe('sponsor')->and($payment->sponsor_id)->toBe($sponsor->id)->and($payment->zap_request)->toBeNull();

    $pot->settleIncoming($payment->payment_hash);
    $this->travel(10)->seconds();
    $page->call('checkInvoice');

    $this->get(route('tournaments.show', $tournament))->assertSee('Satoshi’s Pizza')->assertSeeHtml('data-test="pool-sats">'.ShareCard::sats(500000).'<');
});

test('the prizes are frozen once sign-up closed, and only the organizer or an admin manages the pot', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $tournament->forceFill(['created_by_id' => organizer()->id])->save();

    Livewire::actingAs($tournament->creator)->test('pages::tournaments.pool', ['tournament' => $tournament])
        ->set('potSplit', [100])->call('savePotSettings')->assertNotSet('potError', '')
        ->call('usePotMode', 'fixed')->call('savePotSettings')->assertNotSet('potError', '');
    expect($tournament->refresh()->prizeSplit())->toBe(Tournament::DEFAULT_SPLIT)->and($tournament->prizeMode())->toBe(Tournament::PRIZES_PERCENT);

    $this->actingAs(User::factory()->create())->get(route('tournaments.pool', $tournament))->assertForbidden();
    $this->actingAs(organizer())->get(route('tournaments.pool', $tournament))->assertForbidden();
    $this->actingAs(anAdmin())->get(route('tournaments.pool', $tournament))->assertOk();
});

test('the payouts page is for admins, says when the league wallet is missing, and pays through it', function () {
    fakeWallet();
    $pot = ownPotWallet(0);
    fakeLightningAddresses($pot);
    $tournament = finishedPoolTournament($pot, 20_000, 2);
    $admin = anAdmin();
    $uri = config('esports.wallet.nwc_uri');

    $this->actingAs($tournament->creator ?? organizer())->get(route('admin.payouts'))->assertForbidden();

    config(['esports.wallet.nwc_uri' => 'not a connection']);
    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSee(__('The league wallet is not connected, so nothing can be paid out.'))
        ->assertDontSeeHtml('data-test="approve-payouts"')
        ->assertDontSeeHtml('data-test="wallet-panel"')
        ->call('approve')->assertHasErrors('payouts');

    config(['esports.wallet.nwc_uri' => $uri]);
    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])
        ->assertSeeHtml('data-test="payouts-preview"')
        ->call('approve')->assertHasNoErrors();

    $winner = $tournament->payouts()->where('place', 1)->sole();
    $page->call('pay', $winner->id)->call('pay', $winner->id)->call('payAll');

    expect($tournament->payouts()->pluck('status')->unique()->all())->toBe([PayoutStatus::Paid])
        ->and($pot->payRequests())->toHaveCount(2);

    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSeeHtml('data-test="pool-payouts"');
});

test('a top-up that settles after the pot closed is marked late and changes no prize', function () {
    fakeWallet();
    $pot = ownPotWallet(0);
    fakeLightningAddresses($pot);
    $tournament = finishedPoolTournament($pot, 10_000, 2);
    $late = app(PotTopUps::class)->invoice($tournament, 5_000);

    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $prizes = (int) $tournament->payouts()->sum('amount_sats');
    $this->travel(1)->minutes();
    $pot->settleIncoming($late->payment_hash);
    app(PotTopUps::class)->check($late, 0);

    // Booked to the league reserve, not to the closed pot.
    expect($late->refresh()->status)->toBe(IncomingPaymentStatus::Settled)
        ->and($late->late)->toBeTrue()
        ->and(app(Ledger::class)->credited($tournament->potAccount()))->toBe(10_000)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(5_000)
        ->and((int) $tournament->payouts()->sum('amount_sats'))->toBe($prizes);
});
