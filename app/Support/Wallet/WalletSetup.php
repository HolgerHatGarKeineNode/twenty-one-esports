<?php

namespace App\Support\Wallet;

use App\Models\Tournament;

/**
 * Whether a NIP-47 connection is set up (the league wallet's two, or a
 * tournament pot's own), for pages
 * and checks that only need to say so. Deliberately not a wallet: asking
 * whether one can pay never creates the paying wallet, which only the
 * payout runner holds (tests/Feature/Payouts/WalletTest.php).
 */
final class WalletSetup
{
    /** The league wallet's paying connection (the Season-Chain's). */
    public static function canPay(): bool
    {
        return NwcConnection::fromUri(config('esports.wallet.nwc_uri')) !== null;
    }

    /** A tournament pot's own wallet connection. */
    public static function potCanPay(Tournament $tournament): bool
    {
        return $tournament->hasOwnWallet() && NwcConnection::fromUri($tournament->pot_nwc_uri) !== null;
    }

    public static function canReceive(): bool
    {
        return NwcConnection::fromUri(config('esports.wallet.nwc_receive_uri')) !== null;
    }
}
