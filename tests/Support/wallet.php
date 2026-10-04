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
use App\Support\Nostr\SignedEvent;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\Prizes\PotTopUps;
use App\Support\SeasonChain\LeagueKey;
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
 * A fake NIP-47 wallet behind both league connections, with the league's
 * keys (league, LNURL server, pool key). Every tournament pot is booked in
 * it since 2026-10-02 (ownPotWallet() returns it).
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
 * A finished single-elimination chess tournament of `$n` players whose pot,
 * booked in the league wallet `$pot`, received `$sats`, each player with a
 * Lightning address unless listed in `$withoutAddress` (by seed, 1 =
 * strongest). `$fixed` sets fixed prizes.
 *
 * @param  list<int>  $withoutAddress
 * @param  list<int>|null  $split
 * @param  list<int>|null  $fixed
 */
function finishedPoolTournament(FakeNwcWallet $pot, int $sats = 100_000, int $n = 4, array $withoutAddress = [], ?array $split = null, ?array $fixed = null): Tournament
{
    $tournament = runningChess(TournamentFormat::SingleElimination, $n);
    $tournament->forceFill(['prize_split' => $split, 'prize_mode' => $fixed === null ? null : Tournament::PRIZES_FIXED, 'prize_fixed' => $fixed])->save();
    publishForPool($tournament, $pot);

    foreach ($tournament->participants()->orderByDesc('rating')->get()->values() as $index => $participant) {
        $user = User::query()->find($participant->user_id);
        $user?->forceFill(['lud16' => in_array($index + 1, $withoutAddress, true) ? null : 'player'.($index + 1).'@wallet.example', 'name' => 'Seed '.($index + 1)])->save();
    }

    fundPool($pot, $tournament, $sats);
    playOutAsDirector($tournament);

    return $tournament->refresh();
}

/**
 * Give a stored (not published) running tournament its 31923 and an open
 * pot booked in the league wallet, as the pot settings and
 * TournamentPublisher would. `$pot` is unused (every pot is the league
 * wallet's since 2026-10-02); it stays so the callers read as before.
 */
function publishForPool(Tournament $tournament, ?FakeNwcWallet $pot = null): Tournament
{
    $status = $tournament->status;
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $tournament->forceFill(['status' => TournamentStatus::Draft, 'starts_at' => now()->addHours(2), 'pot_source' => Tournament::POT_LEAGUE])->save();
    $published = app(TournamentPublisher::class)->publish($tournament, $admin, CarbonImmutable::now()->addHour());
    $published->forceFill(['status' => $status, 'pool_opened_at' => $published->pool_opened_at ?? now()])->save();

    $published->refresh();
    app(TournamentPublisher::class)->republish($published);

    return $published;
}

/**
 * `$sats` into the tournament's pot in the league wallet `$pot`. By default
 * a settled top-up row is booked and the wallet's balance grows (fast: no
 * invoice); with `$topUp` the real path, a top-up invoice the league wallet
 * makes and settles.
 */
function fundPool(FakeNwcWallet $pot, Tournament $tournament, int $sats, bool $topUp = false): void
{
    if ($topUp) {
        $payment = app(PotTopUps::class)->invoice($tournament->refresh(), $sats);
        $pot->settleIncoming($payment->payment_hash);
        app(PotTopUps::class)->check($payment, 0);

        return;
    }

    $pot->balanceMsats += $sats * 1000;

    if ($sats <= 0) {
        return;
    }

    $payment = IncomingPayment::query()->create([
        'pot' => $tournament->potAccount(), 'tournament_id' => $tournament->id, 'source' => 'topup',
        'payment_hash' => bin2hex(random_bytes(32)), 'bolt11' => 'lnbcrt-fake', 'amount_sats' => $sats,
        'status' => IncomingPaymentStatus::Settled, 'expires_at' => now()->addMinutes(15), 'settled_at' => now(),
    ]);
    app(Ledger::class)->contribution($payment, $tournament->potAccount());
}

/**
 * The wallet of a tournament's pot: the league wallet (every pot is booked
 * in it since 2026-10-02), its balance raised by `$sats`. Nothing is booked
 * for any pot: fundPool() does that. Call fakeWallet() first.
 */
function ownPotWallet(int $sats = 100_000): FakeNwcWallet
{
    $wallet = app(FakeNwcTransport::class)->wallet;
    $wallet->balanceMsats += $sats * 1000;

    return $wallet;
}

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
