<?php

namespace App\Games\Contracts;

use App\Games\GameAssets;
use App\Games\GameMode;

/**
 * A game the league runs, defined in code (plan "Spiel-Registry als Code").
 *
 * A new game is a new class plus one line in `config/esports.php` (`games`).
 * Everything generic (challenges, Elo, ladders) reads the game through this
 * contract; nothing outside the game class knows its rules.
 *
 * Game classes stay free of framework helpers (no `__()`, no config), so the
 * unit tests run without booting Laravel. Views translate the English names.
 */
interface Game
{
    /**
     * Registry slug, used in `d` values and ladder addresses (NIP "Identifiers").
     */
    public function slug(): string;

    public function name(): string;

    /**
     * @return array<string, GameMode> keyed by mode slug, in display order
     */
    public function modes(): array;

    public function mode(string $slug): ?GameMode;

    /**
     * Describes the result a match of this mode reports, field by field.
     *
     * @return array<string, string>
     */
    public function resultSchema(GameMode $mode): array;

    /**
     * Check a reported result against the rules of the mode.
     *
     * @param  array<string, mixed>  $result
     * @return list<string> error codes; empty means valid
     */
    public function validateResult(GameMode $mode, array $result): array;

    public function assets(): GameAssets;
}
