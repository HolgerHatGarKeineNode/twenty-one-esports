<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Go practice against bots (DerCaddy, 2026-10-27: Go "nach dem Vorbild von Blockli", with an interactive tutorial):
 * the lessons, the bots, and the rules' answers for a game the browser keeps (pages/board/⚡go-practice,
 * resources/js/go/practice.js), on 9 x 9. Nothing of it is stored and nothing counts. The page sends the position it
 * holds with every move, and this checks the move by GoRules as a live game's move is checked, so a practice game
 * follows the league's rules to the letter. The bots and the tips run in the browser (resources/js/go); the server
 * only judges. The twin of BlockliPractice.
 *
 * @phpstan-import-type Position from GoRules
 *
 * @phpstan-type Lesson array{id: string, position: string, goal: 'capture'|'escape'|'point'|'win', min?: int, point?: string, points?: list<string>, closing?: string, reply: 'pass'|'runner'|null, count?: bool, pass?: bool, title: string, text: string, task: string, done: string, retry: string, closingNote?: string}
 */
final class GoPractice
{
    /** The board the practice plays on. */
    public const SIZE = 9;

    /** The most positions a page may send for the repetition draw and the move limit: beyond the 324 plies a game can last. */
    public const HISTORY_LIMIT = 400;

    /** The longest text a 9 x 9 position can take (81 points, 8 slashes, the colour, the ko point, the passes). */
    private const POSITION_LENGTH = 100;

    /** The bots, weakest first; their play is resources/js/go/bots.js. */
    public const BOTS = ['hodler', 'miner', 'whale'];

    /**
     * The lessons, in order. Each is a position (GoRules' text) with the player as Black to move, what the player has
     * to do there (`goal`: `capture` at least `min` stones; `escape`, so the stone on `point` keeps at least `min`
     * liberties; play one of the `points`; `win` the game), who answers the player's moves (`reply`: White passes, or
     * White's stone in atari runs; nobody, so the lesson ends with the one move), and its words. `closing` is a point
     * the lesson asks the player to try first, which the rules refuse (the board shows it red, `closingNote` says why).
     * `count` shows the count beside the board, `pass` lets the player pass (only where the lesson is about the end:
     * elsewhere two passes would end the lesson's game). tests/Feature/GoPracticeTest.php holds every lesson to its goal.
     *
     * @var list<Lesson>
     */
    public const LESSONS = [
        [
            'id' => 'capture', 'position' => '9/9/9/4x4/3xox3/9/9/9/9 x - 0', 'goal' => 'capture', 'min' => 1, 'reply' => null,
            'title' => 'Liberties and capturing',
            'text' => 'You play Black. The empty points right next to a stone, along the lines, are its liberties. The white stone has only one left. Put your stone on its last liberty and it is captured: it comes off the board.',
            'task' => 'Capture the white stone.',
            'done' => 'Captured! A stone without a liberty comes off at once. A stone with one liberty left is in atari.',
            'retry' => 'The white stone still lives. Put your stone on the one empty point next to it.',
        ],
        [
            'id' => 'group', 'position' => '9/9/9/3xxx3/2xooox2/3xx4/9/9/9 x - 0', 'goal' => 'capture', 'min' => 3, 'reply' => null,
            'title' => 'Groups',
            'text' => 'Stones of one colour joined along the lines form a group, and share their liberties: the group lives as long as it has one. Stones that only touch at a corner are not joined.',
            'task' => 'Capture the whole white group.',
            'done' => 'Three stones in one move. The bigger the group, the more it costs when it falls.',
            'retry' => 'The group still has a liberty. Find the empty point that touches it.',
        ],
        [
            'id' => 'escape', 'position' => '9/9/9/4o4/3oxo3/9/9/9/9 x - 0', 'goal' => 'escape', 'point' => 'e5', 'min' => 2, 'reply' => null,
            'title' => 'Out of atari',
            'text' => 'Now your stone is in atari: one liberty left. Play on that liberty yourself, and your stones together have more of them.',
            'task' => 'Save your stone: give it more than one liberty.',
            'done' => 'Saved: the two stones have three liberties now. A group in atari is saved by running like this, or by capturing a stone next to it.',
            'retry' => 'Your stone still has only one liberty, or it is gone. Play right on its last liberty.',
        ],
        [
            'id' => 'forbidden', 'position' => '7o1/8o/9/9/9/9/x8/ox7/1ox6 x - 0', 'goal' => 'capture', 'min' => 2, 'closing' => 'j9', 'reply' => null,
            'title' => 'Forbidden points',
            'text' => 'A stone may not go where it would have no liberty and capture nothing: that would be suicide. Unless it captures: then it gets the liberties of the stones it took.',
            'task' => 'Try J9 in the upper right corner: it stays red. Then capture the two white stones in the lower left.',
            'done' => 'Right: on A1 your stone had no liberty of its own, but it captured two stones and stands safe now.',
            'retry' => 'Look at the lower left corner: the two white stones share one last liberty.',
            'closingNote' => 'Red: on J9 your stone would have no liberty and capture nothing, so the rules forbid it.',
        ],
        [
            'id' => 'ko', 'position' => '9/9/9/3ox4/2o1ox3/3ox4/9/9/9 x d5 0', 'goal' => 'capture', 'min' => 1, 'closing' => 'd5', 'reply' => 'pass',
            'title' => 'Ko',
            'text' => 'White has just taken one of your stones on D5. Taking back at once would bring back the same position, and so on for ever: that is a ko, and the rules make you wait one move.',
            'task' => 'Try to take back on D5: it stays red. Play somewhere else, then take back.',
            'done' => 'That is how ko works: after a move elsewhere you may take back. In a real game White uses that move to close the ko, so you play a threat there that White must answer.',
            'retry' => 'Not yet: play somewhere else first, then take the white stone on E5.',
            'closingNote' => 'Red: that would be taking back the ko at once.',
        ],
        [
            'id' => 'eyes', 'position' => '9/9/9/9/9/9/oooooo3/xxxxxo3/x3xo3 x - 0', 'goal' => 'point', 'points' => ['c1'], 'reply' => null,
            'title' => 'Two eyes',
            'text' => 'An empty point surrounded only by your stones is an eye. White can never play into an eye, unless it is your group\'s last liberty. A group with two separate eyes can never be captured: White would have to fill both at once.',
            'task' => 'Make two separate eyes out of your three empty points.',
            'done' => 'Two eyes: the group lives for ever. Had White played C1 first, only one eye would have been possible, and the group would be dead.',
            'retry' => 'With that move only one eye is left, two points big, and White can fill it. Which point splits your space in two?',
        ],
        [
            'id' => 'ladder', 'position' => '9/9/9/9/9/2x6/1xo6/3x5/9 x - 0', 'goal' => 'capture', 'min' => 2, 'reply' => 'runner',
            'title' => 'The ladder',
            'text' => 'A stone in atari runs. If every move it makes leaves it only two liberties, you put it in atari again and again, in a staircase, until it hits the edge. That is a ladder.',
            'task' => 'Catch the white stone with a ladder. White runs every time it stands in atari.',
            'done' => 'Caught! A ladder works when nothing stands in its way. A white stone on its path would have let White escape.',
            'retry' => 'White has three liberties now and got away. Put it in atari so that running gives it only two. Start again.',
        ],
        [
            'id' => 'count', 'position' => 'ox2xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3/4xo3 x - 1', 'goal' => 'win', 'reply' => 'pass', 'count' => true, 'pass' => true,
            'title' => 'The end and the count',
            'text' => 'The game ends when both pass in a row. Then it is counted: your stones and the empty points that touch only your stones. White gets 7.5 points of komi, as Black moved first. Nothing is marked dead here: what stands on the board counts. White has just passed.',
            'task' => 'Tidy up your area, then pass so the game is counted.',
            'done' => 'Won by 1.5 points. Remember: capture first, then pass. A stone in your own area costs you nothing.',
            'retry' => 'The white stone on A9 still stood: it counted for White, and your whole area beside it for nobody. Start again.',
        ],
    ];

