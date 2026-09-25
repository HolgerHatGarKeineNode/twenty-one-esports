<?php

namespace App\Support\Clans;

/**
 * PLACEHOLDER NUMBERS — replaced in P6 (series, Elo per lineup) and P7 (Elo
 * computation, Clan Rating, Hashrate, Block Height).
 *
 * Everything here is copied from the sample ledger (SAMPLE-LEDGER.md sections
 * 1.1, 2, 2.1, 3.3-3.5) and keyed by clan tag, so the seeded clans look real
 * in `composer dev`. A clan the ledger does not know gets the empty state
 * ("needs 3 blitz Elos", 0 points, provisional 1000), which is also exactly
 * what a new clan shows once the real numbers exist. Nothing in here is ever
 * signed or published.
 */
final class ClanStatsPreview
{
    /** @var array<string, list<int>> Clan Rating inputs: top blitz Elos per clan (ledger 2) */
    private const TOP_BLITZ = [
        'LSR' => [1151, 1089, 1034], 'HDL' => [1146, 1078, 1032], 'MMP' => [1119, 989, 970], 'OPS' => [1109, 1031, 1021],
        'B21' => [1004, 958, 931], 'STK' => [973, 944, 922], 'LNB' => [936, 914, 903], 'NCE' => [955, 948],
    ];

    /** @var array<string, array{0: int, 1: int, 2: int, 3: int}> Hashrate: season total, season bonus, 7-day total, 7-day bonus (ledger 2.1) */
    private const HASHRATE = [
        'LSR' => [562, 125, 64, 15], 'HDL' => [558, 115, 69, 15], 'MMP' => [452, 95, 25, 5], 'OPS' => [364, 45, 42, 5],
        'B21' => [252, 60, 8, 0], 'STK' => [184, 35, 12, 5], 'LNB' => [114, 15, 1, 0], 'NCE' => [56, 5, 0, 0],
    ];

    /** @var array<string, array<string, array{0: int, 1: int, 2: int}>> RL lineup Elo, series, rank in mode (ledger 3.3-3.5) */
    private const LINEUPS = [
        'LSR' => ['3v3' => [1182, 20, 1], '2v2' => [1034, 8, 3], '1v1' => [1000, 0, 0]],
        'HDL' => ['3v3' => [1105, 19, 2], '2v2' => [1072, 11, 1]],
        'MMP' => ['3v3' => [1047, 18, 3], '2v2' => [1055, 10, 2]],
        'OPS' => ['3v3' => [987, 16, 4], '2v2' => [1000, 0, 0], '1v1' => [1000, 0, 0]],
        'B21' => ['3v3' => [974, 17, 5], '2v2' => [963, 9, 5], '1v1' => [1000, 0, 0]],
        'STK' => ['3v3' => [908, 15, 7], '2v2' => [947, 8, 6], '1v1' => [1000, 0, 0]],
        'LNB' => ['3v3' => [854, 12, 8], '2v2' => [986, 5, 4], '1v1' => [1000, 0, 0]],
        'NCE' => ['3v3' => [949, 3, 6], '2v2' => [941, 5, 7]],
    ];

    /** @var array<string, int> rated lineups per mode (ledger 3.3-3.5) */
    private const LADDER_SIZE = ['3v3' => 8, '2v2' => 7, '1v1' => 0];

