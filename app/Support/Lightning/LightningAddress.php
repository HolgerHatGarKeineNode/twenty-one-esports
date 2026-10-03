<?php

namespace App\Support\Lightning;

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;
use App\Support\Nostr\PinnedFetch;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * An invoice for a player's Lightning address (LUD-16 on LUD-06, "LNURL-pay"):
 * `https://<domain>/.well-known/lnurlp/<name>`, then its callback with the
 * amount. Used by the payout runner only.
 *
 * The address comes from a player's own Nostr profile, so both requests go
 * to hosts a stranger chooses. As in {@see Nip05Verifier}: plain DNS names
 * only, every resolved address public, the connection pinned to the checked
 * address, https only, no redirects, at most 64 KB read, and a total deadline
 * per request and per invoice (P47 audit F1/F3: on curl, {@see PinnedFetch}). The
 * callback's host is checked the same way. `esports.wallet.lnurl_insecure_hosts`
 * (the integration suite's local fake, empty in production) lifts the
 * public-address rule and allows plain http for exactly those `host:port`s.
 *
 * The invoice is checked before anyone pays it (LUD-06: "LN WALLET Verifies
 * that h tag in provided invoice is a hash of metadata string"): its network
 * is one of `esports.wallet.invoice_networks`, its amount is the amount
 * asked for, its description hash is SHA-256 of the metadata, and it is
 * valid for at least another minute.
 */
class LightningAddress
{
    public const MAX_BYTES = 65536;

    /**
     * One request at most this long, connect included (P47 audit F1: a total
     * deadline, not an idle time per read); `esports.wallet.lnurl_request_seconds`.
     */
    public const REQUEST_SECONDS = 8;

    /** Both requests of one invoice (the payRequest and its callback) together; `esports.wallet.lnurl_budget_seconds`. */
    public const BUDGET_SECONDS = 12;

    public function __construct(private Factory $http, private HostResolver $resolver) {}

    /**
     * @throws LightningAddressFailure
     */
    public function invoice(string $lud16, int $amountSats): Bolt11
    {
        $target = self::target($lud16) ?? throw new LightningAddressFailure('lnurl_invalid', 'not a Lightning address');
        $deadline = microtime(true) + (float) config('esports.wallet.lnurl_budget_seconds', self::BUDGET_SECONDS);
        $document = $this->getJson($this->base($target).'/.well-known/lnurlp/'.$target['name'], $deadline);

        $callback = $document['callback'] ?? null;
        $metadata = $document['metadata'] ?? null;
        $min = $document['minSendable'] ?? null;
        $max = $document['maxSendable'] ?? null;

        if (($document['tag'] ?? null) !== 'payRequest' || ! is_string($callback) || ! is_string($metadata) || ! is_int($min) || ! is_int($max)) {
            throw new LightningAddressFailure('lnurl_invalid', 'not a payRequest');
        }

        $msats = $amountSats * 1000;

        if ($msats < $min || $msats > $max) {
            throw new LightningAddressFailure('amount_out_of_range', 'amount outside minSendable..maxSendable');
        }

        $separator = str_contains($callback, '?') ? '&' : '?';
        $answer = $this->getJson($callback.$separator.http_build_query(['amount' => $msats]), $deadline);

        if (($answer['status'] ?? null) === 'ERROR' || ! is_string($answer['pr'] ?? null)) {
            throw new LightningAddressFailure('lnurl_invalid', 'the callback returned no invoice');
        }

        $invoice = Bolt11::decode($answer['pr']);

        if ($invoice === null
            || ! in_array($invoice->network, (array) config('esports.wallet.invoice_networks', ['bc']), true)
            || $invoice->amountMsats !== $msats
            || $invoice->descriptionHash !== hash('sha256', $metadata)
            || $invoice->expiresAt() < time() + 60
        ) {
            throw new LightningAddressFailure('invoice_mismatch', 'the invoice does not match the request');
        }

        return $invoice;
    }

    /**
     * A NIP-57 zap invoice (P47, "zap the winner"): the payRequest of the
     * address must allow Nostr (`allowsNostr` true, a hex `nostrPubkey`), the
     * callback gets the amount, the signed zap request as `nostr` and the
     * `lnurl`, and the invoice must be for the amount asked for and commit to
     * exactly that zap request (description hash = SHA-256 of the JSON sent,
     * NIP-57 appendix D), on an allowed network, valid for another minute.
     * The same host checks as {@see invoice()}; nothing is stored.
     *
     * @throws LightningAddressFailure `no_zaps` when the address takes no zaps
     */
    public function zapInvoice(string $lud16, int $amountSats, string $zapRequestJson, string $lnurl): Bolt11
    {
        $target = self::target($lud16) ?? throw new LightningAddressFailure('lnurl_invalid', 'not a Lightning address');
        $deadline = microtime(true) + (float) config('esports.wallet.lnurl_budget_seconds', self::BUDGET_SECONDS);
        $document = $this->getJson($this->base($target).'/.well-known/lnurlp/'.$target['name'], $deadline);

        $callback = $document['callback'] ?? null;
        $min = $document['minSendable'] ?? null;
        $max = $document['maxSendable'] ?? null;

        if (($document['tag'] ?? null) !== 'payRequest' || ! is_string($callback) || ! is_int($min) || ! is_int($max)) {
            throw new LightningAddressFailure('lnurl_invalid', 'not a payRequest');
        }

        if (($document['allowsNostr'] ?? null) !== true || ! is_string($document['nostrPubkey'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $document['nostrPubkey']) !== 1) {
            throw new LightningAddressFailure('no_zaps', 'the address takes no zaps');
        }

        $msats = $amountSats * 1000;

        if ($msats < $min || $msats > $max) {
            throw new LightningAddressFailure('amount_out_of_range', 'amount outside minSendable..maxSendable');
        }

        $separator = str_contains($callback, '?') ? '&' : '?';
        $answer = $this->getJson($callback.$separator.http_build_query(['amount' => $msats, 'nostr' => $zapRequestJson, 'lnurl' => $lnurl]), $deadline);

        if (($answer['status'] ?? null) === 'ERROR' || ! is_string($answer['pr'] ?? null)) {
            throw new LightningAddressFailure('lnurl_invalid', 'the callback returned no invoice');
        }

        $invoice = Bolt11::decode($answer['pr']);

        if ($invoice === null
            || ! in_array($invoice->network, (array) config('esports.wallet.invoice_networks', ['bc']), true)
            || $invoice->amountMsats !== $msats
            || $invoice->expiresAt() < time() + 60
        ) {
            throw new LightningAddressFailure('invoice_mismatch', 'the invoice does not match the zap request');
        }

        // The description hash is not required to match (user decision 2026-10-03, "Primal MUSS gehen"): Primal
        // hashes something other than the zap request we send. A mismatch is logged.
        if ($invoice->descriptionHash !== hash('sha256', $zapRequestJson)) {
            Log::info('Zap invoice with a foreign description hash taken', ['host' => parse_url($callback, PHP_URL_HOST), 'msats' => $msats]);
        }

        return $invoice;
    }

    /**
     * Name, host and base URL of a Lightning address, or null when it is not
     * a plain `name@dns-name` (or one of the configured insecure test hosts).
     *
     * @return array{name: string, host: string, base: string}|null
     */
    public static function target(string $lud16): ?array
    {
        $lud16 = strtolower(trim($lud16));

        if (strlen($lud16) > 320 || preg_match('/^([a-z0-9._+-]{1,64})@(.+)$/', $lud16, $parts) !== 1) {
            return null;
        }

        if (in_array($parts[2], (array) config('esports.wallet.lnurl_insecure_hosts', []), true)) {
            return ['name' => $parts[1], 'host' => $parts[2], 'base' => 'http://'.$parts[2]];
        }

        $dns = Nip05Verifier::target('x@'.$parts[2]);

        return $dns === null ? null : ['name' => $parts[1], 'host' => $dns['host'], 'base' => 'https://'.$dns['host']];
    }

    /**
     * GET a JSON document from a URL whose host passes the checks above.
     *
     * @return array<string, mixed>
     *
     * @throws LightningAddressFailure
     */
    private function getJson(string $url, float $deadline): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower($parts['host'] ?? '');
        $hostPort = isset($parts['port']) ? $host.':'.$parts['port'] : $host;

        if (isset($parts['user']) || isset($parts['pass']) || $host === '') {
            throw new LightningAddressFailure('lnurl_invalid', 'bad URL');
        }

        $address = null;

        if (in_array($hostPort, (array) config('esports.wallet.lnurl_insecure_hosts', []), true)) {
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new LightningAddressFailure('lnurl_invalid', 'bad URL');
            }
        } else {
            if ($scheme !== 'https' || (isset($parts['port']) && $parts['port'] !== $this->port()) || Nip05Verifier::target('x@'.$host) === null) {
                throw new LightningAddressFailure('lnurl_invalid', 'only https on a DNS name');
            }

            // The lookup counts against the budget too (re-audit N2: a silent DNS server added 10 s).
            $lookup = $deadline - microtime(true);

            if ($lookup < 1) {
                throw new LightningAddressFailure('lnurl_unreachable', 'no time left');
            }

            $address = $this->publicAddress($host, $lookup) ?? throw new LightningAddressFailure('lnurl_unreachable', 'the host does not resolve to a public address');
        }

        $left = min((float) config('esports.wallet.lnurl_request_seconds', self::REQUEST_SECONDS), $deadline - microtime(true));

        if ($left < 0.2) {
            throw new LightningAddressFailure('lnurl_unreachable', 'no time left');
        }

        try {
            // The curl handler (PinnedFetch): the pin holds, the timeout is a total deadline, nothing is inflated, and curl aborts past MAX_BYTES.
            $response = $this->http->setHandler(PinnedFetch::handler())->acceptJson()->connectTimeout(min(3, $left))->timeout($left)->withoutRedirecting()
                ->withOptions([...PinnedFetch::options($host, $address, $this->port(), self::MAX_BYTES), ...($address === null ? [] : $this->extraOptions())])->get($url);

            if ($response->status() !== 200) {
                throw new LightningAddressFailure('lnurl_unreachable', 'HTTP '.$response->status());
            }

            $json = PinnedFetch::body($response, self::MAX_BYTES) ?? throw new LightningAddressFailure('lnurl_invalid', 'encoded or too large');
        } catch (LightningAddressFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            Log::info('Lightning address request failed', ['host' => $host, 'error' => $exception->getMessage()]);

            throw new LightningAddressFailure('lnurl_unreachable', 'no answer');
        }

        $document = json_decode($json, true);

        if (! is_array($document)) {
            throw new LightningAddressFailure('lnurl_invalid', 'not JSON');
        }

        return $document;
    }

    /**
     * The base URL of a target: `https://host`, with the port only where it is not 443 (a test hook).
     *
     * @param  array{name: string, host: string, base: string}  $target
     */
    private function base(array $target): string
    {
        return str_starts_with($target['base'], 'https://') && $this->port() !== 443 ? $target['base'].':'.$this->port() : $target['base'];
    }

    /** The only port a Lightning address is fetched from (a test hook: the local HTTPS server's port). */
    protected function port(): int
    {
        return 443;
    }

    /** Whether an address may be connected to at all (a test hook: loopback for the local server). */
    protected function isAllowedAddress(string $address): bool
    {
        return Nip05Verifier::isPublicAddress($address);
    }

    /**
     * Further request options (a test hook: `verify` with the local server's CA file).
     *
     * @return array<string, mixed>
     */
    protected function extraOptions(): array
    {
        return [];
    }

    private function publicAddress(string $host, float $seconds): ?string
    {
        $addresses = $this->resolver->addressesWithin($host, $seconds);

        foreach ($addresses as $address) {
            if (! $this->isAllowedAddress($address)) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }
}
