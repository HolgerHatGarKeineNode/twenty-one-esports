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
}
