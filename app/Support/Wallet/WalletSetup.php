<?php

namespace App\Support\Wallet;

use App\Models\Tournament;

/**
 * Whether the league wallet's two NIP-47 connections are set up, for pages
 * and checks that only need to say so. Deliberately not a wallet: asking
 * whether one can pay never creates the paying wallet, which only the
 * payout runner holds (tests/Feature/Payouts/WalletTest.php).
 */
final class WalletSetup
{
    public static function canPay(?Tournament $tournament = null): bool
    {
        return NwcConnection::fromUri($tournament?->hasOwnWallet() === true ? $tournament->pot_nwc_uri : config('esports.wallet.nwc_uri')) !== null;
    }

    public static function canReceive(): bool
    {
        return NwcConnection::fromUri(config('esports.wallet.nwc_receive_uri')) !== null;
    }
}
