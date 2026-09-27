<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\HostResolver;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PrizePool;
use App\Support\Tournaments\TournamentRuleViolation;
use App\Support\Wallet\PinnedStreamFactory;
use App\Support\Wallet\RelayGuard;
use App\Support\Wallet\WebsocketNwcTransport;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Phrity\Net\Uri;
use Tests\Support\FakeHostResolver;
use Tests\Support\FakeNwcTransport;
use Tests\Support\TestSigner;

/*
| P9 security gate (2026-09-27), one regression per finding:
| F1 a pot's NWC relay URL made the server open websockets anywhere (SSRF);
| F2 the signed-zap path made invoices without the limiter;
| F3 a payout went to the profile's current Lightning address, not the approved one.
*/

afterEach(function () {
    app()['env'] = 'testing';
});

/** A connection string of the fake pot wallet, pointed at another relay. */
function uriWithRelay(string $relay): string
{
    $own = ownPotWallet();

    return (string) preg_replace('/relay=[^&]+/', 'relay='.rawurlencode($relay), $own->uri('pay'));
}

test('F1: a relay on a loopback, private or non-TLS address is refused before any connection', function (string $relay, array $answers, bool $production) {
    fakeWallet();
    $resolver = new FakeHostResolver($answers);
    app()->instance(HostResolver::class, $resolver);
    $uri = uriWithRelay($relay);
    $transport = app(FakeNwcTransport::class);
    $calls = $transport->calls;

    if ($production) {
        app()['env'] = 'production';
    }

    expect(fn () => app(PrizePool::class)->checkWallet($uri))->toThrow(TournamentRuleViolation::class, 'wss://')
        ->and($transport->calls)->toBe($calls)
        ->and(app(RelayGuard::class)->target($relay))->toBeNull();
})->with([
    'loopback IP' => ['wss://127.0.0.1/', [], false],
    'localhost' => ['wss://localhost/', [], false],
    '10.x address' => ['wss://10.1.2.3/', [], false],
    'name resolving to a private IP' => ['wss://relay.internal.example.com/', ['relay.internal.example.com' => ['10.0.0.5']], false],
    'one of its addresses private' => ['wss://mixed.example.com/', ['mixed.example.com' => ['93.184.215.14', '192.168.1.2']], false],
    'another port' => ['wss://relay.example.com:8080/', [], false],
    'ws:// in production' => ['ws://relay.example.com/', [], true],
    'test relay in production' => ['ws://127.0.0.1:7777', [], true],
]);

test('F1: a wss:// relay on a public name passes and is pinned to the address that was checked', function () {
    fakeWallet();
    app()->instance(HostResolver::class, new FakeHostResolver(['relay.example.com' => ['93.184.215.14']]));

    expect(app(PrizePool::class)->checkWallet(uriWithRelay('wss://relay.example.com/v1'))['balance'])->toBe(100_000)
        ->and(app(RelayGuard::class)->target('wss://relay.example.com/v1'))->toBe(['host' => 'relay.example.com', 'ip' => '93.184.215.14']);

    // The socket goes to the checked address; TLS still verifies the name.
    $socket = (new PinnedStreamFactory('relay.example.com', '93.184.215.14'))->createSocketClient(new Uri('ssl://relay.example.com:443'));
    $read = fn (string $property) => (fn () => $this->{$property})->call($socket);

    expect($read('uri')->getHost())->toBe('93.184.215.14')
        ->and($read('context')->getOption('ssl', 'peer_name'))->toBe('relay.example.com')
        ->and($read('context')->getOption('ssl', 'verify_peer'))->toBeTrue();
});

test('F1: the websocket transport never connects to a refused relay (and does to an allowed test relay)', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr((string) stream_socket_get_name($server, false), strrpos((string) stream_socket_get_name($server, false), ':') + 1);
    $transport = app(WebsocketNwcTransport::class);
    $filter = ['kinds' => [13194], 'limit' => 1];

    expect($transport->fetch("ws://127.0.0.1:{$port}", $filter, 1.0))->toBeNull()
        ->and(@stream_socket_accept($server, 0.3))->toBeFalse();

    // Positive control: the same relay allowed as a test relay is reached.
    config(['esports.wallet.nwc_insecure_relays' => ["127.0.0.1:{$port}"]]);
    $transport->fetch("ws://127.0.0.1:{$port}", $filter, 1.0);

    expect(@stream_socket_accept($server, 1.0))->not->toBeFalse();
    fclose($server);
});

