<?php

namespace App\Games;

use App\Games\Contracts\Grouped;
use App\Support\Board\BoardRules;
use App\Support\Board\GoRules;

/**
 * Go on one board size (DerCaddy, 2026-10-27: Go "nach dem Vorbild von Blockli", on 9 x 9, 13 x 13 and 19 x 19), played
 * move by move on the board game core; the rules are {@see GoRules}. Each size is a game of its own, as each EA Sports
 * FC edition is: its own lobby, ladders and tournaments, since a 9 x 9 game and a 19 x 19 game ask for other skills and
 * other times. Together they are one group, "Go": one entry wherever games are listed, with a switch between the sizes.
 * Registered through `config('esports.board_games.games')` (go-9, go-13, go-19), off until
 * ESPORTS_BOARD_GAME_GO (or the size's own switch) turns it on.
 *
 * Black moves first: the core's first side (`w`) plays the black stones, so the sides are named the other way round
 * than in chess (sides()). White's komi of 7.5 makes up for the first move, so a pairing plays one game.
 *
 * Rapid on every size plays clan team matches over 2 or 3 boards, as chess and Blockli rapid (DerCaddy, 2026-11-23:
 * "Go sollte auch als Mannschaftsspiel möglich sein!"; NIP rev. 9.28): a lineup per size (`<clan>/go-9/rapid`, ...),
 * the board order by that size's rapid Elo, the challenger with the first move, Black, on boards 1 and 3.
 */
abstract class Go extends BoardGame implements Grouped
{
    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    /** The slug of the group the sizes form: their one entry in every list of games, their one chat channel. */
    public const GROUP = 'go';

    /**
     * The Go size the pages all sizes share belong to (the practice: its context bar, its card, its way back to a lobby):
     * the group's home, the smallest one switched on, null while none is.
     */
    public static function home(): ?string
    {
        return app(GameRegistry::class)->home(self::GROUP);
    }

    public function group(): GameGroup
    {
        return new GameGroup(self::GROUP, 'Go', new GameCover('go'));
    }

    public function groupLabel(): string
    {
        return $this->size().'×'.$this->size();
    }

    /** The lines of the board: 9, 13 or 19. */
    abstract public function size(): int;

    public function name(): string
    {
        return 'Go '.$this->size().'×'.$this->size();
    }

    public function rules(): BoardRules
    {
        return new GoRules($this->size());
    }

    public function resultSchema(GameMode $mode): array
    {
        // As Blockli (NIP rev. 9.28): a team match reports one result per board, in rapid, the mode that has boards.
        if ($mode->boards === []) {
            return ['result' => 'one of '.implode(', ', self::RESULTS)];
        }

        return [
            'format' => 'solo | team',
            'result' => 'solo: one of '.implode(', ', self::RESULTS),
            'boards' => 'team: list of results, board 1 first, '.implode(' or ', $mode->boards).' boards',
        ];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        if ($mode->boards === []) {
            return in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'];
        }

        return match ($result['format'] ?? null) {
            'solo' => in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'],
            'team' => $this->validateTeamMatch($mode, $result['boards'] ?? null),
            default => ['format'],
        };
    }

    /**
     * @return list<string>
     */
    private function validateTeamMatch(GameMode $mode, mixed $boards): array
    {
        if (! is_array($boards) || ! array_is_list($boards) || ! $mode->allowsBoards(count($boards))) {
            return ['boards_not_allowed'];
        }

        $errors = [];

        foreach ($boards as $index => $board) {
            if (! in_array($board, self::RESULTS, true)) {
                $errors[] = 'board_'.($index + 1).'_result';
            }
        }

        return $errors;
    }

    public function sides(): array
    {
        return ['w' => BoardSide::black(), 'b' => BoardSide::white()];
    }

    public function assets(): GameAssets
    {
        return new GameAssets('go', 'var(--color-edge)', 'var(--color-line)', $this->name(), new GameCover('go'), $this->credit(), $this->creditUrl());
    }
}
