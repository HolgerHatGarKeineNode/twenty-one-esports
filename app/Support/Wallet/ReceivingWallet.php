<?php

namespace App\Support\Wallet;

use App\Support\Lightning\Bolt11;

/**
 * The league wallet's receive-only NIP-47 connection (`esports.wallet.nwc_receive_uri`):
 * invoices for the pots and their lookup; it never reads the wallet's
 * balance (user, 2026-10-05). It cannot pay: there is no method for it, and the
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
     * A receive-only connection from its URI; paying always goes through the
     * payout side only.
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
     * A plain invoice for `$amountSats` with a text description (a top-up of
     * a tournament pot: no zap request, no receipt). The wallet's answer is
     * checked: a different amount or network is refused.
     *
     * @throws NwcError
     */
    public function makePlainInvoice(int $amountSats, string $description, int $expiry): Bolt11
    {
        $result = $this->client->request('make_invoice', ['amount' => $amountSats * 1000, 'description' => $description, 'expiry' => $expiry]);
        $invoice = is_string($result['invoice'] ?? null) ? Bolt11::decode($result['invoice']) : null;

        if ($invoice === null || $invoice->amountMsats !== $amountSats * 1000
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
        $methods = $this->methods();

        return $methods === null ? null : in_array($method, $methods, true);
    }

    /**
     * What this connection may call (NIP-47 `get_info`), null when the wallet
     * does not answer it.
     *
     * @return list<string>|null
     */
    public function methods(): ?array
    {
        try {
            $methods = $this->client->request('get_info', [], 15.0)['methods'] ?? null;
        } catch (NwcError) {
            return null;
        }

        return is_array($methods) ? array_values(array_filter($methods, is_string(...))) : null;
    }
}
