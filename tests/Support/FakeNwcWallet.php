<?php

namespace Tests\Support;

use App\Support\Lightning\Bolt11;
use App\Support\Nostr\SignedEvent;
use App\Support\Wallet\NwcCipher;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * A NIP-47 wallet service for the tests (P9 DoD "Fake-NWC"): it speaks the
 * real protocol, so the league's client, its signing and its encryption are
 * exercised as against a real wallet. It takes a signed kind 23194 request,
 * checks author, recipient, expiration and (with `verifyRequests`) the
 * signature, decrypts it with its
 * own key (NIP-44 or NIP-04, as the request says), acts on it, and answers
 * with an encrypted kind 23195 signed by the wallet.
 *
 * Money rules of a real node that matter here: one invoice (payment hash)
 * is paid at most once, a payment needs the preimage the invoice's issuer
 * knows, and the balance goes down by what was paid.
 *
 * Used in-process through FakeNwcTransport by the feature tests, and as a
 * relay client in the integration suite (tests/Integration/Support/fake-nwc-wallet.php).
 *
 * Levers: `failNext` answers the next pay_invoice with that error code;
 * `loseNextAnswer` pays but sends no answer (the league sees a timeout);
 * `ignoreNextRequest` neither pays nor answers.
 */
final class FakeNwcWallet
{
    public readonly string $secret;

    public readonly string $pubkey;

    /** @var array<string, array{secret: string, pubkey: string}> the two connections, `pay` and `receive` */
    public readonly array $clients;

    /** @var list<array{client: string, method: string, params: array<string, mixed>}> every request that arrived, with the connection it came on */
    public array $calls = [];

    /** @var array<string, array{invoice: string, amount_msats: int, preimage: string, paid_at: int}> outgoing, by payment hash */
    public array $paid = [];

    /** @var array<string, array{invoice: string, amount_msats: int, preimage: string, settled_at: int|null, description_hash: string|null}> incoming, by payment hash */
    public array $invoices = [];

    /** @var array<string, string> preimages of invoices other nodes issued, by payment hash (the fake Lightning addresses) */
    public array $known = [];

    public int $balanceMsats = 50_000_000_000;

    public ?string $failNext = null;

    public bool $loseNextAnswer = false;

    public bool $ignoreNextRequest = false;

    /** Runs when a valid request arrives, before it is answered (to change the world mid-request). */
    public ?\Closure $onRequest = null;

    /** @var list<string>|null what `get_info` lists for the `pay` connection; null = everything */
    public ?array $payMethods = null;

    public int $feeMsats = 1000;

    /** @var list<string> */
    public array $encryption = [NwcCipher::NIP44, NwcCipher::NIP04];

    public string $relay = 'wss://nwc.fake.invalid';

    /**
     * Check each request's Schnorr signature, as a real wallet does. Off in
     * the feature tests for speed (about 0.1 s per request in PHP); the
     * wallet unit test and the integration suite switch it on.
     */
    public bool $verifyRequests = false;

    /**
     * @param  array<string, string>  $clientSecrets  `pay` and `receive` secrets (fresh ones by default)
     */
    public function __construct(?string $secret = null, array $clientSecrets = [])
    {
        $this->secret = $secret ?? bin2hex(random_bytes(32));
        $this->pubkey = (new Key)->getPublicKey($this->secret);
        $clients = [];

        foreach (['pay', 'receive'] as $role) {
            $clientSecret = $clientSecrets[$role] ?? bin2hex(random_bytes(32));
            $clients[$role] = ['secret' => $clientSecret, 'pubkey' => (new Key)->getPublicKey($clientSecret)];
        }

        $this->clients = $clients;
    }

    /** The connection URI of one of the two clients, `pay` or `receive`. */
    public function uri(string $role, ?string $lud16 = null): string
    {
        return 'nostr+walletconnect://'.$this->pubkey.'?relay='.rawurlencode($this->relay).'&secret='.$this->clients[$role]['secret']
            .($lud16 === null ? '' : '&lud16='.rawurlencode($lud16));
    }

    /**
     * @return list<string> the payment hashes pay_invoice was asked for, in order
     */
    public function payRequests(): array
    {
        return array_values(array_map(fn (array $call): string => (string) Bolt11::decode((string) ($call['params']['invoice'] ?? ''))?->paymentHash,
            array_filter($this->calls, fn (array $call): bool => $call['method'] === 'pay_invoice')));
    }

    /** A payer settles one of the wallet's own invoices (a zap arrived). */
    public function settleIncoming(string $paymentHash, ?int $at = null): void
    {
        if (isset($this->invoices[$paymentHash]) && $this->invoices[$paymentHash]['settled_at'] === null) {
            $this->invoices[$paymentHash]['settled_at'] = $at ?? time();
            $this->balanceMsats += $this->invoices[$paymentHash]['amount_msats'];
        }
    }

    /**
     * The wallet's info event (13194) with its encryption schemes.
     *
     * @return array<string, mixed>
     */
    public function infoEvent(): array
    {
        $tags = $this->encryption === [] ? [] : [['encryption', implode(' ', $this->encryption)]];

        return $this->sign(13194, 'pay_invoice make_invoice lookup_invoice get_balance', $tags);
    }

