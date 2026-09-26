<?php

namespace App\Support\Engagement;

/**
 * Meetup against meetup (P10): the clan hashrate summed per city. A clan's
 * city is the city of the portal meetup it is linked to (`meetup_city`);
 * clans without one are not in the ranking.
 *
 * Cities are grouped case- and space-insensitively ("  münchen" and
 * "München" are one city, shown as first written); ties are broken by
 * the city name, so the order is stable.
 */
final class CityRanking
{
    /**
     * @param  iterable<array{city: string|null, hashrate: int}>  $clans
     * @return list<array{city: string, hashrate: int, clans: int}>
     */
    public static function rank(iterable $clans): array
    {
        $cities = [];

        foreach ($clans as $clan) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $clan['city']) ?? '');

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);
            $cities[$key] ??= ['city' => $name, 'hashrate' => 0, 'clans' => 0];
            $cities[$key]['hashrate'] += max(0, $clan['hashrate']);
            $cities[$key]['clans']++;
        }

        $ranked = array_values($cities);
        usort($ranked, fn (array $a, array $b): int => [$b['hashrate'], mb_strtolower($a['city'])] <=> [$a['hashrate'], mb_strtolower($b['city'])]);

        return $ranked;
    }
}
