<?php

namespace Tests\Integration\Support;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/**
 * A local, throwaway Esplora-shaped Bitcoin block API (see
 * fake-bitcoin-router.php), the fake API the P15 DoD requires ("use a fake
 * Bitcoin block API, never the live one") for App\Support\Tournaments\
 * TournamentDraws / BitcoinBlocks. One instance per Stack::boot(), state
 * reset every run.
 */
final class FakeBitcoin
{
    private ?InvokedProcess $process = null;

    private string $statePath;

    public function __construct(private readonly int $port)
    {
        $this->statePath = storage_path('framework/testing/integration-btc-state.json');
    }

    public function start(): void
    {
        $this->writeState(['tip' => 0, 'hashes' => [], 'times' => []]);

        $this->process = Process::path(base_path())
            ->env(['FAKE_BTC_STATE' => $this->statePath])
            ->start(['php', '-S', '127.0.0.1:'.$this->port, 'tests/Integration/Support/fake-bitcoin-router.php']);

        for ($i = 0; $i < 50 && ! @fsockopen('127.0.0.1', $this->port); $i++) {
            usleep(100_000);
        }
    }

    public function stop(): void
    {
        $this->process?->stop(3);
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /**
     * Mine a block at $height: a deterministic hash (so assertions can
     * predict the draw the same way DrawOrder does), minedAt defaults to
     * now. Raises the tip to at least $height.
     */
    public function mine(int $height, ?int $minedAt = null): string
    {
        $hash = hash('sha256', 'fake-block-'.$height);
        $state = $this->readState();
        $state['hashes'][(string) $height] = $hash;
        $state['times'][$hash] = $minedAt ?? now()->getTimestamp();
        $state['tip'] = max($state['tip'], $height);
        $this->writeState($state);

        return $hash;
    }

    /** Raise the tip without necessarily mining a hash for every height in between. */
    public function setTip(int $height): void
    {
        $state = $this->readState();
        $state['tip'] = max($state['tip'], $height);
        $this->writeState($state);
    }

    public function tipHeight(): int
    {
        return (int) $this->readState()['tip'];
    }

    /** @return array{tip: int, hashes: array<string, string>, times: array<string, int>} */
    private function readState(): array
    {
        $state = is_file($this->statePath) ? json_decode((string) file_get_contents($this->statePath), true) : null;

        return is_array($state) ? $state : ['tip' => 0, 'hashes' => [], 'times' => []];
    }

    /** @param  array{tip: int, hashes: array<string, string>, times: array<string, int>}  $state */
    private function writeState(array $state): void
    {
        @mkdir(dirname($this->statePath), 0755, true);
        file_put_contents($this->statePath, json_encode($state));
    }
}
