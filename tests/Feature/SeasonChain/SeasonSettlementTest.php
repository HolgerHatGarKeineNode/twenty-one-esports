<?php

/*
 * P37 DoD: the settlement of an ended chain season. The chain is replayed as
 * /mining replays it, voided blocks come off, an admin approves the list and
 * pays per click from the league wallet's paying connection, exactly once,
 * with a Payout (2157) per player. No fees, no balance pre-check, a 72 h
 * freeze after a Lightning address change, and the claim window.
 */

use App\Enums\NotificationKind;
use App\Enums\PayoutStatus;
use App\Jobs\PaySeasonPayout;
use App\Models\AccountLink;
use App\Models\LedgerTransfer;
use App\Models\NostrEvent;
use App\Models\Season;
use App\Models\SeasonAttestation;
use App\Models\SeasonPayout;
use App\Models\User;
use App\Support\Nostr\ProfileCache;
use App\Support\Nostr\SignedEvent;
use App\Support\Payouts\PayoutRunner;
use App\Support\PreSeason;
use App\Support\SeasonChain\SeasonChains;
use App\Support\SeasonChain\SeasonSettlement;
use App\Support\SeasonChain\SeasonSettlementRefused;
use App\Support\SeasonChain\Settlement;
use App\Support\Wallet\Ledger;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\Support\TestSigner;

test('the review replays the chain: per player the mined sats of their blocks, and never the address itself', function () {
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob', null);
    $bob->forceFill(['lud16' => null])->save();
    $season = settledSeason([[$alice, 1], [$alice, 2], [$bob, 3]]);
    $admin = aBoardMember();

    $chain = app(SeasonChains::class)->chain($season);
    $review = app(SeasonSettlement::class)->review($season);
    $rows = collect($review['rows'])->keyBy('name');

    expect($chain->blocks())->toHaveCount(3)
        ->and($review['mismatch'])->toBeNull()
        ->and($review['mined'])->toBe($chain->mined())
        ->and($review['payout'])->toBe($chain->mined())
        ->and($rows['Alice']['blocks'])->toBe(2)
        ->and($rows['Alice']['heights'])->toBe([1, 2])
        ->and($rows['Alice']['payout'])->toBe($chain->blocks()[0]->rewardPerPlayer + $chain->blocks()[1]->rewardPerPlayer)
        ->and($rows['Bob']['payout'])->toBe($chain->blocks()[2]->rewardPerPlayer)
        ->and($rows['Alice']['has_address'])->toBeTrue()
        ->and($rows['Bob']['has_address'])->toBeFalse();

    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])
        ->assertSee('Alice')
        ->assertSee('has address')
        ->assertSee('missing')
        ->assertDontSee('alice@wallet.example');
});

test('a void takes the block off its player\'s payout and publishes a void-block label with its reason, audited', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1], [$alice, 2]]);
    $admin = aBoardMember();
    $before = app(SeasonSettlement::class)->review($season)['rows'][0]['payout'];

    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])
        ->set('voidHeight', '2')->set('voidReason', 'Second win of the same pairing within five minutes.')
        ->call('void')
        ->assertSet('error', '')
        ->assertSee('Block 2 void');

    $void = $season->blockVoids()->sole();
    $label = SignedEvent::fromInput(json_decode(NostrEvent::query()->findOrFail($void->nostr_event_id)->raw, true));
    $after = app(SeasonSettlement::class)->review($season)['rows'][0];
    $block2 = SeasonAttestation::query()->where('season_id', $season->id)->where('height', 2)->sole();

    expect($after['payout'])->toBeLessThan($before)
        ->and($after['payout'])->toBe($before - $after['voided'])
        ->and($after['heights'])->toBe([1])
        ->and($void->voided_by_pubkey)->toBe($admin->pubkey)
        ->and($label->kind)->toBe(1985)
        ->and($label->hasValidSignature())->toBeTrue()
        ->and($label->pubkey)->toBe($season->league_pubkey)
        ->and($label->tagsNamed('l')[0])->toBe(['void-block', 'space.einundzwanzig.esports'])
        ->and($label->tag('L'))->toBe('space.einundzwanzig.esports')
        ->and($label->tag('e'))->toBe($block2->event_id)
        ->and($label->content)->toBe('Second win of the same pairing within five minutes.');

    // Twice, without a reason, a block that does not exist: refused.
    $settlement = app(SeasonSettlement::class);
    expect(fn () => $settlement->void($season, $admin, 2, 'Again'))->toThrow(SeasonSettlementRefused::class, 'void already')
        ->and(fn () => $settlement->void($season, $admin, 1, '  '))->toThrow(SeasonSettlementRefused::class, 'Say why')
        ->and(fn () => $settlement->void($season, $admin, 9, 'No such block'))->toThrow(SeasonSettlementRefused::class, 'no block 9');
});

