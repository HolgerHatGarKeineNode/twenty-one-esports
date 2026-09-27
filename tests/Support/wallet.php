<?php

/*
 * Helpers of the wallet and payout tests (P9), loaded from tests/Pest.php.
 */

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Models\Admin;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Nostr\HostResolver;
use App\Support\Prizes\PotTopUps;
use App\Support\Tournaments\TournamentPublisher;
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
 * A fake NIP-47 wallet behind both league connections (the Season-Chain's;
 * tournaments never use it), with the league's keys (league, LNURL server,
 * pool key). Tournament pots are ownPotWallet()s on the same transport.
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
 * A finished single-elimination chess tournament of `$n` players whose pot
 * is its own wallet `$pot` holding `$sats` more (read as when it was
 * connected), each player with a Lightning address unless listed in
 * `$withoutAddress` (by seed, 1 = strongest). `$fixed` sets fixed prizes.
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
 * pot in its own wallet `$pot` (a fresh empty fake wallet by default), as
 * the pot settings and TournamentPublisher would.
 */
function publishForPool(Tournament $tournament, ?FakeNwcWallet $pot = null): Tournament
{
    $pot ??= ownPotWallet(0);
    $status = $tournament->status;
    $admin = User::factory()->create();
    Admin::query()->create(['pubkey' => $admin->pubkey]);

    $tournament->forceFill(['status' => TournamentStatus::Draft, 'starts_at' => now()->addHours(2), 'pot_source' => Tournament::POT_WALLET,
        'pot_nwc_uri' => $pot->uri('pay'), 'pot_can_receive' => true, 'pot_balance_sats' => intdiv($pot->balanceMsats, 1000), 'pot_balance_at' => now()])->save();
    $published = app(TournamentPublisher::class)->publish($tournament, $admin, CarbonImmutable::now()->addHour());
    $published->forceFill(['status' => $status, 'pool_opened_at' => $published->pool_opened_at ?? now()])->save();

    $published->refresh();
    app(TournamentPublisher::class)->republish($published);

    return $published;
}

/**
 * `$sats` into the tournament's pot, its own wallet `$pot`. By default the
 * wallet's balance simply grows and is read (fast: no invoice); with
 * `$topUp` the real path, a top-up invoice the wallet makes and settles.
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
    $tournament->forceFill(['pot_balance_sats' => intdiv($pot->balanceMsats, 1000), 'pot_balance_at' => now(), 'pot_balance_error' => null])->save();
}

/**
 * The own NWC wallet of a tournament's pot: a fake wallet on the same fake
 * transport as the league's (which tournaments never use), holding `$sats`.
 * Call fakeWallet() first.
 */
function ownPotWallet(int $sats = 100_000): FakeNwcWallet
{
    $wallet = new FakeNwcWallet;
    $wallet->balanceMsats = $sats * 1000;

    return app(FakeNwcTransport::class)->add($wallet);
}
