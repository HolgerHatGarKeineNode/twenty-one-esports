<?php

use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\NostrEvent;
use App\Models\SeriesMatch;
use App\Models\User;
use App\Support\Lightning\Lnurl;
use App\Support\Lightning\WinnerZaps;
use App\Support\Lightning\ZapRefused;
use App\Support\Nostr\HostResolver;
use App\Support\Nostr\SignedEvent;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\Bolt11Fixture;
use Tests\Support\TestSigner;

/*
 * Zap the winner (P47, NIP-57): offered under a finished game, series or
 * tournament for each winner whose profile has a Lightning address, as the
 * LNURL QR code for anyone and as a signed zap request for a signed-in
 * player; the address never as text, never to yourself, no fee.
 */

beforeEach(function () {
    config([
        'app.url' => 'https://esports.example',
        'esports.relays' => ['wss://league.example'],
        'esports.profile_relays' => ['wss://profiles.example'],
        'esports.chat.relays' => [],
        'esports.wallet.invoice_networks' => ['bcrt'],
    ]);

    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function addresses(string $host): array
        {
            return ['93.184.215.14'];
        }
    });
});

/**
 * A LNURL server at wallet.example that takes zaps (or not), answering the
 * callback with an invoice that commits to the `nostr` it got (or, with
 * `$wrongHash`, to something else).
 */
function fakeZapWallet(bool $allowsNostr = true, bool $wrongHash = false, bool $nostrPubkey = true): void
{
    // A fresh client each time: a second Http::fake() on the same factory never answers (the first stub wins).
    Http::swap(new Factory);
    Http::fake(function (Request $request) use ($allowsNostr, $wrongHash, $nostrPubkey) {
        $url = parse_url($request->url());

        if (($url['host'] ?? '') !== 'wallet.example') {
            return Http::response([], 404);
        }

        if (preg_match('#^/\.well-known/lnurlp/([a-z0-9._-]+)$#', $url['path'] ?? '', $match) === 1) {
            return Http::response(array_filter([
                'tag' => 'payRequest', 'callback' => 'https://wallet.example/cb/'.$match[1], 'minSendable' => 1000, 'maxSendable' => 100_000_000_000,
                'metadata' => json_encode([['text/plain', 'Pay '.$match[1]]]),
                'allowsNostr' => $allowsNostr ?: null, 'nostrPubkey' => $nostrPubkey ? str_repeat('ab', 32) : null,
            ]));
        }

        parse_str($url['query'] ?? '', $query);

        return Http::response(['pr' => Bolt11Fixture::make((int) $query['amount'], hash('sha256', $wrongHash ? 'something else' : (string) ($query['nostr'] ?? '')), network: 'bcrt')['invoice'], 'routes' => []]);
    });
}

test('a finished game offers the zap only for a winner with a Lightning address, never as text and never to yourself', function () {
    $anna = User::factory()->create(['name' => 'anna', 'lud16' => 'anna@wallet.example']);
    $bert = User::factory()->create(['name' => 'bert', 'lud16' => 'bert@wallet.example']);
    $won = ChessGame::factory()->finished('0-1')->create(['white_id' => $bert->id, 'black_id' => $anna->id, 'ply' => 12]);

    $html = Livewire::test('zap-winner', ['type' => 'game', 'subject' => (string) $won->id])
        ->assertSee('data-test="zap-winner"', false)
        ->assertSee('data-test="zap-lnurl-qr"', false)
        ->assertSee('anna')
        ->assertDontSee('bert')
        ->html();

    expect($html)->not->toContain('anna@wallet.example')
        ->and($html)->not->toContain('wallet.example')
        ->and(Lnurl::fromAddress('anna@wallet.example'))->not->toBeNull();

    // The winner herself, a draw, a game without moves, a running game, a winner without an address: nothing.
    Livewire::actingAs($anna)->test('zap-winner', ['type' => 'game', 'subject' => (string) $won->id])->assertDontSee('data-test="zap-winner"', false);

    $draw = ChessGame::factory()->finished('1/2-1/2')->create(['white_id' => $bert->id, 'black_id' => $anna->id, 'ply' => 12]);
    $noMoves = ChessGame::factory()->finished('1-0')->create(['white_id' => $anna->id, 'black_id' => $bert->id, 'ply' => 0]);
    $running = ChessGame::factory()->create(['white_id' => $anna->id, 'black_id' => $bert->id]);
    $noAddress = ChessGame::factory()->finished('1-0')->create(['white_id' => User::factory()->create(['lud16' => null])->id, 'black_id' => $bert->id, 'ply' => 12]);
    $badAddress = ChessGame::factory()->finished('1-0')->create(['white_id' => User::factory()->create(['lud16' => 'not-an-address'])->id, 'black_id' => $bert->id, 'ply' => 12]);

    foreach ([$draw, $noMoves, $running, $noAddress, $badAddress] as $game) {
        expect(app(WinnerZaps::class)->winners('game', (string) $game->id, null))->toBe([]);
        Livewire::test('zap-winner', ['type' => 'game', 'subject' => (string) $game->id])->assertDontSee('data-test="zap-winner"', false);
    }

    // On the game page itself, for a guest.
    auth()->logout();
    $this->get(route('games.show', $won))->assertOk()->assertSee('data-test="zap-winner"', false)->assertDontSee('anna@wallet.example');
    $this->get(route('games.show', $noAddress))->assertOk()->assertDontSee('data-test="zap-winner"', false);
});

