<?php

namespace App\Support\Caster\GameMoments;

use App\Games\GameRegistry;
use App\Games\Go;
use App\Models\BoardGame;
use App\Support\Board\BlockliRules;
use App\Support\Board\BoardGameService;
use App\Support\Board\CheckersRules;
use App\Support\Board\GoRules;
use App\Support\Board\NineMensMorrisRules;

/**
 * What a running board game gives the caster, read from its position as
 * its rules deserialize it:
 *
 * - Blockli (`race`, `race_level`): the race standing the board shows
 *   beside the game (RaceStanding): each side's steps to its goal and the
 *   blocks it has left of BlockliRules::BLOCKS; who is ahead by the score
 *   (steps minus BLOCK_STEPS per block held). Within CLOSE_RACE of each
 *   other is close, DOMINANT_RACE or more apart dominance; a side one
 *   step from its goal is tension.
 * - Nine men's morris (`morris`, `morris_level`): men left per side (on the
 *   board and in hand), mills standing, a side down to three men flies.
 * - Checkers (`checkers`, `checkers_level`): pieces and kings per side.
 * - Go, every size (`go_captures`, `go_count`): the stones a side has
 *   taken off the board; and once a side has passed, who wins by how much
 *   if the other passes too (GoRules::areaCount()). The count is left out
 *   before a pass: early in a game it says nothing (after the first stone
 *   the whole board is Black's area).
 *
 * Every moment carries the spoken game name and both players' names
 * (`a`, `b`: White, Black), `kind`, `mood` where the rule gives one and
 * `lead`, the player the moment is about.
 */
final class BoardMoments
{
    /** Spoken game names by registry slug, or by the slug of a game's group (Go: one name for its three sizes). */
    public const NAMES = ['blockli' => 'Blockli', 'blockspace' => 'Blockspace', 'nine-mens-morris' => 'Mühle', 'checkers' => 'Dame', Go::GROUP => 'Go'];

    /** A race score margin up to this is close. */
    public const CLOSE_RACE = 1.0;

    /** A race score margin from this is dominance. */
    public const DOMINANT_RACE = 4.0;

    /** A lead of this many men or pieces is dominance (morris, checkers). */
    public const DOMINANT_PIECES = 3;

    /** Checkers pieces at the start, per side. */
    public const CHECKERS_PIECES = 12;

    public function __construct(private BoardGameService $boards, private GameRegistry $games) {}

    /** The spoken name of a board game: its own, its group's, else its slug. */
    public function name(string $slug): string
    {
        return self::NAMES[$slug] ?? self::NAMES[$this->games->groupOf($slug)?->slug ?? ''] ?? $slug;
    }

    /**
     * @return list<array<string, string|int>>
     */
    public function of(BoardGame $game, string $white, string $black): array
    {
        $rules = $this->boards->rulesOf($game);
        $names = ['w' => $white, 'b' => $black];
        $name = $this->name($game->game);
        $base = ['game' => $name, 'match' => MatchAnchor::of($game, $name), 'a' => $white, 'b' => $black];

        return match (true) {
            $rules instanceof BlockliRules => self::race($rules->standing($rules->deserialize($game->position)), $names, $base),
            $rules instanceof NineMensMorrisRules => self::morris($rules->deserialize($game->position), $names, $base),
            $rules instanceof CheckersRules => self::checkers($rules->deserialize($game->position), $names, $base),
            $rules instanceof GoRules => self::go($rules->areaCount($position = $rules->deserialize($game->position)), $position['passes'], $this->stonesSet($game), $names, $base),
            default => [],
        };
    }

    /**
     * The stones each side has put on the board in this game: its moves that were no pass (the first side makes the
     * odd plies).
     *
     * @return array{w: int, b: int}
     */
    private function stonesSet(BoardGame $game): array
    {
        $plies = $game->moves()->where('move', '!=', GoRules::PASS)->pluck('ply');
        $first = $plies->filter(fn (int $ply): bool => $ply % 2 === 1)->count();

        return ['w' => $first, 'b' => $plies->count() - $first];
    }

