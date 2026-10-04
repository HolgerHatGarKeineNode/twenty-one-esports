<?php

use App\Enums\IncomingPaymentStatus;
use App\Enums\TournamentFormat;
use App\Models\IncomingPayment;
use App\Support\Prizes\WalletPrizePool;

/*
| Anonymous top-ups of a pot show on the tournament page (user, 2026-10-04): whoever paid without a Nostr key sees
| their sats arrived; a zap with a key stays on the zap wall instead.
*/

test('an anonymous top-up shows in the pot, a pending one and a zap do not', function () {
    fakeWallet();
    $tournament = publishForPool(runningChess(TournamentFormat::SingleElimination, 4));
    $pay = fn (array $values) => IncomingPayment::query()->create([
        'pot' => $tournament->potAccount(), 'tournament_id' => $tournament->id, 'payment_hash' => bin2hex(random_bytes(32)),
        'bolt11' => 'lnbc1test', 'late' => false, 'expires_at' => now()->addHour(), ...$values,
    ]);
    $pay(['source' => 'topup', 'amount_sats' => 2100, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now()]);
    $pay(['source' => 'topup', 'amount_sats' => 500, 'status' => IncomingPaymentStatus::Pending]);
    $pay(['source' => 'zap', 'amount_sats' => 210, 'status' => IncomingPaymentStatus::Settled, 'settled_at' => now(), 'payer_pubkey' => str_repeat('a', 64), 'zap_verified' => true]);

    $pool = app(WalletPrizePool::class)->for($tournament->refresh());

    expect(array_column($pool['deposits'], 'sats'))->toBe([2100]);
});
