<?php

/*
 * Helpers of the wallet and payout tests (P9), loaded from tests/Pest.php.
 */

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\IncomingPayment;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\HostResolver;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Tournaments\TournamentPublisher;
use App\Support\Wallet\Ledger;
use App\Support\Wallet\NwcTransport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Bolt11Fixture;
use Tests\Support\FakeHostResolver;
use Tests\Support\FakeNwcTransport;
use Tests\Support\FakeNwcWallet;
use Tests\Support\TestSigner;

/** A board-independent admin (the `admins` table). */
function anAdmin(): User
{
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    return $admin;
}

/**
 * A fake NIP-47 wallet behind both league connections, with every key the
 * pools need (league, LNURL server, sponsor desk, pool key).
 */
function fakeWallet(): FakeNwcWallet
{
    $wallet = new FakeNwcWallet;
    $transport = new FakeNwcTransport($wallet);
    app()->instance(NwcTransport::class, $transport);
    // The fake wallets' relay names resolve to a public address (RelayGuard), without the network.
    app()->instance(HostResolver::class, new FakeHostResolver);
    app()->instance(FakeNwcTransport::class, $transport);

    config([
        'esports.wallet.nwc_uri' => $wallet->uri('pay'),
        'esports.wallet.nwc_receive_uri' => $wallet->uri('receive'),
        'esports.wallet.lnurl_nsec' => (new TestSigner)->secret,
        'esports.wallet.sponsor_nsec' => (new TestSigner)->secret,
        'esports.wallet.pool_npub' => (new TestSigner)->pubkey,
        'esports.wallet.invoice_networks' => ['bcrt'],
    ]);

    if (! is_string(config('esports.league.nsec')) || config('esports.league.nsec') === '') {
        config(['esports.league.nsec' => (new TestSigner)->secret]);
    }

    return $wallet;
}

/**
 * Lightning addresses `<name>@wallet.example` answered by a fake LNURL-pay
 * server (Http::fake) whose invoices the fake wallet can pay. The domain
 * resolves to a public address. `$broken` names addresses whose server fails.
 *
 * @param  list<string>  $broken
 */
function fakeLightningAddresses(FakeNwcWallet $wallet, array $broken = []): void
{
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function addresses(string $host): array
        {
            return ['93.184.215.14'];
        }
    });

    $metadata = fn (string $name): string => (string) json_encode([['text/plain', 'Pay '.$name], ['text/identifier', $name.'@wallet.example']]);

    Http::fake(function (Request $request) use ($wallet, $metadata, $broken) {
        $url = parse_url($request->url());

        if (($url['host'] ?? '') !== 'wallet.example') {
            return Http::response([], 404);
        }

        if (preg_match('#^/\.well-known/lnurlp/([a-z0-9._-]+)$#', $url['path'] ?? '', $match) === 1) {
            if (in_array($match[1], $broken, true)) {
                return Http::response('down', 503);
            }

            return Http::response(['tag' => 'payRequest', 'callback' => 'https://wallet.example/cb/'.$match[1], 'minSendable' => 1000, 'maxSendable' => 100_000_000_000, 'metadata' => $metadata($match[1])]);
        }

        if (preg_match('#^/cb/([a-z0-9._-]+)$#', $url['path'] ?? '', $match) === 1) {
            parse_str($url['query'] ?? '', $query);
            $fixture = Bolt11Fixture::make((int) $query['amount'], hash('sha256', $metadata($match[1])));
            $wallet->known[$fixture['payment_hash']] = $fixture['preimage'];

            return Http::response(['pr' => $fixture['invoice'], 'routes' => []]);
        }

        return Http::response([], 404);
    });
}

/**
 * A finished single-elimination chess tournament of `$n` players with an
 * open pool holding `$sats` from one zap, each player with a Lightning
 * address unless listed in `$withoutAddress` (by seed, 1 = strongest).
 *
 * @param  list<int>  $withoutAddress
 */
