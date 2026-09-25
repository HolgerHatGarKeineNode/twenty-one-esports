<?php

namespace App\Support;

/**
 * Static sample content for the P2b visual foundation, copied from the approved
 * screens (Main.dc.html, BlockStrip.dc.html). Real data sources replace this in
 * P7; until then every page that shows league content reads it from here.
 */
class SampleData
{
    /**
     * Finished blocks, oldest first; the last one is the newest block next to the divider.
     *
     * @return list<array{height: string, game: string, mode: string, score: string, word: bool, who: string, when: string, a: string, b: string, href: string, aria: string, level: string, casual: bool, state: string, dot: bool, newest: bool}>
     */
    public static function finishedBlocks(): array
    {
        return [
            self::block('#102', 'rl', __('RL 3v3'), '3 : 1', 'Laser Eyes', __(':n h ago', ['n' => 2]), 'LSR', __('vs :name', ['name' => 'MMP']), route('matches.show', 102), __('#102, Rocket League 3v3, finished 3 : 1, won by Laser Eyes, 2 hours ago')),
            self::block('#104', 'chess', __('Team'), '2½ : ½', 'Laser Eyes', __(':n h ago', ['n' => 1]), 'LSR', __('vs :name', ['name' => 'MMP']), route('matches.show', 104), __('#104, chess team match, finished 2½ : ½, won by Laser Eyes, 1 hour ago')),
            self::block('#107', 'chess', __('Blitz'), '1–0', 'lena.k', __(':n min ago', ['n' => 31]), 'lena.k', __('vs :name', ['name' => 'pillpusher']), route('games.index'), __('#107, chess blitz, finished 1–0, won by lena.k against pillpusher, 31 minutes ago')),
            self::block('#108', 'chess', __('Daily'), '½–½', __('Draw'), __(':n min ago', ['n' => 18]), 'rbf_rita', __('vs :name', ['name' => 'hashhodler']), route('games.index'), __('#108, casual chess daily game, finished ½–½, draw between rbf_rita and hashhodler, 18 minutes ago'), casual: true),
            self::block('#109', 'chess', __('Blitz'), '0–1', 'kai_blitz', __(':n min ago', ['n' => 6]), 'feebump', __('vs :name', ['name' => 'kai_blitz']), route('games.index'), __('#109, chess blitz, finished 0–1, won by kai_blitz against feebump, 6 minutes ago, newest block'), newest: true),
        ];
    }

    /**
     * Running and scheduled blocks, next one first.
     *
     * @return list<array{height: string, game: string, mode: string, score: string, word: bool, who: string, when: string, a: string, b: string, href: string, aria: string, level: string, casual: bool, state: string, dot: bool, newest: bool}>
     */
    public static function runningBlocks(): array
    {
        return [
            self::block('#105', 'chess', __('Team'), '1½ : ½', __('board 2 live'), __('live'), 'OPS', __('vs :name', ['name' => 'B21']), route('matches.show', 105), __('#105, chess team match, live, 1½ : ½, board 2 still playing, Orange Pill Squad vs Block 21'), state: 'live', level: '67%', dot: true),
            self::block('#110', 'chess', __('Daily'), __('move :n', ['n' => 14]), __(":name's turn", ['name' => 'lena.k']), __('running'), 'lena.k', __('vs :name', ['name' => 'nonce_nick']), route('games.index'), __("#110, chess daily game, running, move 14, lena.k's turn, lena.k vs nonce_nick"), state: 'live', level: '35%', word: true),
            self::block('#111', 'rl', __('RL 3v3'), 'BO3', 'Halving Cup', __('in ~:n min', ['n' => 40]), 'B21', __('vs :name', ['name' => 'STK']), route('matches.show', 111), __('#111, Rocket League 3v3 best of 3, Halving Cup, starts in about 40 minutes, Block 21 vs Stack Sats Crew'), state: 'next'),
        ];
    }

    /**
     * @return array{height: string, game: string, mode: string, score: string, word: bool, who: string, when: string, a: string, b: string, href: string, aria: string, level: string, casual: bool, state: string, dot: bool, newest: bool}
     */
    private static function block(
        string $height,
        string $game,
        string $mode,
        string $score,
        string $who,
        string $when,
        string $a,
        string $b,
        string $href,
        string $aria,
        string $state = 'fin',
        string $level = '100%',
        bool $casual = false,
        bool $dot = false,
        bool $word = false,
        bool $newest = false,
    ): array {
        return compact('height', 'game', 'mode', 'score', 'word', 'who', 'when', 'a', 'b', 'href', 'aria', 'level', 'casual', 'state', 'dot', 'newest');
    }