    /**
     * @param  array{w: array{stones: int, area: int, score: float}, b: array{stones: int, area: int, score: float}, margin: float, lead: 'w'|'b'|null}  $count  GoRules::areaCount()
     * @param  int  $passes  the passes in a row before this position
     * @param  array{w: int, b: int}  $set  the stones each side has put on the board
     * @param  array{w: string, b: string}  $names
     * @param  array<string, string>  $base
     * @return list<array<string, string|int>>
     */
    public static function go(array $count, int $passes, array $set, array $names, array $base): array
    {
        $moments = [];

        foreach (['w' => 'b', 'b' => 'w'] as $side => $other) {
            // What the other side set and no longer has was taken by this one (never negative on a position put up by hand).
            $taken = max(0, $set[$other] - $count[$other]['stones']);

            if ($taken > 0) {
                $moments[] = [...$base, 'kind' => 'go_captures', 'lead' => $names[$side], 'captor' => $names[$side], 'stones_taken' => Words::count($taken, 'einen Stein', 'Steine')];
            }
        }

        $lead = $count['lead'];

        if ($passes > 0 && $lead !== null) {
            $margin = abs($count['margin']);
            $moments[] = [...$base, 'kind' => 'go_count', 'mood' => Mood::TENSION, 'lead' => $names[$lead], 'count_leader' => $names[$lead],
                'count_margin' => floor($margin) === $margin ? Words::count((int) $margin, 'einen Punkt', 'Punkte') : str_replace('.', ',', (string) $margin).' Punkte'];
        }

        return $moments;
    }

    /**
     * @param  array{w: array{steps: int, blocks: int, score: float}, b: array{steps: int, blocks: int, score: float}, margin: float, lead: 'w'|'b'|null, rate: float}  $standing
     * @param  array{w: string, b: string}  $names
     * @param  array<string, string>  $base
     * @return list<array<string, string|int>>
     */
    public static function race(array $standing, array $names, array $base): array
    {
        $sides = ['w' => $standing['w'], 'b' => $standing['b']];
        $steps = fn (string $side): string => Words::count($sides[$side]['steps'], 'einen Schritt', 'Schritte');
        $blocks = fn (string $side): string => Words::count($sides[$side]['blocks'], 'einen Block', 'Blöcke');
        $used = fn (string $side): ?string => $sides[$side]['blocks'] < BlockliRules::BLOCKS ? Words::count(BlockliRules::BLOCKS - $sides[$side]['blocks'], 'einen Block', 'Blöcke') : null;
        $moments = [];
        $lead = $standing['lead'];

        if ($lead === null) {
            $moments[] = [...$base, 'kind' => 'race_level', 'mood' => Mood::CLOSE, 'level_a_steps' => $steps('w'), 'level_b_steps' => $steps('b')];
        } else {
            $trail = $lead === 'w' ? 'b' : 'w';
            $margin = abs($standing['margin']);
            $moments[] = array_filter([...$base, 'kind' => 'race', 'mood' => match (true) {
                $margin <= self::CLOSE_RACE => Mood::CLOSE,
                $margin >= self::DOMINANT_RACE => Mood::DOMINANCE,
                default => null,
            }, 'lead' => $names[$lead], 'race_leader' => $names[$lead], 'race_trailer' => $names[$trail],
                'leader_steps' => $steps($lead), 'trailer_steps' => $steps($trail), 'leader_blocks_left' => $blocks($lead), 'trailer_blocks_left' => $blocks($trail),
                'leader_blocks_used' => $used($lead), 'trailer_blocks_used' => $used($trail)], fn (mixed $value): bool => $value !== null);
        }

        foreach (['w', 'b'] as $side) {
            if ($sides[$side]['steps'] === 1) {
                $moments[] = [...$base, 'kind' => 'race_goal', 'mood' => Mood::TENSION, 'lead' => $names[$side], 'one_step' => $names[$side]];
            }
        }

        return $moments;
    }