test('no block is voided before the season ends or after the list is approved', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1], [$alice, 2]]);
    $admin = aBoardMember();
    $settlement = app(SeasonSettlement::class);

    $season->forceFill(['ends_at' => now()->addDay()])->save();
    expect(fn () => $settlement->void($season, $admin, 1, 'Too early'))->toThrow(SeasonSettlementRefused::class, 'after the season has ended');

    $season->forceFill(['ends_at' => now()->subHour()])->save();
    $settlement->approve($season, $admin);
    expect(fn () => $settlement->void($season->refresh(), $admin, 1, 'Too late'))->toThrow(SeasonSettlementRefused::class, 'review is closed')
        ->and($season->blockVoids()->count())->toBe(0);
});

test('the approval writes one payout per player with sats, once, and nothing is paid', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $season = settledSeason([[$alice, 1], [$bob, 2], [$bob, 3]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->void($season, $admin, 1, 'Farmed');

    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])
        ->call('approve')
        ->assertSet('error', '')
        ->assertSee('Pay all ready (1)')
        ->call('approve')
        ->assertSee('approved already');

    expect(SeasonPayout::query()->count())->toBe(1)
        ->and(payoutOf($season, $bob)->status)->toBe(PayoutStatus::Pending)
        ->and(payoutOf($season, $bob)->heights)->toBe([2, 3])
        ->and(payoutOf($season, $bob)->lud16)->toBe('bob@wallet.example')
        ->and($season->refresh()->settlement_approved_by_id)->toBe($admin->id)
        ->and($wallet->payRequests())->toBe([]);
});

test('a payout is paid once through the league wallet, also on a double click and a retried job, booked once', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1], [$alice, 2]]);
    app(SeasonSettlement::class)->approve($season, aBoardMember());
    $payout = payoutOf($season, $alice);

    PaySeasonPayout::dispatch($payout->id);
    PaySeasonPayout::dispatch($payout->id);
    (new PaySeasonPayout($payout->id))->handle(app(PayoutRunner::class));
    (new PaySeasonPayout($payout->id, false))->handle(app(PayoutRunner::class));

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($wallet->payRequests())->toBe([$payout->payment_hash])
        ->and(NostrEvent::query()->where('kind', 2157)->count())->toBe(1)
        ->and(LedgerTransfer::query()->where('season_payout_id', $payout->id)->where('reason', 'season_payout')->sole()->sats)->toBe($payout->amount_sats)
        ->and(LedgerTransfer::query()->where('season_payout_id', $payout->id)->where('reason', 'season_payout_fee')->sole()->sats)->toBe(1)
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(-$payout->amount_sats - 1);
});

test('two attempts at the same moment: the one holding the lease pays, the other returns without a payment', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1]]);
    app(SeasonSettlement::class)->approve($season, aBoardMember());
    $payout = payoutOf($season, $alice);

    $payout->forceFill(['lease_until' => now()->addMinute(), 'lease_owner' => 'first'])->save();
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Pending)
        ->and($wallet->payRequests())->toBe([]);
});