test('a series offers each winning player with an address, at most five; a tournament its champion', function () {
    $winners = User::factory()->count(7)->sequence(fn ($sequence) => ['lud16' => 'w'.$sequence->index.'@wallet.example'])->create();
    $losers = User::factory()->count(2)->create(['lud16' => 'loser@wallet.example']);
    $match = SeriesMatch::factory()->create([
        'status' => SeriesStatus::Confirmed, 'winner' => 'challenged', 'finished_at' => now(),
        'rosters' => ['challenger' => $losers->modelKeys(), 'challenged' => $winners->modelKeys()],
    ]);

    $offered = app(WinnerZaps::class)->winners('series', (string) $match->number, null);

    expect(array_map(fn (array $row): int => $row['user']->id, $offered))->toBe(array_slice($winners->modelKeys(), 0, 5));
    $this->get(route('matches.show', $match->number))->assertOk()->assertSee('data-test="zap-winner"', false)->assertDontSee('@wallet.example');

    $open = SeriesMatch::factory()->create(['status' => SeriesStatus::Reported, 'winner' => 'challenged', 'rosters' => ['challenger' => $losers->modelKeys(), 'challenged' => $winners->modelKeys()]]);
    expect(app(WinnerZaps::class)->winners('series', (string) $open->number, null))->toBe([]);

    $champion = User::factory()->create(['lud16' => 'champ@wallet.example']);
    $tournament = shareTournament($champion, $losers[0]);
    expect(array_map(fn (array $row): int => $row['user']->id, app(WinnerZaps::class)->winners('tournament', (string) $tournament->id, null)))->toBe([$champion->id]);
    $this->get(route('tournaments.show', $tournament))->assertOk()->assertSee('data-test="zap-winner"', false)->assertDontSee('champ@wallet.example');
});

