<?php

namespace App\Games;

use App\Support\Board\BoardRules;
use App\Support\Board\GoRules;

/**
 * Go on one board size (DerCaddy, 2026-10-27: Go "nach dem Vorbild von Blockli", on 9 x 9, 13 x 13 and 19 x 19), played
 * move by move on the board game core; the rules are {@see GoRules}. Each size is a game of its own, as each EA Sports
 * FC edition is: its own lobby, ladders and tournaments, since a 9 x 9 game and a 19 x 19 game ask for other skills and
 * other times. Registered through `config('esports.board_games.games')` (go-9, go-13, go-19), off until
 * ESPORTS_BOARD_GAME_GO (or the size's own switch) turns it on.
 *
 * Black moves first: the core's first side (`w`) plays the black stones, so the sides are named the other way round
 * than in chess (sideColours()). White's komi of 7.5 makes up for the first move, so a pairing plays one game.
 */
abstract class Go extends BoardGame
{
    public const RESULTS = ['1-0', '0-1', '1/2-1/2'];

    /**
     * The Go size the pages all sizes share belong to (the practice: its context bar, its card, its way back to a lobby):
     * the smallest one switched on, null while none is.
     */
    public static function home(): ?string
    {
        $registry = app(GameRegistry::class);

        foreach ([Go9::SLUG, Go13::SLUG, Go19::SLUG] as $slug) {
            if ($registry->find($slug) instanceof self) {
                return $slug;
            }
        }

        return null;
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
        return ['result' => 'one of '.implode(', ', self::RESULTS)];
    }

    public function validateResult(GameMode $mode, array $result): array
    {
        return in_array($result['result'] ?? null, self::RESULTS, true) ? [] : ['result'];
    }

    public function sideColours(): array
    {
        return ['w' => 'black', 'b' => 'white'];
    }

    public function assets(): GameAssets
    {
        return new GameAssets('go', 'var(--color-edge)', 'var(--color-line)', $this->name(), new GameCover('go'), $this->credit(), $this->creditUrl());
    }
}
