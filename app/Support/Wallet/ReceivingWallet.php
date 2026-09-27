<?php

namespace App\Support\Wallet;

use App\Support\Lightning\Bolt11;

/**
 * The league wallet's receive-only NIP-47 connection (`esports.wallet.nwc_receive_uri`):
 * invoices for the pots, their lookup, and the balance for the daily
 * reconciliation. It cannot pay: there is no method for it, and the
 * connection it holds should have no `pay_invoice` permission in the wallet
 * either. Null without a valid URI (fail closed: no invoice is made).
 */
final class ReceivingWallet
{
    private function __construct(private readonly NwcClient $client) {}

    public static function fromConfig(): ?self
    {
        return self::fromUri(config('esports.wallet.nwc_receive_uri'));
    }

    /**
     * A tournament's own wallet (P9 scope addition): only its balance is read
     * through this; paying from it goes through the payout side only.
     */
    public static function fromUri(#[\SensitiveParameter] mixed $uri): ?self
    {
        $connection = NwcConnection::fromUri($uri);

        return $connection === null ? null : new self(new NwcClient($connection, app(NwcTransport::class)));
    }

    /**
     * An invoice for `$amountSats` whose description hash is `$descriptionHash`
     * (NIP-57: SHA-256 of the zap request). The wallet's answer is checked:
     * a different amount, hash or network is refused.
     *
     * @throws NwcError
     */
    public function makeInvoice(int $amountSats, string $descriptionHash, int $expiry): Bolt11
    {
        $result = $this->client->request('make_invoice', ['amount' => $amountSats * 1000, 'description_hash' => $descriptionHash, 'expiry' => $expiry]);
        $invoice = is_string($result['invoice'] ?? null) ? Bolt11::decode($result['invoice']) : null;

        if ($invoice === null || $invoice->amountMsats !== $amountSats * 1000 || $invoice->descriptionHash !== $descriptionHash
            || ! in_array($invoice->network, (array) config('esports.wallet.invoice_networks', ['bc']), true)) {
            throw new NwcError('OTHER', 'the wallet returned an invoice that does not match');
        }

        return $invoice;
    }

    /**
     * The invoice's state at the wallet, null when the wallet does not know it.
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

    /**
     * Whether this connection may call `$method`, from the wallet's
     * `get_info` answer (NIP-47: `methods` lists what the connection is
     * permitted); null when the wallet does not answer `get_info`.
     */
    public function permits(string $method): ?bool
    {
        try {
            $methods = $this->client->request('get_info', [], 15.0)['methods'] ?? null;
        } catch (NwcError) {
            return null;
        }

        return is_array($methods) ? in_array($method, $methods, true) : null;
    }

    /**
     * @throws NwcError
     */
    public function balanceSats(): int
    {
        $balance = $this->client->request('get_balance', [], 15.0)['balance'] ?? null;

        if (! is_int($balance)) {
            throw new NwcError('OTHER', 'no balance');
        }

        return intdiv($balance, 1000);
    }
}