    /** @var array<string, array{0: int, 1: string, 2: int, 3: int}> per player: Block Height, blitz rank label, blitz Elo, Hashrate 7 days (ledger 1.1) */
    private const PLAYERS = [
        'satsjäger' => [97, 'platinum-3', 1151, 25], 'hodlqueen' => [63, 'gold-3', 1089, 15], 'nonce_nick' => [25, 'gold-1', 1034, 9],
        'rocketman21' => [56, 'gold-3', 1078, 17], 'halvinghans' => [27, 'silver-1', 962, 4], 'lena.k' => [63, 'platinum-2', 1146, 19], 'moonfee' => [49, 'gold-1', 1032, 14],
        'mempoolmax' => [64, 'platinum-1', 1119, 9], 'feebump' => [59, 'silver-1', 970, 9], 'rbf_rita' => [44, 'silver-2', 989, 2], 'blockbert' => [4, 'provisional-1', 0, 0],
        'pillpusher' => [47, 'gold-1', 1031, 10], 'kai_blitz' => [51, 'platinum-1', 1109, 11], 'orangina' => [34, 'silver-3', 1021, 10], 'satoshi_sue' => [26, 'silver-2', 978, 6],
        'blockzeit' => [41, 'silver-3', 1004, 3], 'dezentral_dani' => [39, 'silver-1', 958, 4], 'utxo_uwe' => [28, 'bronze-3', 931, 1],
        'stackstefan' => [37, 'silver-1', 973, 3], 'dca_doris' => [33, 'bronze-3', 944, 3], 'kaltlager' => [25, 'bronze-2', 922, 1],
        'channel_chris' => [28, 'bronze-3', 936, 1], 'invoice_ivo' => [22, 'bronze-2', 903, 0], 'zap_zoe' => [26, 'bronze-2', 914, 0],
        'asic_anna' => [17, 'silver-1', 955, 0], 'sha_sebi' => [15, 'bronze-3', 948, 0], 'hashing_hugo' => [3, 'provisional-1', 0, 0],
    ];

    /** @var array<string, int> personal Hashrate Season 1 (ledger 1.1, column HR) */
    private const PLAYER_SEASON_HASHRATE = [
        'satsjäger' => 226, 'mempoolmax' => 144, 'hodlqueen' => 147, 'lena.k' => 146, 'feebump' => 116, 'rocketman21' => 128,
        'kai_blitz' => 114, 'moonfee' => 111, 'pillpusher' => 91, 'rbf_rita' => 89, 'blockzeit' => 76, 'dezentral_dani' => 69,
        'stackstefan' => 61, 'orangina' => 66, 'dca_doris' => 50, 'channel_chris' => 39, 'utxo_uwe' => 47, 'halvinghans' => 58,
        'satoshi_sue' => 48, 'zap_zoe' => 33, 'kaltlager' => 38, 'nonce_nick' => 64, 'invoice_ivo' => 27, 'asic_anna' => 26,
        'sha_sebi' => 22, 'blockbert' => 8, 'hashing_hugo' => 3,
    ];

    /** Blocks mined since launch (ledger 4.1: 379 rated results). */
    public const BLOCKS_MINED = 379;

    /**
     * @return array{rating: int|null, top: list<int>}
     */
    public static function clanRating(string $tag): array
    {
        $top = self::TOP_BLITZ[$tag] ?? [];

        return ['rating' => count($top) >= 3 ? (int) round(array_sum($top) / 3) : null, 'top' => $top];
    }

    /**
     * @return array{season: int, seasonBonus: int, week: int, weekBonus: int}
     */
    public static function hashrate(string $tag): array
    {
        [$season, $seasonBonus, $week, $weekBonus] = self::HASHRATE[$tag] ?? [0, 0, 0, 0];

        return compact('season', 'seasonBonus', 'week', 'weekBonus');
    }

    /**
     * @return array{elo: int, series: int, rank: int, of: int, tier: string, level: int}
     */
    public static function lineup(string $tag, string $mode): array
    {
        [$elo, $series, $rank] = self::LINEUPS[$tag][$mode] ?? [1000, 0, 0];
        [$tier, $level] = $series < 5 ? ['provisional', 1] : self::tier($elo);

        return ['elo' => $elo, 'series' => $series, 'rank' => $rank, 'of' => self::LADDER_SIZE[$mode] ?? 0, 'tier' => $tier, 'level' => $level];
    }

    /**
     * @return array{blockHeight: int, tier: string, level: int, blitz: int, week: int, season: int}
     */
    public static function player(string $name): array
    {
        [$height, $rank, $blitz, $week] = self::PLAYERS[$name] ?? [0, 'provisional-1', 0, 0];
        [$tier, $level] = explode('-', $rank);

        return ['blockHeight' => $height, 'tier' => $tier, 'level' => (int) $level, 'blitz' => $blitz, 'week' => $week, 'season' => self::PLAYER_SEASON_HASHRATE[$name] ?? 0];
    }