test('the Payout 2157 names the genesis, the player, each paid block and the invoice with its preimage, and no fee', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1], [$alice, 2], [$alice, 3]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->void($season, $admin, 2, 'Farmed');
    app(SeasonSettlement::class)->approve($season, $admin);
    $payout = payoutOf($season, $alice);
    app(PayoutRunner::class)->run($payout, true);
    $payout->refresh();

    $event = SignedEvent::fromInput(json_decode(NostrEvent::query()->findOrFail($payout->event_id)->raw, true));
    $blocks = SeasonAttestation::query()->where('season_id', $season->id)->whereIn('height', [1, 3])->orderBy('height')->pluck('event_id')->all();
    $relay = (string) (config('esports.relays')[0] ?? '');

    expect($event->kind)->toBe(2157)
        ->and($event->hasValidSignature())->toBeTrue()
        ->and($event->pubkey)->toBe($season->league_pubkey)
        ->and($event->content)->toBe('')
        ->and(array_map(fn (array $tag): string => $tag[0], $event->tags))->toBe(['e', 'p', 'e', 'e', 'bolt11', 'preimage', 'alt'])
        ->and($event->tags[0])->toBe(['e', $season->genesisId(), $relay])
        ->and($event->tags[1])->toBe(['p', $alice->pubkey])
        ->and([$event->tags[2][1], $event->tags[3][1]])->toBe($blocks)
        ->and($event->tag('bolt11'))->toBe($payout->bolt11)
        ->and(hash('sha256', (string) hex2bin((string) $event->tag('preimage'))))->toBe($payout->payment_hash)
        ->and($payout->amount_sats)->toBe(app(SeasonChains::class)->chain($season)->blocks()[0]->rewardPerPlayer + app(SeasonChains::class)->chain($season)->blocks()[2]->rewardPerPlayer);
});

test('no fees: the settlement gets no fee receipt, so every payout is the mined sats less the voided ones', function () {
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1], [$alice, 2]]);

    $review = app(SeasonSettlement::class)->review($season);
    $computed = Settlement::compute(app(SeasonSettlement::class)->chain($season), []);

    expect(array_column($computed['players'], 'fees'))->toBe([0])
        ->and($computed['fees_per_block'])->toBe([])
        ->and($review['rows'][0]['payout'])->toBe($review['rows'][0]['mined'] - $review['rows'][0]['voided']);

    // The only caller hands Settlement no fees; nothing else in the app computes a settlement.
    $callers = collect(File::allFiles(app_path()))->filter(fn ($file): bool => str_contains($file->getContents(), 'Settlement::compute('))
        ->map(fn ($file): string => str_replace(base_path().'/', '', $file->getPathname()))->values()->all();
    expect($callers)->toBe(['app/Support/SeasonChain/SeasonSettlement.php'])
        ->and(File::get(app_path('Support/SeasonChain/SeasonSettlement.php')))->toContain('Settlement::compute($chain, [])');
});

test('a wallet that cannot pay fails the payout with the top-up hint, and a retry after the top-up pays it', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->approve($season, $admin);
    $payout = payoutOf($season, $alice);
    $wallet->balanceMsats = 1_000;

    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])
        ->call('pay', $payout->id)
        ->call('$refresh')
        ->assertSee('Top up the payout wallet')
        ->assertSee('Retry');

    expect($payout->refresh()->status)->toBe(PayoutStatus::Failed)
        ->and($payout->reason)->toBe('insufficient_balance')
        ->and(NostrEvent::query()->where('kind', 2157)->count())->toBe(0)
        ->and(LedgerTransfer::query()->count())->toBe(0);

    $wallet->balanceMsats = 50_000_000_000;
    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])->call('pay', $payout->id);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->attempts)->toBe(2)
        ->and(NostrEvent::query()->where('kind', 2157)->count())->toBe(1);
});

test('a Lightning address changed less than 72 hours ago waits, and an admin approves it only after the freeze', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $alice->forceFill(['lud16_changed_at' => now()->subHours(10)])->save();
    $season = settledSeason([[$alice, 1]]);
    $admin = aBoardMember();
    $settlement = app(SeasonSettlement::class);
    $settlement->approve($season, $admin);
    $payout = payoutOf($season, $alice);
    $fingerprint = SeasonSettlement::fingerprint('alice@wallet.example');

    expect($payout->status)->toBe(PayoutStatus::Open)
        ->and($payout->reason)->toBe('lud16_frozen')
        ->and($payout->lud16)->toBeNull()
        ->and(fn () => $settlement->approveAddress($payout, $admin, $fingerprint))->toThrow(SeasonSettlementRefused::class, 'less than 72 hours');

    app(PayoutRunner::class)->run($payout, true);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)->and($wallet->payRequests())->toBe([]);

    $this->travel(63)->hours();
    expect(fn () => $settlement->approveAddress($payout, $admin, 'another-address'))->toThrow(SeasonSettlementRefused::class, 'changed again');

    $settlement->approveAddress($payout, $admin, $fingerprint);
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->lud16)->toBe('alice@wallet.example');
});