function finishedPoolTournament(FakeNwcWallet $wallet, int $sats = 100_000, int $n = 4, array $withoutAddress = [], ?array $split = null): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    $tournament->forceFill(['prize_split' => $split])->save();
    publishForPool($tournament);

    foreach ($tournament->participants()->orderByDesc('rating')->get()->values() as $index => $participant) {
        $user = User::query()->find($participant->user_id);
        $user?->forceFill(['lud16' => in_array($index + 1, $withoutAddress, true) ? null : 'player'.($index + 1).'@wallet.example', 'name' => 'Seed '.($index + 1)])->save();
    }

    fundPool($wallet, $tournament, $sats);
    playOutAsDirector($tournament);

    return $tournament->refresh();
}

/**
 * Give a stored (not published) running tournament its 31923 and an open
 * pool, as TournamentPublisher and PrizePool::open() would.
 */
function publishForPool(Tournament $tournament): Tournament
{
    $status = $tournament->status;
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $tournament->forceFill(['status' => TournamentStatus::Draft, 'starts_at' => now()->addHours(2)])->save();
    $published = app(TournamentPublisher::class)->publish($tournament, $admin, CarbonImmutable::now()->addHour());
    $published->forceFill(['status' => $status, 'pool_opened_at' => now()])->save();

    $published->refresh();
    app(TournamentPublisher::class)->republish($published);

    return $published;
}

/**
 * `$sats` into the tournament's pool. By default a paid invoice written
 * straight to the book (fast: no NIP-47 round trips, no signatures) and
 * credited to the fake wallet; with `$zap` the real path, an anonymous zap
 * the fake wallet invoices and settles.
 */
function fundPool(FakeNwcWallet $wallet, Tournament $tournament, int $sats, bool $zap = false): void
{
    if ($zap) {
        $payment = app(PoolInvoices::class)->anonymousZap($tournament->refresh(), $sats, 'test');
        $wallet->settleIncoming($payment->payment_hash);
        app(IncomingPayments::class)->check($payment, 0);

        return;
    }

    $fixture = Bolt11Fixture::make($sats * 1000, str_repeat('0', 64));
    $payment = IncomingPayment::query()->create([
        'pot' => IncomingPayment::tournamentPot($tournament->id), 'tournament_id' => $tournament->id, 'source' => 'anonymous',
        'payment_hash' => $fixture['payment_hash'], 'bolt11' => $fixture['invoice'], 'amount_sats' => $sats,
        'status' => IncomingPaymentStatus::Settled, 'expires_at' => now()->addHour(), 'settled_at' => now(), 'preimage' => $fixture['preimage'],
    ]);
    app(Ledger::class)->contribution($payment, $payment->pot);
    $wallet->balanceMsats += $sats * 1000;
}

/**
 * The own NWC wallet of a tournament's pot (P9 scope addition): a second
 * fake wallet on the same fake transport as the league's, holding `$sats`.
 * Call fakeWallet() first.
 */
function ownPotWallet(int $sats = 100_000): FakeNwcWallet
{
    $wallet = new FakeNwcWallet;
    $wallet->balanceMsats = $sats * 1000;

    return app(FakeNwcTransport::class)->add($wallet);
}

/**
 * A finished single-elimination chess tournament of `$n` players whose pot
 * is held in `$own` (read once, as when it was connected), each player with
 * a Lightning address unless listed in `$withoutAddress` (by seed).
 *
 * @param  list<int>  $withoutAddress
 */
function finishedOwnWalletTournament(FakeNwcWallet $own, int $n = 4, array $withoutAddress = []): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    publishForPool($tournament);
    $tournament->forceFill([
        'pot_source' => Tournament::POT_WALLET, 'pot_nwc_uri' => $own->uri('pay', 'pot@wallet.example'), 'pot_lud16' => 'pot@wallet.example',
        'pot_balance_sats' => intdiv($own->balanceMsats, 1000), 'pot_balance_at' => now(),
    ])->save();

    foreach ($tournament->participants()->orderByDesc('rating')->get()->values() as $index => $participant) {
        $user = User::query()->find($participant->user_id);
        $user?->forceFill(['lud16' => in_array($index + 1, $withoutAddress, true) ? null : 'player'.($index + 1).'@wallet.example', 'name' => 'Seed '.($index + 1)])->save();
    }

    playOutAsDirector($tournament);

    return $tournament->refresh();
}