    /**
     * Answer one request event, or null when there is no answer.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>|null
     */
    public function handle(array $request): ?array
    {
        $event = SignedEvent::fromInput($request);

        $role = array_search($event?->pubkey, array_column($this->clients, 'pubkey', null), true);
        $role = $role === false ? null : array_keys($this->clients)[$role];

        if ($event === null || $role === null || $event->kind !== 23194 || $event->tag('p') !== $this->pubkey || ($this->verifyRequests && ! $event->hasValidSignature())) {
            return null;
        }

        $client = $this->clients[$role]['pubkey'];

        $expiration = $event->tag('expiration');

        if ($expiration !== null && (int) $expiration < time()) {
            return null;
        }

        if ($this->onRequest !== null) {
            ($this->onRequest)();
        }

        if ($this->ignoreNextRequest) {
            $this->ignoreNextRequest = false;

            return null;
        }

        $scheme = $event->tag('encryption') === NwcCipher::NIP44 ? NwcCipher::NIP44 : NwcCipher::NIP04;
        $body = json_decode(NwcCipher::decrypt($scheme, $event->content, $this->secret, $client), true);
        $method = (string) ($body['method'] ?? '');
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];
        $this->calls[] = ['client' => $role, 'method' => $method, 'params' => $params];

        $answer = match ($method) {
            // The receive connection has no permission to pay (as set up in a real wallet).
            'pay_invoice' => $role !== 'pay' ? ['error' => ['code' => 'RESTRICTED', 'message' => 'no pay permission']] : $this->payInvoice((string) ($params['invoice'] ?? '')),
            'make_invoice' => $this->makeInvoice((int) ($params['amount'] ?? 0), is_string($params['description_hash'] ?? null) ? $params['description_hash'] : null, (int) ($params['expiry'] ?? 3600)),
            'lookup_invoice' => $this->lookup((string) ($params['payment_hash'] ?? '')),
            'get_balance' => ['result' => ['balance' => $this->balanceMsats]],
            'get_info' => ['result' => ['alias' => 'fake', 'network' => 'regtest', 'methods' => $role === 'pay' ? ($this->payMethods ?? ['pay_invoice', 'make_invoice', 'lookup_invoice', 'get_balance', 'get_info']) : ['make_invoice', 'lookup_invoice', 'get_balance', 'get_info']]],
            default => ['error' => ['code' => 'NOT_IMPLEMENTED', 'message' => $method]],
        };

        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;

            return null;
        }

        $content = NwcCipher::encrypt($scheme, (string) json_encode(['result_type' => $method, ...$answer]), $this->secret, $client);

        return $this->sign(23195, $content, [['p', $client], ['e', $event->id]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payInvoice(string $bolt11): array
    {
        $invoice = Bolt11::decode($bolt11);

        if ($invoice === null || $invoice->amountMsats === null) {
            return ['error' => ['code' => 'OTHER', 'message' => 'invalid invoice']];
        }

        if ($this->failNext !== null) {
            $code = $this->failNext;
            $this->failNext = null;

            return ['error' => ['code' => $code, 'message' => 'refused by the fake wallet']];
        }

        if (isset($this->paid[$invoice->paymentHash])) {
            return ['error' => ['code' => 'OTHER', 'message' => 'invoice is already paid']];
        }

        $preimage = $this->known[$invoice->paymentHash] ?? null;

        if ($preimage === null || $invoice->isExpired()) {
            return ['error' => ['code' => 'PAYMENT_FAILED', 'message' => 'no route']];
        }

        if ($this->balanceMsats < $invoice->amountMsats + $this->feeMsats) {
            return ['error' => ['code' => 'INSUFFICIENT_BALANCE', 'message' => 'not enough sats']];
        }

        $this->balanceMsats -= $invoice->amountMsats + $this->feeMsats;
        $this->paid[$invoice->paymentHash] = ['invoice' => $bolt11, 'amount_msats' => $invoice->amountMsats, 'preimage' => $preimage, 'paid_at' => time()];

        return ['result' => ['preimage' => $preimage, 'fees_paid' => $this->feeMsats]];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeInvoice(int $amountMsats, ?string $descriptionHash, int $expiry): array
    {
        $fixture = Bolt11Fixture::make($amountMsats, $descriptionHash ?? hash('sha256', ''), $expiry);
        $this->invoices[$fixture['payment_hash']] = ['invoice' => $fixture['invoice'], 'amount_msats' => $amountMsats, 'preimage' => $fixture['preimage'], 'settled_at' => null, 'description_hash' => $descriptionHash];

        return ['result' => ['type' => 'incoming', 'invoice' => $fixture['invoice'], 'payment_hash' => $fixture['payment_hash'], 'amount' => $amountMsats, 'created_at' => time(), 'expires_at' => time() + $expiry]];
    }

    /**
     * @return array<string, mixed>
     */
    private function lookup(string $paymentHash): array
    {
        if (isset($this->paid[$paymentHash])) {
            $paid = $this->paid[$paymentHash];

            return ['result' => ['type' => 'outgoing', 'state' => 'settled', 'invoice' => $paid['invoice'], 'payment_hash' => $paymentHash, 'amount' => $paid['amount_msats'], 'preimage' => $paid['preimage'], 'settled_at' => $paid['paid_at'], 'fees_paid' => $this->feeMsats]];
        }

        if (isset($this->invoices[$paymentHash])) {
            $invoice = $this->invoices[$paymentHash];
            $settled = $invoice['settled_at'] !== null;

            return ['result' => ['type' => 'incoming', 'state' => $settled ? 'settled' : 'pending', 'invoice' => $invoice['invoice'], 'payment_hash' => $paymentHash,
                'amount' => $invoice['amount_msats'], 'preimage' => $settled ? $invoice['preimage'] : null, 'settled_at' => $invoice['settled_at']]];
        }

        return ['error' => ['code' => 'NOT_FOUND', 'message' => 'unknown payment hash']];
    }

    /**
     * @param  list<list<string>>  $tags
     * @return array<string, mixed>
     */
    private function sign(int $kind, string $content, array $tags): array
    {
        $event = (new Event)->setKind($kind)->setContent($content)->setTags($tags)->setCreatedAt(time());
        (new Sign)->signEvent($event, $this->secret);

        return $event->toArray();
    }
}