test('an address changed after the approval is not paid: the payout waits for the freeze and an admin', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->approve($season, $admin);
    $payout = payoutOf($season, $alice);

    $alice->forceFill(['lud16' => 'thief@wallet.example', 'lud16_changed_at' => now()])->save();
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)
        ->and($payout->reason)->toBe('lud16_changed')
        ->and($wallet->payRequests())->toBe([])
        ->and(fn () => app(SeasonSettlement::class)->approveAddress($payout, $admin, SeasonSettlement::fingerprint('thief@wallet.example')))
        ->toThrow(SeasonSettlementRefused::class, 'less than 72 hours');
});

test('an approved address is not paid while the profile changed it within 72 hours, even back to the same address', function () {
    $wallet = settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1]]);
    app(SeasonSettlement::class)->approve($season, aBoardMember());
    $payout = payoutOf($season, $alice);

    // Changed away and back: the address matches the approved one, but it moved an hour ago.
    $alice->forceFill(['lud16_changed_at' => now()->subHour()])->save();
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)
        ->and($payout->reason)->toBe('lud16_frozen')
        ->and($wallet->payRequests())->toBe([]);
});

test('a changed Lightning address in a newer profile starts the freeze; the first cached profile does not', function () {
    $signer = new TestSigner;
    $user = User::factory()->create(['pubkey' => $signer->pubkey, 'lud16' => null, 'profile_event_at' => null]);

    ProfileCache::apply($user, $signer->sign(0, [], json_encode(['name' => 'Alice', 'lud16' => 'alice@wallet.example']), now()->subMinutes(5)->getTimestamp()));
    expect($user->refresh()->lud16)->toBe('alice@wallet.example')->and($user->lud16_changed_at)->toBeNull();

    ProfileCache::apply($user, $signer->sign(0, [], json_encode(['name' => 'Alice', 'lud16' => 'alice@wallet.example']), now()->subMinutes(4)->getTimestamp()));
    expect($user->refresh()->lud16_changed_at)->toBeNull();

    ProfileCache::apply($user, $signer->sign(0, [], json_encode(['name' => 'Alice', 'lud16' => 'other@wallet.example']), now()->subMinutes(3)->getTimestamp()));
    expect($user->refresh()->lud16)->toBe('other@wallet.example')
        ->and($user->lud16_changed_at)->not->toBeNull()
        ->and(SeasonSettlement::addressFrozen($user))->toBeTrue();
});

test('a player without a Lightning address waits and is told to add one to their Nostr profile', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $alice->forceFill(['lud16' => null])->save();
    $season = settledSeason([[$alice, 1]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->approve($season, $admin);
    $payout = payoutOf($season, $alice);
    $notification = $alice->notifications()->sole();

    expect($payout->status)->toBe(PayoutStatus::Open)
        ->and($payout->reason)->toBe('no_lud16')
        ->and($notification->type)->toBe(NotificationKind::SeasonPayout->value)
        ->and($notification->data['title'])->toBe('Add a Lightning address to get your season sats')
        ->and($notification->data['body'])->toContain('Add a Lightning address to your Nostr profile');

    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])
        ->assertSee('No Lightning address in the player’s Nostr profile yet.')
        ->assertDontSeeHtml('data-test="pay-one"');

    // The player adds one; it counts as a change, so it waits 72 h, then an admin approves it.
    $alice->forceFill(['lud16' => 'alice@wallet.example', 'lud16_changed_at' => now()->subHours(73)])->save();
    app(SeasonSettlement::class)->approveAddress($payout, $admin, SeasonSettlement::fingerprint('alice@wallet.example'));
    expect($payout->refresh()->status)->toBe(PayoutStatus::Pending);
});