test('F1: every live wallet check counts against the limit before it runs, on the check, create and edit paths', function () {
    fakeWallet();
    $own = ownPotWallet();
    // The receive connection may not pay: each check reaches the wallet and fails, so nothing saves.
    $refused = $own->uri('receive');
    $transport = app(FakeNwcTransport::class);
    $tournament = openTournament();

    $pages = [
        'check' => fn () => Livewire::actingAs(organizer())->test('pages::admin.tournament-create')->set('potEnabled', true)->set('potSource', 'wallet')->set('potUri', $refused),
        'create' => fn () => Livewire::actingAs(organizer())->test('pages::admin.tournament-create')->set('name', 'Limit Cup')
            ->set('potEnabled', true)->set('potSource', 'wallet')->set('potUri', $refused),
        'edit' => fn () => Livewire::actingAs($tournament->creator)->test('pages::admin.tournament-edit', ['tournament' => $tournament])
            ->set('potEnabled', true)->set('potSource', 'wallet')->set('potUri', $refused),
    ];
    $actions = ['check' => 'checkPotConnection', 'create' => 'create', 'edit' => 'savePotSettings'];

    foreach ($pages as $path => $page) {
        $page = $page();

        for ($i = 1; $i <= 6; $i++) {
            $page->call($actions[$path])->assertNotSet('potError', __('Too many checks. Wait :seconds s and try again.', ['seconds' => 60]));
        }

        $calls = $transport->calls;
        $page->call($actions[$path]);

        expect($page->get('potError'))->toStartWith('Too many checks')
            ->and($transport->calls)->toBe($calls);
    }

    expect(Tournament::query()->where('name', 'Limit Cup')->exists())->toBeFalse()
        ->and($tournament->refresh()->pot_source)->toBeNull();
});

test('F2: thirty signed zaps in a minute make at most the per-user limit of invoices', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $signer = new TestSigner;
    $player = User::factory()->withPubkey($signer->pubkey)->create();
    $panel = Livewire::actingAs($player)->test('tournament-pool', ['tournament' => $tournament])->set('amount', 2100);

    for ($i = 0; $i < 30; $i++) {
        $templates = $panel->instance()->prepareZap(app(PoolInvoices::class));
        $panel->call('submitZap', json_encode($signer->signTemplates($templates)))->call('closeInvoice');
    }

    expect(IncomingPayment::query()->count())->toBe((int) config('esports.wallet.open_invoices_per_user'))
        ->and(IncomingPayment::query()->where('requester_user_id', $player->id)->count())->toBe(5);

    // The per-minute limit holds on its own too, with the open-invoice cap out of the way.
    config(['esports.wallet.open_invoices_per_user' => 100, 'esports.wallet.open_invoices_per_ip' => 100]);
    IncomingPayment::query()->delete();
    $this->travel(2)->minutes();

    for ($i = 0; $i < 30; $i++) {
        $templates = $panel->instance()->prepareZap(app(PoolInvoices::class));
        $panel->call('submitZap', json_encode($signer->signTemplates($templates)))->call('closeInvoice');
    }

    expect(IncomingPayment::query()->count())->toBe((int) config('esports.wallet.invoices_per_minute'));
});

test('F3: a changed Lightning address is not paid until an admin approves it', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    $admin = anAdmin();
    $page = Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('approve');
    $payout = $tournament->payouts()->where('place', 1)->sole();
    $approved = $payout->lud16;

    // The player changes their kind 0 after the check.
    $payout->user->forceFill(['lud16' => 'thief@wallet.example'])->save();
    $sent = count(Http::recorded());
    $page->call('pay', $payout->id);

    expect(count(Http::recorded()))->toBe($sent)
        ->and($wallet->payRequests())->toBe([])
        ->and($payout->refresh()->status)->toBe(PayoutStatus::Open)
        ->and($payout->reason)->toBe('lud16_changed')
        ->and($payout->lud16)->toBe($approved)
        ->and($payout->reasonText())->toContain('changed since approval');
    $page->assertSee('thief@wallet.example')->assertSeeHtml('data-test="approve-address"');

    // A Pay click on the open payout does nothing either.
    $page->call('pay', $payout->id);
    expect($wallet->payRequests())->toBe([]);

    // Approved again: the new address is paid, once.
    $page->call('approveAddress', $payout->id, 'thief@wallet.example')->call('pay', $payout->id);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid)
        ->and($payout->lud16)->toBe('thief@wallet.example')
        ->and($wallet->payRequests())->toHaveCount(1)
        ->and(collect(Http::recorded())->map(fn ($pair) => $pair[0]->url())->filter(fn ($url) => str_contains($url, '/cb/thief')))->toHaveCount(1);
});