test('a signed zap: the previewed zap request, checked against the template, becomes the winner\'s invoice', function () {
    fakeZapWallet();
    $zapperKey = new TestSigner;
    $zapper = User::factory()->withPubkey($zapperKey->pubkey)->create();
    $anna = User::factory()->create(['lud16' => 'anna@wallet.example']);
    $league = new TestSigner;
    $record = NostrEvent::fromSigned(SignedEvent::fromInput($league->sign(64, [['alt', 'Chess game']], '1. e4 e5')));
    $game = ChessGame::factory()->rated()->finished('1-0')->create(['white_id' => $anna->id, 'black_id' => User::factory()->create()->id, 'ply' => 12, 'record_event_id' => $record->id]);

    $page = Livewire::actingAs($zapper)->test('zap-winner', ['type' => 'game', 'subject' => (string) $game->id]);
    $template = $page->instance()->prepareZap($anna->id, 210, 'GG', app(WinnerZaps::class))['template'];

    expect($template['kind'])->toBe(9734)
        ->and($template['content'])->toBe('GG')
        ->and($template['tags'])->toBe([
            ['relays', 'wss://profiles.example', 'wss://league.example'],
            ['amount', '210000'],
            ['lnurl', Lnurl::fromAddress('anna@wallet.example')],
            ['p', $anna->pubkey],
            ['e', $record->event_id],
            ['k', '64'],
        ]);

    $signed = $zapperKey->sign(9734, $template['tags'], $template['content'], now()->getTimestamp());
    $answer = $page->instance()->zapInvoice($anna->id, 210, 'GG', json_encode($signed), app(WinnerZaps::class));

    expect($answer)->toHaveKeys(['invoice', 'qr'])
        ->and($answer['invoice'])->toStartWith('lnbcrt2100n')
        ->and($answer['qr'])->toContain('<svg');

    // The callback got the amount, the lnurl and exactly the signed zap request.
    Http::assertSent(function (Request $request) use ($signed): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://wallet.example/cb/anna')
            && $query['amount'] === '210000'
            && json_decode($query['nostr'], true)['id'] === $signed['id']
            && $query['lnurl'] === Lnurl::fromAddress('anna@wallet.example');
    });
});

test('a zap is refused for a changed request, a foreign key, a wallet without zaps and an invoice that commits to something else', function () {
    $zapperKey = new TestSigner;
    $zapper = User::factory()->withPubkey($zapperKey->pubkey)->create();
    $anna = User::factory()->create(['lud16' => 'anna@wallet.example']);
    $game = ChessGame::factory()->finished('1-0')->create(['white_id' => $anna->id, 'black_id' => User::factory()->create()->id, 'ply' => 12]);
    $template = app(WinnerZaps::class)->template($zapper, 'game', (string) $game->id, $anna->id, 21, '');
    $sign = fn (TestSigner $key, array $tags) => $key->sign(9734, $tags, '', now()->getTimestamp());

    fakeZapWallet();
    $zaps = app(WinnerZaps::class);
    // Signed for 21 sats, asked for 2100.
    expect(fn () => $zaps->invoice($zapper, 'game', (string) $game->id, $anna->id, 2100, '', $sign($zapperKey, $template['tags'])))->toThrow(ZapRefused::class, 'did not match')
        // Signed by someone else.
        ->and(fn () => $zaps->invoice($zapper, 'game', (string) $game->id, $anna->id, 21, '', $sign(new TestSigner, $template['tags'])))->toThrow(ZapRefused::class, 'did not match')
        // The right key, a forged signature.
        ->and(fn () => $zaps->invoice($zapper, 'game', (string) $game->id, $anna->id, 21, '', ['sig' => str_repeat('0', 128)] + $sign($zapperKey, $template['tags'])))->toThrow(ZapRefused::class, 'did not match');
    Http::assertNothingSent();

    // NIP-57 needs both: `allowsNostr` true and a `nostrPubkey` to sign the receipt.
    foreach ([[false, true], [true, false]] as [$allows, $pubkey]) {
        fakeZapWallet(allowsNostr: $allows, nostrPubkey: $pubkey);
        $zaps = app(WinnerZaps::class);
        expect(fn () => $zaps->invoice($zapper, 'game', (string) $game->id, $anna->id, 21, '', $sign($zapperKey, $template['tags'])))->toThrow(ZapRefused::class, 'takes no Nostr zaps');
    }

    fakeZapWallet(wrongHash: true);
    $zaps = app(WinnerZaps::class);
    expect(fn () => $zaps->invoice($zapper, 'game', (string) $game->id, $anna->id, 21, '', $sign($zapperKey, $template['tags'])))->toThrow(ZapRefused::class, 'matching invoice');

    // Not the winner, too much, yourself.
    expect(fn () => $zaps->template($zapper, 'game', (string) $game->id, $game->black_id, 21, ''))->toThrow(ZapRefused::class)
        ->and(fn () => $zaps->template($zapper, 'game', (string) $game->id, $anna->id, WinnerZaps::MAX_SATS + 1, ''))->toThrow(ZapRefused::class)
        ->and(fn () => $zaps->template($anna, 'game', (string) $game->id, $anna->id, 21, ''))->toThrow(ZapRefused::class);
});
