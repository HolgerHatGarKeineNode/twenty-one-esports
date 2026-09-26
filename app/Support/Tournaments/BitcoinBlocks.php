<?php

namespace App\Support\Tournaments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Reads the Bitcoin chain tip and block hashes from an Esplora-compatible
 * API (`esports.bitcoin.api`). Null whenever the answer is missing or not
 * what a height or a hash looks like: a draw never runs on a guess.
 */
class BitcoinBlocks
{
    public function tipHeight(): ?int
    {
        $body = $this->get('/blocks/tip/height');

        return $body !== null && preg_match('/^\d{1,9}$/', $body) === 1 ? (int) $body : null;
    }

    /**
     * The hash of the block at this height, lower-case hex, or null while it
     * is not mined (or the API cannot be read).
     */
    public function hashAt(int $height): ?string
    {
        $body = $this->get('/block-height/'.$height);

        return $body !== null && preg_match('/^[0-9a-f]{64}$/', strtolower($body)) === 1 ? strtolower($body) : null;
    }

    private function get(string $path): ?string
    {
        try {
            $response = Http::timeout((int) config('esports.bitcoin.timeout_seconds', 5))
                ->get(rtrim((string) config('esports.bitcoin.api'), '/').$path);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? trim($response->body()) : null;
    }
}