    private GoRules $rules;

    public function __construct()
    {
        $this->rules = new GoRules(self::SIZE);
    }

    public function rules(): GoRules
    {
        return $this->rules;
    }

    /**
     * What the board needs for a position the page starts from (a lesson, the start of a game): its pieces, the legal
     * moves with their paths, the count and how the game stands; an error for text that is no position.
     *
     * @return array<string, mixed>
     */
    public function open(mixed $position): array
    {
        $parsed = $this->position($position);

        return $parsed === null ? ['ok' => false, 'error' => 'no_position'] : ['ok' => true, ...$this->describe($parsed, [$this->rules->serialize($parsed)])];
    }

    /**
     * A move in a position the page holds: the next position and what the board needs for it, or an error (`illegal_move`,
     * `game_over`, `no_position`). `history` is every position of the game before this move, the one it is made in
     * last, as the page kept them: the repetition draw and the move limit count them as a live game's are counted.
     *
     * @return array<string, mixed>
     */
    public function play(mixed $position, mixed $move, mixed $history = []): array
    {
        $parsed = $this->position($position);
        $earlier = $this->history($history);

        if ($parsed === null || $earlier === null) {
            return ['ok' => false, 'error' => 'no_position'];
        }

        $serialized = $this->rules->serialize($parsed);
        $earlier = $earlier === [] || end($earlier) !== $serialized ? [...$earlier, $serialized] : $earlier;

        if ($this->rules->outcome($parsed, $earlier) !== null) {
            return ['ok' => false, 'error' => 'game_over'];
        }

        if (! is_string($move) || ! in_array($move, $this->rules->legalMoves($parsed), true)) {
            return ['ok' => false, 'error' => 'illegal_move'];
        }

        $next = $this->rules->apply($parsed, $move);

        return [
            'ok' => true,
            'move' => ['move' => $move, 'notation' => $this->rules->notation($parsed, $move), 'path' => $this->rules->path($move)],
            ...$this->describe($next, [...$earlier, $this->rules->serialize($next)]),
        ];
    }

    /**
     * @param  Position  $position
     * @param  list<string>  $history
     * @return array<string, mixed>
     */
    private function describe(array $position, array $history): array
    {
        $outcome = $this->rules->outcome($position, $history);

        return [
            'position' => $this->rules->serialize($position),
            'turn' => $this->rules->turn($position),
            'pieces' => $this->rules->view($position)['pieces'],
            'legal' => $outcome === null ? array_map(fn (string $move): array => ['move' => $move, 'path' => $this->rules->path($move)], $this->rules->legalMoves($position)) : [],
            'count' => $this->rules->areaCount($position),
            'outcome' => $outcome,
        ];
    }

    /** @return Position|null */
    private function position(mixed $text): ?array
    {
        if (! is_string($text) || strlen($text) > self::POSITION_LENGTH) {
            return null;
        }

        try {
            return $this->rules->deserialize($text);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<string>|null */
    private function history(mixed $history): ?array
    {
        if (! is_array($history) || count($history) > self::HISTORY_LIMIT) {
            return null;
        }

        $positions = [];

        foreach ($history as $entry) {
            if (! is_string($entry) || strlen($entry) > self::POSITION_LENGTH) {
                return null;
            }

            $positions[] = $entry;
        }

        return $positions;
    }
}
