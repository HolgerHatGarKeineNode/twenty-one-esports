<?php

namespace App\Support\Tournaments;

/**
 * NIP "Tournament Draw", algorithm `sha256-v1`: every entrant's digest is
 * SHA-256 of "<block hash>:<pubkey>" (lower-case hex both), the draw order is
 * the entrants sorted by that digest, and team `n` (from 1) is entrants
 * `(n-1)*s+1` to `n*s`; whoever is left after the last full team is a
 * reserve, in draw order. Anyone can re-run it with a block explorer.
 */
final class DrawOrder
{
    /**
     * @param  list<string>  $pubkeys
     * @return list<string> in draw order
     */
    public static function order(string $blockHash, array $pubkeys): array
    {
        $hash = strtolower($blockHash);
        $digests = [];

        foreach ($pubkeys as $pubkey) {
            $digests[strtolower($pubkey)] = hash('sha256', $hash.':'.strtolower($pubkey));
        }

        asort($digests, SORT_STRING);

        return array_map(strval(...), array_keys($digests));
    }

    /**
     * @param  list<string>  $pubkeys
     * @return array{teams: list<list<string>>, reserves: list<string>}
     */
    public static function teams(string $blockHash, array $pubkeys, int $size): array
    {
        $order = self::order($blockHash, $pubkeys);
        $count = intdiv(count($order), max(1, $size));

        return [
            'teams' => array_map(fn (int $team): array => array_slice($order, $team * $size, $size), $count > 0 ? range(0, $count - 1) : []),
            'reserves' => array_slice($order, $count * $size),
        ];
    }
}
