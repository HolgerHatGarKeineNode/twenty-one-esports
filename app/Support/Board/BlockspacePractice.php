<?php

namespace App\Support\Board;

/**
 * The Blockspace tutorial and its bots (pages/board/⚡blockspace-practice, resources/js/blockspace/practice.js): nine
 * short lessons on the league's own board, each a position with one thing to do, so the rules are learned by playing
 * them. For guests too; nothing of it is stored and nothing counts. The game stays in the browser, on the engine that
 * tests/Unit/Board/BlockspaceRulesTest.php holds to the server's rules, and tests/Feature/BlockspacePracticeTest.php
 * holds every lesson to its goal.
 *
 * @phpstan-type Lesson array{id: string, stones?: array{w?: string, b?: string}, pool?: string, queue?: string, ti?: int, score?: array{w: int, b: int}, played?: string, fuse?: bool, position?: string, goal: 'place'|'touch'|'fee'|'slot'|'pass'|'win', count?: int, slot?: int, title: string, text: string, task: string, done: string, retry: string}
 */
final class BlockspacePractice
{
    /** The last lesson: 3 sats behind with six transactions left, from a game of the bots. */
    private const ENDGAME = 'n 0 85 88 11 0 rofie p dmqnmbeanrkqlitgsgbjulctkhhpcufjsorofaidep .b.www.w..bbwbbbw..b.bwwb.w..bb.wbww..bb.wwbw.bwwb.bbw.b.wbb.wb.bw..bbwb.bwb...wb.bwb..wwbbwbbww..bbw.bwwwb.bwwwb..w.bb.bb.ww....b.bw...wwwwbw..w..bbwbbbwwbbwwbw.wbwbwbwww.ww.wb.w...ww.b...';

    /**
     * The lessons, in order. A lesson is a position with the player as Orange (the rules' White) to move, built from `stones`, `pool` and
     * `queue` (forms as in BlockspaceRules, a = I1 … u = Z5) or given whole, and what the player has to do there:
     * `place` `count` transactions, `touch` a Blue stone along an edge, take the highest `fee`, the transaction on
     * rung `slot`, `pass`, or `win` the game against the Hodler.
     *
     * @var list<Lesson>
     */
    public const LESSONS = [
        [
            'id' => 'first', 'pool' => 'cdfgj', 'queue' => 'bebh', 'played' => '00', 'goal' => 'place',
            'title' => 'Your first stones',
            'text' => 'You are Orange, your opponent Blue. Whoever collects the higher yield wins. Yield counts in sat, the points of this game. Beside the block is the mempool: five transactions waiting. Take one and set it so that it covers your start cell, the orange diamond.',
            'task' => 'Cover your start cell.',
            'done' => 'Your first transaction is in the block and now counts toward your yield.',
            'retry' => 'Set it on the orange diamond.',
        ],
        [
            'id' => 'ladder', 'pool' => 'bgeck', 'queue' => 'hbeb', 'played' => '00', 'goal' => 'fee',
            'title' => 'The mempool ladder',
            'text' => 'The oldest transaction stands on top. Each brings its size in vB plus a bonus for its age: +4 for the oldest, then +3, +2, +1, nothing for the newest. So a big stone does not always bring the most. Each block shows what it brings, in its number and its colour.',
            'task' => 'Take the transaction that brings the most and cover your start cell.',
            'done' => 'What you take leaves a gap: everything behind it moves up a rung and brings more, for Blue as well.',
            'retry' => 'That was not the highest number. Look at the sat on each block.',
        ],
        [
            'id' => 'corner', 'stones' => ['w' => 'e2,e3,f3'], 'pool' => 'abcdg', 'queue' => 'bebh', 'played' => '10', 'goal' => 'place',
            'title' => 'Corners only',
            'text' => 'From now on your new stones must touch your own stones at a corner, and never along an edge. Try a place right next to your stones, then one that only meets them corner to corner.',
            'task' => 'Set a transaction that touches your stones at a corner.',
            'done' => 'That is the whole rule: corners yes, edges no. Every corner you open is a way forward.',
            'retry' => 'Look for a free cell diagonal to your stones.',
        ],
        [
            'id' => 'turn', 'stones' => ['w' => 'a1', 'b' => 'b3,b4,d3'], 'pool' => 'hhhhh', 'queue' => 'bebh', 'played' => '11', 'goal' => 'place',
            'title' => 'Turn and mirror',
            'text' => 'A transaction can lie in any direction. With a mouse a right click turns it and a long right click mirrors it, on a phone the buttons do. As it comes off the ladder this one fits your only corner nowhere, not even turned.',
            'task' => 'Mirror the transaction, then set it on your corner.',
            'done' => 'Mirrored, it fits. Before you take a transaction, look at all the ways it can lie.',
            'retry' => 'Turning alone does not help here: mirror it.',
        ],
        [
            'id' => 'other', 'stones' => ['w' => 'e5', 'b' => 'g6,g7,g8'], 'pool' => 'bbbbb', 'queue' => 'bebh', 'played' => '11', 'goal' => 'touch',
            'title' => 'The other miner',
            'text' => 'The rules only care about your own stones. Next to Blue\'s stones you may lie along the edge as you like, and that takes the room Blue wanted to grow into.',
            'task' => 'From your corner, set a transaction right next to a Blue stone.',
            'done' => 'Every cell you take is one Blue cannot use. Blocking is worth as much as filling your own side.',
            'retry' => 'That did not touch Blue. Stand it upright on the corner at f6.',
        ],
        [
            'id' => 'order', 'stones' => ['w' => 'e5', 'b' => 'e17'], 'pool' => 'ccdbb', 'queue' => 'gbhe', 'played' => '11', 'ti' => 3, 'goal' => 'place', 'count' => 2,
            'title' => 'Two in a row',
            'text' => 'The sides move Orange, Blue, Blue, Orange. So after the start everyone moves twice in a row, and what you leave on the ladder after your second move is what Blue gets first.',
            'task' => 'Set two transactions in a row.',
            'done' => 'The four small diamonds at the top always show where you are in that order.',
            'retry' => 'Set your second one as well.',
        ],
        [
            'id' => 'pass', 'stones' => ['w' => 'a1', 'b' => 'c2,b3,e16'], 'pool' => 'kaabb', 'queue' => 'gbhe', 'played' => '11', 'goal' => 'pass',
            'title' => 'Passing',
            'text' => 'Instead of setting a stone you may pass: the oldest transaction falls out, a new one comes in and the turn goes on. A side with nothing to set is passed over by the game. Here you could set the single stone, but then Blue gets the long transaction on top for 9 sat.',
            'task' => 'Pass, press again to confirm, and the long one is gone.',
            'done' => 'A pass costs you a turn, but it can take a rich transaction away from the other side.',
            'retry' => 'Blue can still take the long one now. Pass instead.',
        ],
        [
            'id' => 'fuse', 'stones' => ['w' => 'e5'], 'pool' => 'kccbb', 'queue' => 'gbhe', 'played' => '10', 'ti' => 3, 'fuse' => true, 'goal' => 'slot', 'slot' => 0,
            'title' => 'Blitz: the fuse',
            'text' => 'In blitz games a fuse burns: at every second turn the oldest transaction falls out by itself. The marked one will be gone after this turn, and it brings 9 sat.',
            'task' => 'Take the oldest before it burns.',
            'done' => 'In blitz nothing waits for long. After this one the next oldest burns, so look at the top of the ladder on every turn.',
            'retry' => 'That one was not the oldest. It has burned now.',
        ],
        [
            'id' => 'endgame', 'position' => self::ENDGAME, 'goal' => 'win',
            'title' => 'Close the block',
            'text' => 'The end of a real game. The game is over when the ladder is empty or after 14 passes in a row, and the higher yield wins. You are 3 behind and six transactions are left.',
            'task' => 'Win the game against the Hodler.',
            'done' => 'That is Blockspace. Now try the bots, or find a real opponent in the lobby.',
            'retry' => 'The Hodler stayed ahead. Count the yield and start again.',
        ],
    ];