test('hardening: the pot settings check the tournament again under the row lock', function () {
    fakeWallet();
    $own = ownPotWallet();
    $tournament = openTournament();
    // The tournament is called off while the wallet is being checked.
    $own->onRequest = fn () => Tournament::query()->whereKey($tournament->id)->update(['status' => TournamentStatus::Cancelled]);

    expect(fn () => app(PrizePool::class)->configurePot($tournament, $tournament->creator, Tournament::POT_WALLET, $own->uri('pay'), null, [50, 30, 20]))
        ->toThrow(TournamentRuleViolation::class, __('This tournament has ended; its pool can no longer change.'))
        ->and($tournament->refresh()->pot_source)->toBeNull()
        ->and($tournament->pot_nwc_uri)->toBeNull();
});

test('re-gate R2: an admin approves only the address they were shown, not one swapped in since', function () {
    $wallet = fakeWallet();
    fakeLightningAddresses($wallet);
    $tournament = finishedPoolTournament($wallet, 10_000, 2);
    $page = Livewire::actingAs(anAdmin())->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->call('approve');
    $payout = $tournament->payouts()->where('place', 1)->sole();
    $approved = $payout->lud16;

    $payout->user->forceFill(['lud16' => 'shown@wallet.example'])->save();
    $page->call('pay', $payout->id);
    $page->call('$refresh')->assertSee('shown@wallet.example');

    // The page shows "shown"; the profile switches before the admin confirms.
    $payout->user->forceFill(['lud16' => 'swapped@wallet.example'])->save();
    $page->call('approveAddress', $payout->id, 'shown@wallet.example')->assertHasErrors('payouts');

    expect($payout->refresh()->status)->toBe(PayoutStatus::Open)->and($payout->lud16)->toBe($approved);

    // Re-rendered, the admin sees the swapped address and approves that one knowingly.
    $page->call('$refresh')->assertSee('swapped@wallet.example')
        ->call('approveAddress', $payout->id, 'swapped@wallet.example')->assertHasNoErrors();

    expect($payout->refresh()->status)->toBe(PayoutStatus::Pending)->and($payout->lud16)->toBe('swapped@wallet.example');
});

test('re-gate R1: a forged X-Forwarded-For on an on-forge.com host does not pick the IP a limit counts', function () {
    $limit = (int) config('esports.wallet.invoices_per_minute');
    $statuses = [];

    for ($i = 1; $i <= $limit + 1; $i++) {
        $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['X-Forwarded-For' => '198.51.100.'.$i])
            ->get('http://esports-demo.on-forge.com/.well-known/lnurlp/pool')->status();
    }

    expect(array_count_values($statuses)[429] ?? 0)->toBe(1)
        ->and(end($statuses))->toBe(429);
});

test('re-gate: a pot with a pending league zap is not moved to an own wallet or removed', function () {
    fakeWallet();
    $own = ownPotWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $admin = anAdmin();
    $payment = app(PoolInvoices::class)->anonymousZap($tournament->refresh(), 7_000, 'late zap');
    $move = fn (?string $source) => app(PrizePool::class)->configurePot($tournament->refresh(), $admin, $source, $source === null ? null : $own->uri('pay'), null, $tournament->prizeSplit());

    expect($payment->status)->toBe(IncomingPaymentStatus::Pending)
        ->and(fn () => $move(Tournament::POT_WALLET))->toThrow(TournamentRuleViolation::class, 'still waiting to be paid')
        ->and(fn () => $move(null))->toThrow(TournamentRuleViolation::class, 'still waiting to be paid')
        ->and($tournament->refresh()->pot_source)->toBeNull()
        ->and($tournament->pot_nwc_uri)->toBeNull();

    // Positive control: once that invoice expired unpaid, the pot may move.
    $payment->forceFill(['status' => IncomingPaymentStatus::Expired])->save();
    $move(Tournament::POT_WALLET);
    expect($tournament->refresh()->pot_source)->toBe(Tournament::POT_WALLET);
});
