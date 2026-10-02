<?php

use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Models\NostrEvent;
use App\Support\Nostr\NostrKeys;
use App\Support\Prizes\IncomingPayments;
use App\Support\Prizes\PoolInvoices;
use App\Support\SeasonChain\LeagueKey;
use App\Support\StreamBot\StreamCoordinates;
use App\Support\Wallet\Ledger;
use Tests\Support\FakeNwcWallet;
use Tests\Support\TestSigner;

/*
| Zaps to the league's profile and to its 24/7 stream (user, 2026-10-03:
| „Dass alle Zaps an das Profil in den Pool gehen ist total in Ordnung"): the
| profile's lud16 is pool@<host>, so a plain profile zap (no `a`) and a zap of
| the stream's 30311 (zap.stream: `a` = the stream, `p` = its host; Amethyst
| adds the version as `e` and `k`) settle into the league reserve, never into
| a tournament pot. Any other `a` is refused; a tournament's `a` keeps its pot.
*/

beforeEach(function () {
    $this->wallet = fakeWallet();
    // The stream's host is the profile key, which is the pool key (ESPORTS_POOL_NPUB).
    config(['twentyone.nostr.npub' => NostrKeys::hexToNpub((string) LeagueKey::poolPubkey()), 'twentyone.stream.event.d' => 'twentyone-247']);
    $this->stream = (string) StreamCoordinates::fromConfig()?->address();
});

/**
 * Ask the league's LNURL callback for an invoice; the zap request as a client sends it.
 *
 * @param  list<list<string>>  $tags  besides `p` (the pool key), `relays` and `amount`
 * @return array{0: string, 1: array<mixed>} the request as sent, and the answer
 */
function askPool(mixed $test, array $tags, int $msats = 21_000_000, string $query = ''): array
{
    $request = json_encode((new TestSigner)->sign(9734, [['p', (string) LeagueKey::poolPubkey()], ['relays', 'wss://relay.example.org'], ['amount', (string) $msats], ...$tags], 'gg', now()->getTimestamp()));

    return [(string) $request, $test->getJson('/lnurlp/pool/callback?'.http_build_query(['amount' => $msats, 'nostr' => $request]).$query)->assertOk()->json()];
}

/** Pay the invoice and let the league settle it. */
function payPool(FakeNwcWallet $wallet, IncomingPayment $payment): IncomingPayment
{
    $wallet->settleIncoming($payment->payment_hash);

    return app(IncomingPayments::class)->check($payment, 0);
}

test('a profile zap without an `a` settles into the league reserve and is receipted once by the LNURL key', function () {
    [$sent, $answer] = askPool($this, [['lnurl', PoolInvoices::lnurl()]]);
    expect($answer)->toHaveKey('pr');

    $payment = payPool($this->wallet, IncomingPayment::query()->sole());
    app(IncomingPayments::class)->check($payment, 0);
    $receipt = NostrEvent::query()->where('kind', 9735)->sole();
    $tags = json_decode($receipt->raw, true)['tags'];

    expect($payment->pot)->toBe(IncomingPayment::RESERVE)
        ->and($payment->tournament_id)->toBeNull()
        ->and(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(21_000)
        ->and($receipt->pubkey)->toBe(LeagueKey::lnurl()?->pubkey())
        ->and(collect($tags)->firstWhere(0, 'p')[1])->toBe(LeagueKey::poolPubkey())
        ->and(collect($tags)->firstWhere(0, 'a'))->toBeNull()
        ->and(collect($tags)->firstWhere(0, 'description')[1])->toBe($sent);
});

test('a zap of the league stream settles into the reserve: as zap.stream sends it, and as Amethyst sends it', function () {
    expect($this->stream)->toStartWith('30311:'.LeagueKey::poolPubkey().':');

    // zap.stream (src/element/stream/stream-info.tsx): `a` = the 30311, `p` = its host, no `e` without a goal.
    askPool($this, [['a', $this->stream]]);
    // Amethyst (quartz ZapRequestEvent.create): the version as `e`, the kind as `k`, and the address.
    $version = str_repeat('ab', 32);
    askPool($this, [['e', $version], ['k', '30311'], ['a', $this->stream]], 2_100_000);

    $payments = IncomingPayment::query()->orderBy('id')->get();
    expect($payments)->toHaveCount(2)
        ->and($payments->pluck('pot')->unique()->all())->toBe([IncomingPayment::RESERVE])
        ->and($payments->pluck('tournament_id')->filter()->all())->toBe([]);

    $payments->each(fn (IncomingPayment $payment) => payPool($this->wallet, $payment));
    $receipts = NostrEvent::query()->where('kind', 9735)->orderBy('id')->get()->map(fn (NostrEvent $event): array => json_decode($event->raw, true)['tags']);

    expect(app(Ledger::class)->balance(Ledger::RESERVE))->toBe(23_100)
        ->and($receipts)->toHaveCount(2)
        ->and($receipts->map(fn (array $tags) => collect($tags)->firstWhere(0, 'a')[1] ?? null)->all())->toBe([$this->stream, $this->stream])
        // The league never vouches for an event id it could not check: the stream's versions are not stored.
        ->and($receipts->map(fn (array $tags) => collect($tags)->firstWhere(0, 'e'))->filter()->all())->toBe([]);
});

test('an `a` the league does not run is refused: another stream, another key, another kind, or the stream through a tournament’s LNURL', function () {
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $pool = (string) LeagueKey::poolPubkey();
    $refused = [
        '30311:'.$pool.':another-stream',
        '30311:'.str_repeat('cd', 32).':twentyone-247',
        '30023:'.$pool.':twentyone-247',
        '30311:'.$pool.':twentyone-247:x',
    ];

    foreach ($refused as $address) {
        expect(askPool($this, [['a', $address]])[1])->toMatchArray(['status' => 'ERROR']);
    }

    // The stream beside a tournament, the stream with another kind, the stream through the tournament's own LNURL.
    expect(askPool($this, [['a', $this->stream], ['a', (string) $tournament->address()]])[1])->toMatchArray(['status' => 'ERROR'])
        ->and(askPool($this, [['a', $this->stream], ['k', '1']])[1])->toMatchArray(['status' => 'ERROR'])
        ->and(askPool($this, [['a', $this->stream]], query: '&pot='.$tournament->id)[1])->toMatchArray(['status' => 'ERROR'])
        ->and(IncomingPayment::query()->count())->toBe(0);

    // Without a stream announced there is no stream to zap.
    config(['twentyone.nostr.npub' => null, 'twentyone.nostr.nsec' => null]);
    expect(askPool($this, [['a', $this->stream]])[1])->toMatchArray(['status' => 'ERROR'])
        ->and(IncomingPayment::query()->count())->toBe(0);
});

test('a tournament’s `a` still pays into that tournament’s pot', function () {
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));

    askPool($this, [['a', (string) $tournament->address()], ['k', '31923']]);

    expect(IncomingPayment::query()->sole())->pot->toBe($tournament->potAccount())->tournament_id->toBe($tournament->id);
});