    /**
     * Rank of the clan by Clan Rating among the clans that have one, and by Hashrate.
     *
     * @return array{rating: int|null, ratedClans: int, season: int|null, week: int|null, clans: int}
     */
    public static function ranks(string $tag): array
    {
        $ratings = array_filter(array_map(fn (array $top) => count($top) >= 3 ? array_sum($top) : null, self::TOP_BLITZ));
        arsort($ratings);
        $season = array_map(fn (array $row) => $row[0], self::HASHRATE);
        $week = array_map(fn (array $row) => $row[2], self::HASHRATE);
        arsort($season);
        arsort($week);
        $position = fn (array $list) => ($index = array_search($tag, array_keys($list), true)) === false ? null : $index + 1;

        return ['rating' => $position($ratings), 'ratedClans' => count($ratings), 'season' => $position($season), 'week' => $position($week), 'clans' => count(self::HASHRATE)];
    }

    /**
     * Series record, 3v3 Elo line and recent matches (ClanShow.dc.html). The
     * ledger details this for Laser Eyes only; other clans show the empty state.
     *
     * @return array{stats: list<array{0: string, 1: string, 2: string, 3: string}>, line: list<int>, matches: list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string}>}|null
     */
    public static function record(string $tag): ?array
    {
        if ($tag !== 'LSR') {
            return null;
        }

        return [
            'stats' => [['Series', '14', '31', '5'], ['Wins', '12', '24', '5'], ['Goals (team)', '99', '218', '36']],
            'line' => [1000, 1021, 1039, 1058, 1072, 1086, 1069, 1086, 1100, 1112, 1124, 1137, 1118, 1101, 1112, 1124, 1135, 1144, 1160, 1174, 1182],
            'matches' => [
                ['#404', 'MMP', 'Mempool Maniacs', '2½ : ½', 'chess team', 'win', '+9', '2 h ago'],
                ['#402', 'MMP', 'Mempool Maniacs', '3 : 1', '3v3 · BO5', 'wait', 'pending', 'today'],
                ['#370', 'MMP', 'Mempool Maniacs', '1 : 2', '2v2 · BO3', 'loss', '−17', '2 days ago'],
                ['#351', 'OPS', 'Orange Pill Squad', '3 : 2', '3v3 · BO5', 'win', '+8', '5 days ago'],
                ['#350', 'HDL', 'HODL Rockets', '3 : 2', '3v3 · BO5', 'win', '+14', '6 days ago'],
                ['#342', 'FEW', 'Few Understand, Genesis Cup', '2 : 0', '3v3 · BO3', 'win', 'no Elo', '7 days ago'],
                ['#332', 'HDL', 'HODL Rockets', '2 : 1', '3v3 · BO3', 'win', '+16', '9 days ago'],
                ['#316', 'B21', 'Block 21', '3 : 0', '3v3 · BO5', 'win', '+9', '12 days ago'],
            ],
        ];
    }

    /**
     * Season 1 thresholds (plan "Ränge wie bei Rocket League"): [tier, level].
     *
     * @return array{0: string, 1: int}
     */
    public static function tier(int $elo): array
    {
        $steps = [
            [1425, 'grand-champion', 3], [1375, 'grand-champion', 2], [1325, 'grand-champion', 1],
            [1300, 'champion', 3], [1275, 'champion', 2], [1250, 'champion', 1],
            [1225, 'diamond', 3], [1200, 'diamond', 2], [1175, 'diamond', 1],
            [1150, 'platinum', 3], [1125, 'platinum', 2], [1100, 'platinum', 1],
            [1075, 'gold', 3], [1050, 'gold', 2], [1025, 'gold', 1],
            [1000, 'silver', 3], [975, 'silver', 2], [950, 'silver', 1],
            [925, 'bronze', 3], [900, 'bronze', 2],
        ];

        foreach ($steps as [$minimum, $tier, $level]) {
            if ($elo >= $minimum) {
                return [$tier, $level];
            }
        }

        return ['bronze', 1];
    }
}
