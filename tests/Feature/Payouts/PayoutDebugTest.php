<?php

use App\Enums\PayoutStatus;
use App\Support\Nostr\HostResolver;
use App\Support\Payouts\PayoutApproval;
use App\Support\Payouts\PayoutRunner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Bolt11Fixture;
use Tests\Support\FakeNwcWallet;

/*
| A failed payout keeps what its attempt saw (user 2026-10-25: "invoice_mismatch" alone told nobody why a Primal
| address failed): the LNURL answer, the invoice, the failed check; the admin page shows it.
*/

test('an invoice whose description hash is not sha256(metadata) fails with the failed check and both hashes in the trace', function () {
    $league = fakeWallet();
    primalLikeAddresses();

    $tournament = finishedPoolTournament($league, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, $admin = anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();
    app(PayoutRunner::class)->run($payout, true);
    $payout->refresh();

    expect($payout->status)->toBe(PayoutStatus::Failed)
        ->and($payout->reason)->toBe('invoice_mismatch')
        ->and($payout->debug['failed_checks'])->toBe(['description_hash != sha256(metadata) (LUD-06)'])
        ->and($payout->debug['metadata_sha256'])->toBe(hash('sha256', (string) json_encode([['text/plain', 'Pay me']])))
        ->and($payout->debug['invoice_description_hash'])->toBe(hash('sha256', 'something primal hashed'))
        ->and($payout->debug['callback_url'])->toStartWith('https://wallet.example/cb?amount=');

    Livewire::actingAs($admin)->test('pages::admin.payouts', ['tournamentId' => $tournament->id])->assertSeeHtml('data-test="payout-debug"')->assertSee('description_hash != sha256(metadata)');

});

/** LNURL answers like Primal's (2026-10-26): the invoice's description hash is not sha256(metadata); config `test.extra_msats` skews the amount. */
function primalLikeAddresses(?FakeNwcWallet $wallet = null): void
{
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function addresses(string $host): array
        {
            return ['93.184.215.14'];
        }
    });
    $metadata = (string) json_encode([['text/plain', 'Pay me']]);
    Http::fake(function (Request $request) use ($metadata, $wallet) {
        $url = parse_url($request->url());

        if (str_starts_with($url['path'] ?? '', '/.well-known/lnurlp/')) {
            return Http::response(['tag' => 'payRequest', 'callback' => 'https://wallet.example/cb', 'minSendable' => 1000, 'maxSendable' => 100_000_000_000, 'metadata' => $metadata]);
        }

        parse_str($url['query'] ?? '', $query);

        $fixture = Bolt11Fixture::make((int) $query['amount'] + (int) config('test.extra_msats', 0), hash('sha256', 'something primal hashed'));
        if ($wallet !== null) {
            $wallet->known[$fixture['payment_hash']] = $fixture['preimage'];
        }

        return Http::response(['pr' => $fixture['invoice'], 'routes' => []]);
    });

}

test('a lenient host (Primal, user decision 2026-10-26) is paid despite a foreign description hash, but a wrong amount still fails', function () {
    $league = fakeWallet();
    config(['esports.wallet.description_hash_lenient_hosts' => ['wallet.example']]);
    primalLikeAddresses($league);
    $tournament = finishedPoolTournament($league, 10_000, 2);
    app(PayoutApproval::class)->approve($tournament, anAdmin());
    $payout = $tournament->payouts()->where('place', 1)->sole();
    app(PayoutRunner::class)->run($payout, true);

    expect($payout->refresh()->status)->toBe(PayoutStatus::Paid);

    config(['test.extra_msats' => 1000]);
    $second = $tournament->payouts()->where('place', 2)->sole();
    app(PayoutRunner::class)->run($second, true);

    expect($second->refresh()->status)->toBe(PayoutStatus::Failed)
        ->and($second->debug['failed_checks'][0])->toStartWith('amount ');
});