    /**
     * @param  array{board: array<string, 'w'|'b'|null>, turn: 'w'|'b', hand: array{w: int, b: int}, quiet: int}  $position
     * @param  array{w: string, b: string}  $names
     * @param  array<string, string>  $base
     * @return list<array<string, string|int>>
     */
    public static function morris(array $position, array $names, array $base): array
    {
        $left = [];
        $mills = ['w' => 0, 'b' => 0];

        foreach (['w', 'b'] as $side) {
            $left[$side] = count(array_filter($position['board'], fn (?string $owner): bool => $owner === $side)) + $position['hand'][$side];
        }

        foreach (NineMensMorrisRules::LINES as $line) {
            $owners = array_unique(array_map(fn (string $point): ?string => $position['board'][$point], $line));

            if (count($owners) === 1 && $owners[0] !== null) {
                $mills[$owners[0]]++;
            }
        }

        $men = fn (string $side): string => Words::count($left[$side], 'einen Stein', 'Steine');
        $moments = [];

        if ($left['w'] === $left['b']) {
            $moments[] = [...$base, 'kind' => 'morris_level', 'mood' => Mood::CLOSE, 'men_each' => $men('w'), 'men_left' => $left['w']];
        } else {
            $lead = $left['w'] > $left['b'] ? 'w' : 'b';
            $trail = $lead === 'w' ? 'b' : 'w';
            $moments[] = array_filter([...$base, 'kind' => 'morris', 'mood' => $left[$lead] - $left[$trail] >= self::DOMINANT_PIECES ? Mood::DOMINANCE : null,
                'lead' => $names[$lead], 'men_leader' => $names[$lead], 'men_trailer' => $names[$trail], 'leader_men' => $men($lead), 'trailer_men' => $men($trail),
                'trailer_men_lost' => Words::count(NineMensMorrisRules::MEN - $left[$trail], 'einen Stein', 'Steine')], fn (mixed $value): bool => $value !== null);
        }

        foreach (['w', 'b'] as $side) {
            if ($mills[$side] > 0) {
                $moments[] = [...$base, 'kind' => 'mills', 'lead' => $names[$side], 'mill_owner' => $names[$side], 'mills' => Words::count($mills[$side], 'eine Mühle', 'Mühlen')];
            }

            if ($left[$side] === NineMensMorrisRules::FLYING_MEN && $position['hand'][$side] === 0) {
                $moments[] = [...$base, 'kind' => 'flying', 'mood' => Mood::TENSION, 'lead' => $names[$side], 'flying' => $names[$side]];
            }
        }

        return $moments;
    }

    /**
     * @param  array{board: array<string, 'w'|'b'|'W'|'B'|null>, turn: 'w'|'b', quiet: int}  $position
     * @param  array{w: string, b: string}  $names
     * @param  array<string, string>  $base
     * @return list<array<string, string|int>>
     */
    public static function checkers(array $position, array $names, array $base): array
    {
        $pieces = ['w' => 0, 'b' => 0];
        $kings = ['w' => 0, 'b' => 0];

        foreach ($position['board'] as $piece) {
            if ($piece !== null) {
                $side = strtolower($piece) === 'w' ? 'w' : 'b';
                $pieces[$side]++;
                $kings[$side] += ctype_upper($piece) ? 1 : 0;
            }
        }

        $count = fn (string $side): string => Words::count($pieces[$side], 'einen Stein', 'Steine');
        $moments = [];

        if ($pieces['w'] === $pieces['b']) {
            if ($pieces['w'] < self::CHECKERS_PIECES) {
                $moments[] = [...$base, 'kind' => 'checkers_level', 'mood' => Mood::CLOSE, 'pieces_each' => $count('w'), 'pieces_left' => $pieces['w']];
            }
        } else {
            $lead = $pieces['w'] > $pieces['b'] ? 'w' : 'b';
            $trail = $lead === 'w' ? 'b' : 'w';
            $moments[] = array_filter([...$base, 'kind' => 'checkers', 'mood' => $pieces[$lead] - $pieces[$trail] >= self::DOMINANT_PIECES ? Mood::DOMINANCE : null,
                'lead' => $names[$lead], 'pieces_leader' => $names[$lead], 'pieces_trailer' => $names[$trail], 'leader_pieces' => $count($lead), 'trailer_pieces' => $count($trail)],
                fn (mixed $value): bool => $value !== null);
        }

        foreach (['w', 'b'] as $side) {
            if ($kings[$side] > 0) {
                $moments[] = [...$base, 'kind' => 'kings', 'lead' => $names[$side], 'king_owner' => $names[$side], 'kings' => Words::count($kings[$side], 'eine Dame', 'Damen')];
            }
        }

        return $moments;
    }
}