    /**
     * The position of a lesson, as BlockspaceRules writes it.
     *
     * @param  Lesson  $lesson
     */
    public static function position(array $lesson): string
    {
        if (isset($lesson['position'])) {
            return $lesson['position'];
        }

        $rules = new BlockspaceRules;
        $position = $rules->start();
        $position['pool'] = $lesson['pool'] ?? '';
        $position['queue'] = $lesson['queue'] ?? '';
        $position['ti'] = $lesson['ti'] ?? 0;
        $position['fuse'] = $lesson['fuse'] ?? false;
        $position['score'] = $lesson['score'] ?? ['w' => 0, 'b' => 0];
        $played = $lesson['played'] ?? '00';
        $position['played'] = ['w' => $played[0] === '1', 'b' => $played[1] === '1'];

        foreach ($lesson['stones'] ?? [] as $side => $cells) {
            foreach (explode(',', $cells) as $name) {
                $position['board'][(BlockspaceRules::ROWS - (int) substr($name, 1)) * BlockspaceRules::COLUMNS + (int) strpos('abcdefghi', $name[0])] = $side;
            }
        }

        return $rules->serialize($position);
    }

    /**
     * The words of the board's own input, on a game's page and here.
     *
     * @return array<string, mixed>
     */
    public static function boardWords(): array
    {
        return [
            'rotate' => __('Rotate'),
            'mirror' => __('Mirror'),
            'place' => __('Set it'),
            'you' => __('you'),
            'pass' => __('Pass'),
            'passSure' => __('Really pass?'),
            'queue' => __(':count to come'),
            'mouse' => __('Left click takes and sets, right click turns, a long right click mirrors. Keys: 1 to 5 take, R turns, F mirrors, the arrows move, Enter sets.'),
            'passed' => __('The last turn was passed: the oldest transaction fell out.'),
            'chat' => __('Chat'),
            'unfoldLadder' => __('Open the mempool'),
            'foldLadder' => __('Close the mempool'),
            'portrait' => __('Blockspace plays best with the phone upright.'),
            'at' => __('Shown on :cells.'),
            'burning' => __('burns after this turn'),
            'touch' => __('Pick a transaction, then tap or drag on the block. One more tap on the stones sets them.'),
            'tile' => __('Rung :rung, form :form: :size vB plus :bonus, brings :fee sat'),
            'why' => [
                'taken' => __('A stone already lies there.'),
                'edge' => __('Your stones may not touch along an edge.'),
                'corner' => __('The stones need a corner of your own stones.'),
                'start' => __('Your first stones must cover your start cell.'),
            ],
        ];
    }
}
