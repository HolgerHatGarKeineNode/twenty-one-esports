<?php

namespace App\Support\Board;

use InvalidArgumentException;

/**
 * Practice against bots on a board game (BlockliPractice, GoPractice): the rules' answers for a game the browser keeps
 * (resources/js/boardPractice.js). Nothing of it is stored and nothing counts. The page sends the position it holds
 * with every move, and this checks the move by the game's rules as a live game's move is checked, so a practice game
 * follows the league's rules to the letter. A game brings its rules, its lessons and bots, and how it stands
 * (standing()).
 *
 * @template TPosition
 */
abstract class BoardPractice
{
    /**
     * @param  int  $positionLength  the longest text a position can take
     * @param  int  $historyLimit  the most positions a page may send for the repetition draw
     */
    public function __construct(private readonly int $positionLength, private readonly int $historyLimit) {}

    /** @return BoardRules<TPosition> */
    abstract public function rules(): BoardRules;

    /**
     * How the game stands in a position, under the key the board reads it by (Blockli's race `standing`, Go's `count`).
     *
     * @param  TPosition  $position
     * @return array<string, mixed>
     */
    abstract protected function standing(mixed $position): array;

    /**
     * What the board needs for a position the page starts from (a lesson, the start of a game): its pieces, the legal
     * moves with their paths, how the game stands and its outcome; an error for text that is no position.
     *
     * @return array<string, mixed>
     */
    public function open(mixed $position): array
    {
        $parsed = $this->position($position);

        return $parsed === null ? ['ok' => false, 'error' => 'no_position'] : ['ok' => true, ...$this->describe($parsed, [$this->rules()->serialize($parsed)])];
    }

    /**
     * A move in a position the page holds: the next position and what the board needs for it, or an error (`illegal_move`,
     * `game_over`, `no_position`). `history` is every position of the game before this move, the one it is made in
     * last, as the page kept them: the repetition draw (and a move limit) count them as a live game's are counted.
     *
     * @return array<string, mixed>
     */
    public function play(mixed $position, mixed $move, mixed $history = []): array
    {
        $rules = $this->rules();
        $parsed = $this->position($position);
        $earlier = $this->history($history);

        if ($parsed === null || $earlier === null) {
            return ['ok' => false, 'error' => 'no_position'];
        }

        $serialized = $rules->serialize($parsed);
        $earlier = $earlier === [] || end($earlier) !== $serialized ? [...$earlier, $serialized] : $earlier;

        if ($rules->outcome($parsed, $earlier) !== null) {
            return ['ok' => false, 'error' => 'game_over'];
        }

        if (! is_string($move) || ! in_array($move, $rules->legalMoves($parsed), true)) {
            return ['ok' => false, 'error' => 'illegal_move'];
        }

        $next = $rules->apply($parsed, $move);

        return [
            'ok' => true,
            'move' => ['move' => $move, 'notation' => $rules->notation($parsed, $move), 'path' => $rules->path($move)],
            ...$this->describe($next, [...$earlier, $rules->serialize($next)]),
        ];
    }

    /**
     * @param  TPosition  $position
     * @param  list<string>  $history
     * @return array<string, mixed>
     */
    private function describe(mixed $position, array $history): array
    {
        $rules = $this->rules();
        $outcome = $rules->outcome($position, $history);

        return [
            'position' => $rules->serialize($position),
            'turn' => $rules->turn($position),
            'pieces' => $rules->view($position)['pieces'],
            'legal' => $outcome === null ? array_map(fn (string $move): array => ['move' => $move, 'path' => $rules->path($move)], $rules->legalMoves($position)) : [],
            ...$this->standing($position),
            'outcome' => $outcome,
        ];
    }

    /** @return TPosition|null */
    private function position(mixed $text): mixed
    {
        if (! is_string($text) || strlen($text) > $this->positionLength) {
            return null;
        }

        try {
            return $this->rules()->deserialize($text);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @return list<string>|null */
    private function history(mixed $history): ?array
    {
        if (! is_array($history) || count($history) > $this->historyLimit) {
            return null;
        }

        $positions = [];

        foreach ($history as $entry) {
            if (! is_string($entry) || strlen($entry) > $this->positionLength) {
                return null;
            }

            $positions[] = $entry;
        }

        return $positions;
    }
}