    /**
     * @return list<array{value: string, label: string, short: string, dot: bool}>
     */
    public static function counters(): array
    {
        return [
            ['value' => '23', 'label' => __('players online'), 'short' => __('online'), 'dot' => true],
            ['value' => '41', 'label' => __('games today'), 'short' => __('games today'), 'dot' => false],
            ['value' => '5', 'label' => __('games live now'), 'short' => __('live now'), 'dot' => false],
        ];
    }

    /**
     * @return array{strongest: list<array{rank: int, name: string, initial: string, member: bool, avatar: string, sub: string, value: string}>, active: list<array{rank: int, name: string, initial: string, member: bool, avatar: string, sub: string, value: string}>}
     */
    public static function rankings(): array
    {
        $avatars = [
            'linear-gradient(135deg, #F9B25F, #B9640A)',
            'linear-gradient(135deg, #CBD5E1, #6B6B70)',
            'linear-gradient(135deg, #7DD3FC, #3B6E8C)',
            'linear-gradient(135deg, #E5A06B, #8A4F24)',
        ];

        $rows = fn (array $rows): array => array_map(
            fn (array $row, int $index): array => [
                'rank' => $index + 1,
                'name' => $row[0],
                'initial' => mb_strtoupper(mb_substr($row[0], 0, 1)),
                'member' => $row[1],
                'avatar' => $avatars[$row[2]],
                'sub' => $row[3],
                'value' => $row[4],
            ],
            $rows,
            array_keys($rows),
        );

        $chessRl = __('chess + RL');
        $chess = __('chess');

        return [
            'strongest' => $rows([
                ['satsjäger', true, 0, $chessRl, '1214'],
                ['mempoolmax', true, 1, $chessRl, '1188'],
                ['lena.k', false, 2, $chess, '1161'],
                ['hodlqueen', true, 3, $chessRl, '1149'],
                ['kai_blitz', false, 1, $chess, '1127'],
                ['nonce_nick', true, 0, $chessRl, '1096'],
                ['pillpusher', true, 2, __('RL + chess'), '1071'],
                ['rbf_rita', false, 3, $chessRl, '1043'],
            ]),
            'active' => $rows([
                ['nonce_nick', true, 0, '+12', '187'],
                ['satsjäger', true, 0, '+9', '164'],
                ['lena.k', false, 2, '+21', '141'],
                ['hodlqueen', true, 3, '+6', '118'],
                ['feebump', false, 1, '+4', '96'],
                ['kai_blitz', false, 1, '+17', '71'],
                ['mempoolmax', true, 1, '+3', '64'],
                ['hashhodler', false, 2, __('+:n new', ['n' => 9]), '9'],
            ]),
        ];
    }

    /**
     * Clan Hashrate this week: win 3, draw 2, loss 1, won team match +5.
     *
     * @return array{rows: list<array{rank: int, tag: string, name: string, points: int, width: string, tip: string}>, total: int}
     */
    public static function hashrate(): array
    {
        $clans = [
            ['LSR', 'Laser Eyes', [8, 2, 4, 2]],
            ['HDL', 'HODL Rockets', [4, 1, 3, 0]],
            ['MMP', 'Mempool Maniacs', [4, 3, 5, 1]],
            ['NCE', 'Nonce Hunters', [9, 3, 7, 1]],
            ['OPS', 'Orange Pill Squad', [5, 0, 4, 1]],
            ['B21', 'Block 21', [3, 1, 3, 0]],
            ['STK', 'Stack Sats Crew', [1, 2, 4, 0]],
            ['LNB', 'Lightning Boost', [0, 0, 4, 0]],
        ];

        $scored = array_map(fn (array $clan): array => [
            'tag' => $clan[0],
            'name' => $clan[1],
            'points' => 3 * $clan[2][0] + 2 * $clan[2][1] + $clan[2][2] + 5 * $clan[2][3],
            'games' => $clan[2][0] + $clan[2][1] + $clan[2][2],
        ], $clans);

        usort($scored, fn (array $a, array $b): int => $b['points'] <=> $a['points']);

        $top = $scored[0]['points'];
        $rows = [];

        foreach (array_slice($scored, 0, 5) as $index => $clan) {
            $rows[] = [
                'rank' => $index + 1,
                'tag' => $clan['tag'],
                'name' => $clan['name'],
                'points' => $clan['points'],
                'width' => number_format($clan['points'] / $top * 100, 1).'%',
                'tip' => __(':name: :points points from :games games', ['name' => $clan['name'], 'points' => $clan['points'], 'games' => $clan['games']]),
            ];
        }

        return ['rows' => $rows, 'total' => array_sum(array_column($scored, 'points'))];
    }

    /**
     * Footer figures; [N] marks facts the design leaves open until real data exists.
     *
     * @return array{players: string, clans: string, games: string}
     */
    public static function footerStats(): array
    {
        return ['players' => '[N]', 'clans' => '8', 'games' => '[N]'];
    }
}
