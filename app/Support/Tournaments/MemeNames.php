<?php

namespace App\Support\Tournaments;

/**
 * Names of the mix teams (plan, "Aktuell gültig": fun Bitcoin and
 * EINUNDZWANZIG meme names). The list is fixed; a tournament starts at a
 * place derived from its slug, so names differ between tournaments but are
 * known before the draw: they go into the `2155` as `teamname` tags, and
 * team `n` of the draw gets the `n`-th name. Names are proper names and stay
 * English in every language.
 */
final class MemeNames
{
    public const NAMES = [
        'Stay Humble Stack Sats',
        'Tick Tock Next Block',
        'Not Your Keys',
        'Laser Eyes Lions',
        'HODL Hurricanes',
        'Orange Pill Squad',
        'Fix The Money',
        'Number Go Up',
        'Few Understand',
        'Running Bitcoin',
        'Mempool Maniacs',
        'Proof of Play',
        'Satoshi Stackers',
        'Cold Storage Crew',
        'Block Height Heroes',
        'Low Time Preference',
        'Difficulty Adjusters',
        'Halving Heroes',
        'Lightning Strikes',
        'Twenty One Million',
        'Einundzwanzig Elf',
        'Genesis Blockers',
        'Hash Rate Rockets',
        'Sound Money Squad',
    ];

    /**
     * @return list<string> `$count` names for this tournament, in draw order
     */
    public static function for(string $slug, int $count): array
    {
        $total = count(self::NAMES);
        $start = (int) (hexdec(substr(hash('sha256', $slug), 0, 8)) % $total);
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $name = self::NAMES[($start + $i) % $total];
            $names[] = $i < $total ? $name : $name.' '.(intdiv($i, $total) + 1);
        }

        return $names;
    }
}
