<?php

namespace Tests\Integration\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeNwcWallet;

/**
 * P9 in the real stack: a fake NIP-47 wallet service on the local relay
 * (fake-nwc-wallet.php) and a fake LNURL-pay server for the players'
 * Lightning addresses (fake-lnurl-router.php), sharing one state file. The
 * league's app server, queue worker and artisan commands reach the wallet
 * only through the relay, with the connection URIs of pay() and receive().
 * Throwaway keys, fresh state every boot; never a real wallet or node.
 */
final class FakeNwc
{
    public readonly string $statePath;

    public readonly FakeNwcWallet $keys;

    /** @var list<InvokedProcess> */
    private array $processes = [];

    public function __construct(private readonly int $lnurlPort)
    {
        $this->statePath = storage_path('framework/testing/integration-nwc-state.json');
        // Only for the keys and URIs: the wallet itself runs in its own process.
        $this->keys = new FakeNwcWallet;
    }

    public function lnurlHost(): string
    {
        return '127.0.0.1:'.$this->lnurlPort;
    }

    /** Start both fakes once; later calls do nothing. */
    public function start(string $relayUrl): void
    {
        if ($this->processes !== []) {
            return;
        }

        @mkdir(dirname($this->statePath), 0755, true);
        file_put_contents($this->statePath, json_encode(['known' => [], 'settle' => [], 'calls' => [], 'paid' => []]));

        $this->processes[] = Process::path(base_path())->env(['FAKE_NWC_STATE' => $this->statePath])
            ->start(['php', '-S', $this->lnurlHost(), 'tests/Integration/Support/fake-lnurl-router.php']);

        $this->processes[] = Process::path(base_path())->env([
            'FAKE_NWC_STATE' => $this->statePath,
            'FAKE_NWC_RELAY' => $relayUrl,
            'FAKE_NWC_SECRET' => $this->keys->secret,
            'FAKE_NWC_PAY_SECRET' => $this->keys->clients['pay']['secret'],
            'FAKE_NWC_RECEIVE_SECRET' => $this->keys->clients['receive']['secret'],
        ])->forever()->start(['php', 'tests/Integration/Support/fake-nwc-wallet.php']);

        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $this->lnurlPort); $i++) {
            usleep(100_000);
        }

        // NIP-47 requests are ephemeral: the wallet must be subscribed before the first one is sent.
        for ($i = 0; $i < 100 && ! (json_decode((string) file_get_contents($this->statePath), true)['ready'] ?? false); $i++) {
            usleep(100_000);
        }

        if (! (json_decode((string) file_get_contents($this->statePath), true)['ready'] ?? false)) {
            throw new \RuntimeException('The fake NWC wallet did not subscribe on the relay within 10 s.');
        }
    }

    public function stop(): void
    {
        foreach ($this->processes as $process) {
            try {
                $process->stop(2);
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /** The connection URI the league pays with, or receives with. */
    public function uri(string $role, string $relayUrl): string
    {
        return 'nostr+walletconnect://'.$this->keys->pubkey.'?relay='.rawurlencode($relayUrl).'&secret='.$this->keys->clients[$role]['secret'];
    }

    /** A payer pays one of the wallet's own invoices. */
    public function settle(string $paymentHash): void
    {
        $this->update(function (array $state) use ($paymentHash): array {
            $state['settle'][] = $paymentHash;

            return $state;
        });
    }

    /**
     * @return array{known: array<string, string>, settle: list<string>, calls: list<array{client: string, method: string, payment_hash: string|null}>, paid: array<string, array{amount_msats: int}>}
     */
    public function state(): array
    {
        $state = json_decode((string) file_get_contents($this->statePath), true);

        return is_array($state) ? $state + ['known' => [], 'settle' => [], 'calls' => [], 'paid' => []] : ['known' => [], 'settle' => [], 'calls' => [], 'paid' => []];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $work
     */
    private function update(callable $work): void
    {
        $handle = fopen($this->statePath, 'c+');
        flock($handle, LOCK_EX);
        $state = json_decode((string) stream_get_contents($handle), true) ?: [];
        $state = $work($state);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) json_encode($state));
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
