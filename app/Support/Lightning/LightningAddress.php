<?php

namespace App\Support\Lightning;

use App\Support\Nostr\HostResolver;
use App\Support\Nostr\Nip05Verifier;
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
 * address, https only, no redirects, short timeouts, at most 64 KB read. The
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

    public function __construct(private Factory $http, private HostResolver $resolver) {}

    /**
     * @throws LightningAddressFailure
     */
    public function invoice(string $lud16, int $amountSats): Bolt11
    {
        $target = self::target($lud16) ?? throw new LightningAddressFailure('lnurl_invalid', 'not a Lightning address');
        $document = $this->getJson($target['base'].'/.well-known/lnurlp/'.$target['name']);

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
        $answer = $this->getJson($callback.$separator.http_build_query(['amount' => $msats]));

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
    private function getJson(string $url): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = strtolower($parts['host'] ?? '');
        $hostPort = isset($parts['port']) ? $host.':'.$parts['port'] : $host;

        if (isset($parts['user']) || isset($parts['pass']) || $host === '') {
            throw new LightningAddressFailure('lnurl_invalid', 'bad URL');
        }

        $options = ['stream' => true];

        if (in_array($hostPort, (array) config('esports.wallet.lnurl_insecure_hosts', []), true)) {
            if (! in_array($scheme, ['http', 'https'], true)) {
                throw new LightningAddressFailure('lnurl_invalid', 'bad URL');
            }
        } else {
            if ($scheme !== 'https' || isset($parts['port']) || Nip05Verifier::target('x@'.$host) === null) {
                throw new LightningAddressFailure('lnurl_invalid', 'only https on a DNS name');
            }

            $address = $this->publicAddress($host) ?? throw new LightningAddressFailure('lnurl_unreachable', 'the host does not resolve to a public address');
            $options['curl'] = [CURLOPT_RESOLVE => [$host.':443:'.(str_contains($address, ':') ? '['.$address.']' : $address)]];
        }

        try {
            $response = $this->http->acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()->withOptions($options)->get($url);

            if ($response->status() !== 200) {
                throw new LightningAddressFailure('lnurl_unreachable', 'HTTP '.$response->status());
            }

            $body = $response->toPsrResponse()->getBody();
            $json = '';

            while (! $body->eof() && strlen($json) <= self::MAX_BYTES) {
                $json .= $body->read(8192);
            }
        } catch (LightningAddressFailure $failure) {
            throw $failure;
        } catch (Throwable $exception) {
            Log::info('Lightning address request failed', ['host' => $host, 'error' => $exception->getMessage()]);

            throw new LightningAddressFailure('lnurl_unreachable', 'no answer');
        }

        $document = strlen($json) > self::MAX_BYTES ? null : json_decode($json, true);

        if (! is_array($document)) {
            throw new LightningAddressFailure('lnurl_invalid', 'not JSON');
        }

        return $document;
    }

    private function publicAddress(string $host): ?string
    {
        $addresses = $this->resolver->addresses($host);

        foreach ($addresses as $address) {
            if (! Nip05Verifier::isPublicAddress($address)) {
                return null;
            }
        }

        return $addresses[0] ?? null;
    }
}