test('after the claim window a payout that still waits for an address is over; its sats stay in the reserve', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $bob->forceFill(['lud16' => null])->save();
    $season = settledSeason([[$alice, 1], [$bob, 2]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->approve($season, $admin);
    app(PayoutRunner::class)->run(payoutOf($season, $alice), true);
    $waiting = payoutOf($season, $bob);

    expect(SeasonSettlement::claimEndsAt($season)?->getTimestamp())->toBe(payoutOf($season, $alice)->paid_at->getTimestamp() + $season->claim_seconds)
        ->and(SeasonSettlement::claimExpired($waiting))->toBeFalse();

    $this->travel($season->claim_seconds + 1)->seconds();
    $bob->forceFill(['lud16' => 'bob@wallet.example', 'lud16_changed_at' => now()->subDays(10)])->save();

    expect(SeasonSettlement::claimExpired($waiting->refresh()))->toBeTrue()
        ->and(fn () => app(SeasonSettlement::class)->approveAddress($waiting, $admin, SeasonSettlement::fingerprint('bob@wallet.example')))
        ->toThrow(SeasonSettlementRefused::class, 'claim window');

    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])->assertSee('Claim window over');
});

test('a linked second account wins no season payout: withheld at approval, released by the unlink', function () {
    settlementWallet();
    $main = settlementPlayer('Main');
    $second = settlementPlayer('Second');
    $season = settledSeason([[$second, 1]]);
    $admin = aBoardMember();
    AccountLink::query()->create(['main_user_id' => $main->id, 'main_pubkey' => $main->pubkey, 'linked_user_id' => $second->id, 'linked_pubkey' => $second->pubkey,
        'linked_by_id' => $admin->id, 'linked_by_pubkey' => $admin->pubkey, 'reason' => 'Same person']);

    app(SeasonSettlement::class)->approve($season, $admin);
    $payout = payoutOf($season, $second);

    expect($payout->status)->toBe(PayoutStatus::Open)->and($payout->reason)->toBe('linked_account');
});

test('only the board voids and approves; any admin pays; a player does nothing', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1], [$alice, 2]]);
    $player = User::factory()->create();
    $admin = anAdmin();
    $settlement = app(SeasonSettlement::class);

    expect(fn () => $settlement->void($season, $player, 1, 'No'))->toThrow(SeasonSettlementRefused::class, 'Only a board member')
        ->and(fn () => $settlement->approve($season, $player))->toThrow(SeasonSettlementRefused::class, 'Only a board member')
        ->and(fn () => $settlement->void($season, $admin, 1, 'No'))->toThrow(SeasonSettlementRefused::class, 'Only a board member')
        ->and(fn () => $settlement->approve($season, $admin))->toThrow(SeasonSettlementRefused::class, 'Only a board member')
        ->and($season->blockVoids()->count())->toBe(0);

    Livewire::actingAs($player)->test('season-settlement', ['season' => $season])->assertForbidden();
    $this->actingAs($player)->get(route('admin.season'))->assertForbidden();

    // An admin who is not on the board sees the list, without the void form and the approval.
    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])
        ->assertSeeHtml('data-test="settlement-board-only"')
        ->assertDontSeeHtml('data-test="void-form"')
        ->assertDontSeeHtml('data-test="approve-settlement"')
        ->set('voidHeight', '1')->set('voidReason', 'No')->call('void')
        ->assertSet('error', __('Only a board member on the public admin list can void blocks and approve the settlement list.'))
        ->call('approve')
        ->assertSet('error', __('Only a board member on the public admin list can void blocks and approve the settlement list.'));

    // The board voids and approves; then the admin pays.
    $board = aBoardMember();
    Livewire::actingAs($board)->test('season-settlement', ['season' => $season])
        ->assertSeeHtml('data-test="void-form"')
        ->set('voidHeight', '2')->set('voidReason', 'Farmed')->call('void')->assertSet('error', '')
        ->call('approve')->assertSet('error', '');
    expect($season->refresh()->settlement_approved_by_id)->toBe($board->id);

    $payout = payoutOf($season, $alice);
    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])->call('pay', $payout->id);
    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid);
});

