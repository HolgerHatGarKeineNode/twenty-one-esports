<?php

namespace App\Support\Wallet;

use App\Models\Tournament;

/**
 * A NIP-47 connection that pays: a tournament pot's own wallet
 * ({@see forTournament()}), or the league wallet's paying connection
 * (`esports.wallet.nwc_uri`, the Season-Chain's only, never a tournament's).
 * Only the payout runner holds one (App\Support\Payouts\PayoutRunner;
 * tests/Feature/Payouts/WalletTest.php keeps it that way): receiving,
 * counting and reconciling use {@see ReceivingWallet}. Null without a valid
 * URI (fail closed: no payout is attempted).
 */
final class PayingWallet
{
    private function __construct(private readonly NwcClient $client) {}

    public static function fromConfig(): ?self
    {
        $connection = NwcConnection::fromUri(config('esports.wallet.nwc_uri'));

        return $connection === null ? null : new self(new NwcClient($connection, app(NwcTransport::class)));
    }

    /**
     * The wallet a tournament's prizes are paid from: its pot's own NWC
     * wallet, and nothing else. Null without one (a tournament never falls
     * back to the league wallet).
     */
    public static function forTournament(Tournament $tournament): ?self
    {
        if (! $tournament->hasOwnWallet()) {
            return null;
        }

        $connection = NwcConnection::fromUri($tournament->pot_nwc_uri);

        return $connection === null ? null : new self(new NwcClient($connection, app(NwcTransport::class)));
    }

    /**
     * Pay an invoice. The answer's preimage is returned as the wallet sent
     * it; the caller checks it against the invoice's payment hash.
     *
     * @return array{preimage: string, fees_msats: int|null}
     *
     * @throws NwcError TIMEOUT when no answer came: the payment may or may not have happened
     */
    public function pay(string $bolt11): array
    {
        $result = $this->client->request('pay_invoice', ['invoice' => $bolt11]);
        $preimage = is_string($result['preimage'] ?? null) ? strtolower($result['preimage']) : '';

        return ['preimage' => $preimage, 'fees_msats' => is_int($result['fees_paid'] ?? null) ? $result['fees_paid'] : null];
    }

    /**
     * An outgoing payment's state at the wallet, null when the wallet does
     * not know the payment hash.
     *
     * @throws NwcError
     */
    public function lookup(string $paymentHash): ?WalletTransaction
    {
        try {
            return WalletTransaction::fromResult($this->client->request('lookup_invoice', ['payment_hash' => $paymentHash], 15.0));
        } catch (NwcError $error) {
            if ($error->errorCode === 'NOT_FOUND') {
                return null;
            }

            throw $error;
        }
    }
}
