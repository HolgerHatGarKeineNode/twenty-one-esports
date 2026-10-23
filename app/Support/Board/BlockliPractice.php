<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Blockli practice against bots (DerCaddy, 2026-10-23: a small way to play against bots, where a newcomer gets the
 * game explained and small tips): the lessons, the bots, and the rules' answers for a game that the browser keeps
 * (pages/board/⚡practice, resources/js/blockli/practice.js). Nothing of it is stored and nothing counts. The page sends
 * the position it holds with every move, and this checks the move by BlockliRules as a live game's move is checked,
 * so a practice game follows the league's rules to the letter. The bots and the tips run in the browser
 * (resources/js/blockli, the engine of DerCaddy's prototype); the server only judges.
 *
 * @phpstan-import-type Position from BlockliRules
 *
 * @phpstan-type Lesson array{id: string, position: string, goal: 'win'|'block'|'jump', steps?: int, closing?: string, reply: 'hodler'|null, title: string, text: string, task: string, done: string, retry: string}
 */
final class BlockliPractice
{
    /** The most positions a page may send for the repetition draw: far beyond any game a person plays. */
    public const HISTORY_LIMIT = 1000;

    /** The longest text a position can take (two pawns, the blocks left, the side, twenty blocks with their setter, the quiet plies). */
    private const POSITION_LENGTH = 140;

    /** The bots, weakest first; their play is resources/js/blockli/bots.js. */
    public const BOTS = ['hodler', 'miner', 'whale'];

    /**
     * The lessons, in order. Each is a position (BlockliRules' text) with the player as White to move, what the player
     * has to do there (`goal`: `win` the game; set a `block` that makes Black's way at least `steps` longer; `jump` over
     * Black), who answers the player's moves (`reply`: the Hodler, who only runs, or nobody, so the lesson ends with the
     * one move), and its words. `closing` is the block the fence lesson asks for first, which the rules refuse because
     * it would leave Black without a way (tests/Feature/Board/BlockliPracticeTest.php holds every lesson to its goal).
     *
     * @var list<Lesson>
     */
    public const LESSONS = [
        [
            'id' => 'move', 'position' => 'e7 a9 10 10 w - 0', 'goal' => 'win', 'reply' => 'hodler',
            'title' => 'Move your pawn',
            'text' => 'You play White, from the bottom. The first pawn on the far side wins. On your move tap one of the marked squares: your pawn steps there, one square up, down, left or right. Then Black moves.',
            'task' => 'Bring your pawn to the top row.',
            'done' => 'That is all it takes to win. Every move is a step or a block, never both.',
            'retry' => 'Black got there first. Head straight for the top row.',
        ],
        [
            'id' => 'around', 'position' => 'e6 i9 10 7 w c6hb,e6hb,g6hb 0', 'goal' => 'win', 'reply' => 'hodler',
            'title' => 'Around the wall',
            'text' => 'Blocks lie in the grooves between the squares. No pawn passes through them or jumps them, so Black\'s three blocks above you make your way longer. They never close it: there is always a way around.',
            'task' => 'Find the shortest way around the wall to the top row.',
            'done' => 'Around the left end it is six steps, around the right one seven. Counting the way is half the game.',
            'retry' => 'Black was faster. The way around the left end is the short one.',
        ],
        [
            'id' => 'block', 'position' => 'e5 e2 10 10 w - 0', 'goal' => 'block', 'steps' => 1, 'reply' => null,
            'title' => 'Set a block',
            'text' => 'Black needs one more step to win. Stop him: tap the groove right under his pawn, and a block shows where it would lie. Tap it once more or press Confirm to set it; Rotate turns it. Each side has ten blocks.',
            'task' => 'Make Black\'s way longer with a block.',
            'done' => 'Now Black needs two steps instead of one. A block is your move, so it only pays when it costs the other side more than it costs you.',
            'retry' => 'That did not make his way longer. Set a block in the groove right under his pawn.',
        ],
        [
            'id' => 'jump', 'position' => 'e4 e5 10 10 w - 0', 'goal' => 'jump', 'reply' => null,
            'title' => 'Jump',
            'text' => 'Face to face with the other pawn you jump straight over it. If a block or the edge of the board stands right behind it, you step beside it instead.',
            'task' => 'Jump over Black.',
            'done' => 'One move, two squares. A jump also works the other way round: Black can jump over you.',
            'retry' => 'That was a single step. Tap the square right behind Black.',
        ],
        [
            'id' => 'fence', 'position' => 'e3 i9 9 10 w h8vw 0', 'goal' => 'block', 'steps' => 2, 'closing' => 'h7h', 'reply' => null,
            'title' => 'Never lock anyone in',
            'text' => 'Every pawn must always keep a way to its goal. Your block on the right already fences in the corner where Black stands. One more block under h8 and i8 would lock him in completely, so the board shows it red and you cannot set it.',
            'task' => 'Try the block under h8 and i8. Then set a block that makes Black\'s way at least two steps longer.',
            'done' => 'A block may make a way long, never impossible. That rule also protects you.',
            'retry' => 'That did not make his way two steps longer. Try a block across his way further down the i file.',
        ],
        [
            'id' => 'race', 'position' => 'b4 h5 9 10 w f3hw 0', 'goal' => 'win', 'reply' => 'hodler',
            'title' => 'Count the race',
            'text' => 'If you both only run, who arrives first? You need five steps, Black four, and Black moves right after you: he wins that race. A block that costs him two steps or more and you none turns it. This Black never sets a block.',
            'task' => 'Win the race.',
            'done' => 'Steps, blocks in hand and who moves next: that is the whole race. Now try it against the bots.',
            'retry' => 'Black was first. Count again: which block costs him at least two steps?',
        ],
    ];

    private BlockliRules $rules;

    public function __construct()
    {
        $this->rules = new BlockliRules;
    }

    public function rules(): BlockliRules
    {
        return $this->rules;
    }

    /**
     * What the board needs for a position the page starts from (a lesson, the start of a game): its pieces, the legal
     * moves with their paths, the race standing and how the game stands; an error for text that is no position.
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
     * last, as the page kept them: the repetition draw counts them as a live game's are counted.
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
            'standing' => $this->rules->standing($position),
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
