<?php

namespace App\Support\Wallet;

use App\Models\Tournament;

/**
 * Whether a NIP-47 connection is set up (the league wallet's two, or a
 * legacy tournament pot's own), for pages and checks that only need to say
 * so. Deliberately not a wallet: asking whether one can pay never creates
 * the paying wallet, which only the payout runner holds
 * (tests/Feature/Payouts/WalletTest.php).
 */
final class WalletSetup
{
    /** The league wallet's paying connection. */
    public static function canPay(): bool
    {
        return NwcConnection::fromUri(config('esports.wallet.nwc_uri')) !== null;
    }

    /**
     * Whether a tournament's prizes can be paid: from the league wallet
     * (every pot since 2026-10-02), or a legacy pot's own wallet.
     */
    public static function potCanPay(Tournament $tournament): bool
    {
        if ($tournament->hasOwnWallet()) {
            return NwcConnection::fromUri($tournament->pot_nwc_uri) !== null;
        }

        return $tournament->hasLeaguePot() && self::canPay();
    }

    public static function canReceive(): bool
    {
        return NwcConnection::fromUri(config('esports.wallet.nwc_receive_uri')) !== null;
    }
}