test('the Payouts card on /mining follows the state: mined so far, waiting for the review with voids, then approved and paid', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $season = settledSeason([[$alice, 1], [$alice, 2], [$bob, 3]]);
    $reward = app(SeasonChains::class)->chain($season)->blocks()[0]->rewardPerPlayer;
    $sats = fn (int $value): string => PreSeason::formatSats($value);
    $card = fn () => $this->get(route('mining'))->assertOk();

    // Live: what is mined so far.
    $season->forceFill(['ends_at' => now()->addDay()])->save();
    $card()->assertSeeHtml('data-test="payouts-mined"')->assertSee('Mined so far, paid after the review')->assertSee($sats(3 * $reward).' sats, 2 players');

    // Ended, review open: the replay with the voids so far, and it says it may change.
    $season->forceFill(['ends_at' => now()->subHour()])->save();
    $board = aBoardMember();
    app(SeasonSettlement::class)->void($season, $board, 2, 'Farmed');
    $card()->assertSeeHtml('data-test="payouts-review"')->assertSee('Waiting for the review, corrections may still lower it')
        ->assertSee($sats(2 * $reward).' sats, 2 players')->assertDontSee($sats(3 * $reward).' sats');

    // Approved: the approved total, not the raw mined total; then what is paid.
    app(SeasonSettlement::class)->approve($season->refresh(), $board);
    $card()->assertSeeHtml('data-test="payouts-approved"')->assertSee('Approved to pay')
        ->assertSee($sats(2 * $reward).' sats, 2 players')->assertDontSee($sats(3 * $reward).' sats')
        ->assertSee('Paid so far')->assertSee('0 sats, 0 players')->assertDontSee('Mined so far');

    app(PayoutRunner::class)->run(payoutOf($season, $alice), true);
    $card()->assertSee($sats($reward).' sats, 1 player');
});

test('without the league key nothing is voided or approved', function () {
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1]]);
    $admin = aBoardMember();
    config(['esports.league.nsec' => null]);

    expect(fn () => app(SeasonSettlement::class)->void($season, $admin, 1, 'Farmed'))->toThrow(SeasonSettlementRefused::class, 'league key')
        ->and(fn () => app(SeasonSettlement::class)->approve($season, $admin))->toThrow(SeasonSettlementRefused::class, 'league key')
        ->and(SeasonPayout::query()->count())->toBe(0);
});

test('a stored chain the replay does not reproduce blocks the approval', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $season = settledSeason([[$alice, 1]]);
    SeasonAttestation::query()->where('season_id', $season->id)->update(['label' => '#999']);

    expect(app(SeasonSettlement::class)->blocker($season))->toContain('differs from the stored blocks at block 1')
        ->and(fn () => app(SeasonSettlement::class)->approve($season, aBoardMember()))->toThrow(SeasonSettlementRefused::class, 'differs');
});

test('/mining lists the paid season payouts only, with their Payout event, never an unpaid amount or an address', function () {
    settlementWallet();
    $alice = settlementPlayer('Alice');
    $bob = settlementPlayer('Bob');
    $season = settledSeason([[$alice, 1], [$bob, 2], [$bob, 3]]);
    $admin = aBoardMember();
    app(SeasonSettlement::class)->void($season, $admin, 3, 'Reciprocal results within a pairing.');
    app(SeasonSettlement::class)->approve($season, $admin);
    app(PayoutRunner::class)->run(payoutOf($season, $alice), true);

    $paid = payoutOf($season, $alice);
    $unpaid = payoutOf($season, $bob);

    $this->get(route('mining'))->assertOk()
        ->assertSeeHtml('data-test="mining-season-payouts"')
        ->assertSeeHtml('data-test="mining-season-payout-proof"')
        ->assertSee('Reciprocal results within a pairing.')
        ->assertSee(PreSeason::formatSats($paid->amount_sats))
        ->assertDontSee('alice@wallet.example')
        ->assertDontSee('bob@wallet.example');

    expect(SeasonSettlement::paid($season)->pluck('id')->all())->toBe([$paid->id])
        ->and($unpaid->status)->toBe(PayoutStatus::Pending);

    Livewire::test('pages::mining')->call('$refresh')->assertOk();
    Livewire::actingAs($admin)->test('pages::admin.season')->call('$refresh')->assertOk()->assertSeeHtml('wire:name="season-settlement"');
    Livewire::actingAs($admin)->test('season-settlement', ['season' => $season])->call('$refresh')->assertOk();
    $this->actingAs($admin)->get(route('admin.season'))->assertOk()->assertSeeHtml('data-test="season-settlement"')->assertDontSee('bob@wallet.example');
});
