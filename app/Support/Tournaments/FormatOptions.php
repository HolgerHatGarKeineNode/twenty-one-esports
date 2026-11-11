<?php

namespace App\Support\Tournaments;

use App\Games\BoardGame as BoardGameDefinition;
use App\Games\GameRegistry;

/**
 * The options of every format at once, as the chooser keeps them while the
 * organizer switches between systems (`tfDefaults` of the artboard script,
 * TOURNAMENT-FORMATS.md, sections 1 and 7). A tournament stores all of them;
 * only the ones of its format are read.
 */
final readonly class FormatOptions
{
    public const GRAND_FINALS = ['reset', 'single', 'skip'];

    public const RANK_BY = ['points', 'match-wins', 'game-wins', 'custom'];

    /** Challonge's tie-break statistics; `median-buchholz` is for Swiss only. */
    public const TIE_BREAKS = ['match-wins', 'game-wins', 'game-win-percentage', 'game-difference', 'points-scored', 'points-difference', 'head-to-head', 'median-buchholz'];

    public const GROUP_STAGES = ['round-robin', 'single-elimination', 'double-elimination'];

    public const FINAL_STAGES = ['single-elimination', 'double-elimination'];

    /**
     * What a tie for place 1 of a final table (round robin, Swiss) that no tie-break splits comes to
     * ({@see $firstPlaceTie}): a tie-break match among the tied (`playoff`), or a shared place 1 (`shared`). The
     * organizer picks one of these two.
     */
    public const FIRST_PLACE_TIES = ['playoff', 'shared'];

    /**
     * The seed order splits the tie, as every table did before the choice existed (DerCaddy, 2026-11-05: Blitz Blockli
     * #55 went to the higher seed at 6:2 against 6:2 and 2:2 head-to-head). Never offered; what options stored without
     * the key mean, and what a casual cup keeps (its seeds are the block hash's lot, CasualCups::formatFor()).
     */
    public const FIRST_PLACE_TIE_SEED = 'seed';

    /**
     * @param  'reset'|'single'|'skip'  $grandFinal
     * @param  'points'|'match-wins'|'game-wins'|'custom'  $rankBy
     * @param  int|null  $swissRounds  null = as many as fit the time (recommendation rule 2)
     * @param  list<string>  $swissTieBreaks
     * @param  list<string>  $roundRobinTieBreaks
     * @param  'round-robin'|'single-elimination'|'double-elimination'  $groupStage
     * @param  'single-elimination'|'double-elimination'  $finalStage
     * @param  int  $lobbyMinutes  Free for All as lobbies (P10, Lobbies): one round, nobody advances, each lobby
     *                             planned at this many minutes; 0 = heats whose best move on
     * @param  int  $teamSize  Hyperbitcoinization only (plan "Hyperbitcoinization", P5b): 1 = players enter, 2 or 3 =
     *                         clans enter and play a 2v2 or 3v3 knockout ({@see HYPER_TEAM_SIZES}); 1 for every other game
     * @param  int  $legs  A two-leg board game (Blockli, TournamentRunner::playsTwoLegs()) in a round-robin table (the round
     *                     robin, or round-robin groups): 2 games per pairing with the colours swapped, or 1 (DerCaddy,
     *                     2026-10-18: "das sollte man vorher einstellen können"); a knockout always plays two
     * @param  bool  $legPoints  Two legs in a round-robin table count game by game: a win 1, a draw ½ per game, up to 2 points
     *                           per pairing, each game in the table once it ends (DerCaddy, 2026-10-18: "Wenn man zwei Spiele
     *                           gegeneinander spielt, sollte man dann auch zwei Punkte bekommen können"); false: the pairing
     *                           counts once, as before
     * @param  'playoff'|'shared'|'seed'  $firstPlaceTie  A final table's tie for place 1 that points and every tie-break
     *                                                    leave (DerCaddy, 2026-11-06: "entweder Stichkampf oder geteilter
     *                                                    erster Platz, soll der Turnierleiter im Editor auswählen können"):
     *                                                    a knockout among the tied decides (FirstPlaceTie::openPlayoff()),
     *                                                    or they share place 1; `seed` only for options stored before
     *                                                    ({@see FIRST_PLACE_TIE_SEED})
     * @param  string|null  $pairGame  A combined tournament (DerCaddy, 2026-11-11: "kombinierte Turniere … Blockli und
     *                                 Blockspace gegeneinander", one game of each per pairing): the second board game, whose
     *                                 slug plays leg 2 and every other decider ({@see TournamentRunner::legGame()}); only
     *                                 another board game with the tournament's mode ({@see pairGames()}), and the pairing
     *                                 always plays two legs. Null: one game, as before
     */
    public function __construct(
        public int $bestOf = 1,
        public int $finalBestOf = 1,
        public bool $thirdPlace = false,
        public string $grandFinal = 'reset',
        public bool $split = false,
        public int $iterations = 1,
        public string $rankBy = 'points',
        public ?int $swissRounds = null,
        public float $pointsWin = 1.0,
        public float $pointsTie = 0.5,
        public float $pointsBye = 1.0,
        public array $swissTieBreaks = ['median-buchholz', 'head-to-head', 'game-wins'],
        public array $roundRobinTieBreaks = ['head-to-head', 'game-wins', 'game-difference'],
        public int $groupSize = 4,
        public int $advance = 2,
        public string $groupStage = 'round-robin',
        public string $finalStage = 'single-elimination',
        public int $heatSize = 4,
        public int $heatAdvance = 2,
        public int $lobbyMinutes = 0,
        public int $teamSize = 1,
        public int $legs = 2,
        public bool $legPoints = false,
        public string $firstPlaceTie = 'playoff',
        public ?string $pairGame = null,
    ) {}

    /** Whether the games of a two-leg pairing count one by one in a round-robin table ({@see $legPoints}). */
    public function perLeg(): bool
    {
        return $this->legs === 2 && $this->legPoints;
    }

    /** What options stored before the choice of legs existed mean ({@see $legs}): two games per pairing. */
    private const STORED_LEGS = 2;

    /** What options stored before the choice of leg points existed mean ({@see $legPoints}): the pairing counts once. */
    private const STORED_LEG_POINTS = false;

    /** The players per side of a Hyperbitcoinization tournament (P5b): players alone, or a clan 2v2 or 3v3. */
    public const HYPER_TEAM_SIZES = [1, 2, 3];

    public static function defaults(GameProfile $profile): self
    {
        // A lobby game (P10) always plays Free for All as lobbies: its heat options come from the game, never the form.
        if (Lobbies::isLobbyGame($profile->game)) {
            $lobby = Lobbies::options($profile->game);

            return new self(bestOf: $profile->bestOf, finalBestOf: $profile->finalBestOf, heatSize: $lobby['heatSize'], heatAdvance: $lobby['heatAdvance'], lobbyMinutes: $lobby['lobbyMinutes']);
        }

        return new self(bestOf: $profile->bestOf, finalBestOf: $profile->finalBestOf);
    }

    /**
     * Options from stored or submitted values; anything missing or out of
     * range falls back to the default of the game, so a stale draft never
     * breaks the chooser.
     *
     * `$lobbyFromGame` (P10): a lobby game's heat options come from the game
     * (Lobbies::options()), whatever is stored; false for a tournament
     * already drawn, whose stored options keep holding (a Free for All drawn
     * before the game became a lobby game keeps its heats, see
     * Tournament::formatOptions()).
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values, GameProfile $profile, bool $lobbyFromGame = true): self
    {
        $defaults = self::defaults($profile);
        $forced = $lobbyFromGame && $defaults->lobbyMinutes > 0;
        $int = fn (string $key, int $default, int $min, int $max): int => is_numeric($values[$key] ?? null) && (int) $values[$key] >= $min && (int) $values[$key] <= $max ? (int) $values[$key] : $default;
        $float = fn (string $key, float $default): float => is_numeric($values[$key] ?? null) && (float) $values[$key] >= 0 && (float) $values[$key] <= 10 ? (float) $values[$key] : $default;
        $pick = fn (string $key, string $default, array $allowed): string => in_array($values[$key] ?? null, $allowed, true) ? (string) $values[$key] : $default;

        $twoLegs = TournamentRunner::playsTwoLegs($profile->game);
        // A combined tournament's second game: only one the chooser offers for this game and mode, else none.
        $pairGame = $twoLegs && in_array($values['pairGame'] ?? null, self::pairGames($profile), true) ? (string) $values['pairGame'] : null;
        $bestOf = in_array((int) ($values['bestOf'] ?? 0), $profile->bestOfOptions, true) ? (int) $values['bestOf'] : $defaults->bestOf;
        $finalBestOf = in_array((int) ($values['finalBestOf'] ?? 0), $profile->bestOfOptions, true) ? (int) $values['finalBestOf'] : $defaults->finalBestOf;

        /** @var 'reset'|'single'|'skip' $grandFinal */
        $grandFinal = $pick('grandFinal', $defaults->grandFinal, self::GRAND_FINALS);
        /** @var 'points'|'match-wins'|'game-wins'|'custom' $rankBy */
        $rankBy = $pick('rankBy', $defaults->rankBy, self::RANK_BY);
        /** @var 'round-robin'|'single-elimination'|'double-elimination' $groupStage */
        $groupStage = $pick('groupStage', $defaults->groupStage, self::GROUP_STAGES);
        /** @var 'single-elimination'|'double-elimination' $finalStage */
        $finalStage = $pick('finalStage', $defaults->finalStage, self::FINAL_STAGES);

        return new self(
            bestOf: $bestOf,
            finalBestOf: max($bestOf, $finalBestOf),
            thirdPlace: (bool) ($values['thirdPlace'] ?? $defaults->thirdPlace),
            grandFinal: $grandFinal,
            split: (bool) ($values['split'] ?? $defaults->split),
            iterations: $int('iterations', $defaults->iterations, 1, 3),
            rankBy: $rankBy,
            swissRounds: is_numeric($values['swissRounds'] ?? null) && (int) $values['swissRounds'] >= 1 ? (int) $values['swissRounds'] : null,
            pointsWin: $float('pointsWin', $defaults->pointsWin),
            pointsTie: $float('pointsTie', $defaults->pointsTie),
            pointsBye: $float('pointsBye', $defaults->pointsBye),
            swissTieBreaks: self::tieBreakList($values['swissTieBreaks'] ?? null, $defaults->swissTieBreaks, true),
            roundRobinTieBreaks: self::tieBreakList($values['roundRobinTieBreaks'] ?? null, $defaults->roundRobinTieBreaks, false),
            groupSize: $int('groupSize', $defaults->groupSize, 3, 8),
            advance: $int('advance', $defaults->advance, 1, 4),
            groupStage: $groupStage,
            finalStage: $finalStage,
            // A lobby game's lobbies are the game's (P10): stored or submitted heat values are not read.
            // Hyperbitcoinization's tables (P5) seat `esports.hyper.tournament_tables` players, and at least one of a table drops out.
            heatSize: $forced ? $defaults->heatSize : ($profile->isHyper() ? $int('heatSize', 4, ...self::hyperTables()) : $int('heatSize', 4, 3, 16)),
            heatAdvance: $forced ? $defaults->heatAdvance : ($profile->isHyper() ? min($int('heatAdvance', 2, 1, 8), $int('heatSize', 4, ...self::hyperTables()) - 1) : $int('heatAdvance', 2, 1, 8)),
            // Not forced: what is stored; a tournament stored without it has heats, never lobbies.
            // A game that plays no lobbies never has lobby minutes, whatever is stored (re-audit P10, R9b).
            lobbyMinutes: $defaults->lobbyMinutes === 0 ? 0 : ($forced ? $defaults->lobbyMinutes : $int('lobbyMinutes', 0, 0, 10_000)),
            teamSize: $profile->isHyper() ? self::hyperTeamSize($values) : 1,
            // Only a game that plays two legs has them to choose (TournamentRunner::playsTwoLegs()): any other keeps one
            // result per match, whatever a draft stored before its game changed. Options stored without the keys predate
            // the choice: they keep two games counted per pairing, as they were drawn, whatever the defaults become.
            // A combined tournament plays one game of each per pairing: always two.
            legs: $pairGame !== null ? 2 : ($twoLegs ? $int('legs', self::STORED_LEGS, 1, 2) : self::STORED_LEGS),
            legPoints: $twoLegs && (bool) ($values['legPoints'] ?? self::STORED_LEG_POINTS),
            // Options stored without the key predate the choice: their ties stay with the seed order, as they were drawn
            // and paid out; anything else unknown takes the default.
            firstPlaceTie: ! array_key_exists('firstPlaceTie', $values) ? self::FIRST_PLACE_TIE_SEED : match ($values['firstPlaceTie']) {
                'playoff' => 'playoff',
                'shared' => 'shared',
                self::FIRST_PLACE_TIE_SEED => self::FIRST_PLACE_TIE_SEED,
                default => $defaults->firstPlaceTie,
            },
            pairGame: $pairGame,
        );
    }

    /**
     * The board games a tournament of this game and mode can be combined with ({@see $pairGame}): a tournament of a two-leg
     * game (Blockli) takes any other switched-on board game for two (Blockspace, Nine Men's Morris, Checkers) that has the
     * same mode; that game's leg replaces the return leg.
     *
     * @return list<string>
     */
    public static function pairGames(GameProfile $profile): array
    {
        if (! TournamentRunner::playsTwoLegs($profile->game)) {
            return [];
        }

        $registry = app(GameRegistry::class);

        return array_values(array_filter(
            array_map(fn ($game): string => $game->slug(), $registry->all()),
            fn (string $slug): bool => $slug !== $profile->game && $registry->find($slug) instanceof BoardGameDefinition && $registry->mode($slug, $profile->mode) !== null,
        ));
    }

    /**
     * The team size stored or submitted for a Hyperbitcoinization tournament (P5b): 1, 2 or 3, anything else 1.
     * Read by Tournament::profile() too, which cannot build the options before the profile.
     *
     * @param  array<string, mixed>|null  $values
     */
    public static function hyperTeamSize(?array $values): int
    {
        $size = $values['teamSize'] ?? null;

        return is_numeric($size) && in_array((int) $size, self::HYPER_TEAM_SIZES, true) ? (int) $size : 1;
    }

    /**
     * The smallest and largest table of a Hyperbitcoinization free-for-all (P5), `esports.hyper.tournament_tables`.
     *
     * @return array{0: int, 1: int}
     */
    public static function hyperTables(): array
    {
        [$min, $max] = array_map(intval(...), (array) config('esports.hyper.tournament_tables', [3, 6])) + [3, 6];

        return [max(2, min($min, 6)), max(2, min($max, 6))];
    }

    /**
     * Up to three distinct known tie-breaks, in order; `median-buchholz` only for Swiss.
     *
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function tieBreakList(mixed $value, array $default, bool $swiss): array
    {
        $list = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && in_array($item, self::TIE_BREAKS, true) && ($swiss || $item !== 'median-buchholz') && ! in_array($item, $list, true)) {
                $list[] = $item;
            }
        }

        return $list === [] ? $default : array_slice($list, 0, 3);
    }

    public function withSwissRounds(?int $rounds): self
    {
        return new self($this->bestOf, $this->finalBestOf, $this->thirdPlace, $this->grandFinal, $this->split, $this->iterations,
            $this->rankBy, $rounds, $this->pointsWin, $this->pointsTie, $this->pointsBye, $this->swissTieBreaks, $this->roundRobinTieBreaks,
            $this->groupSize, $this->advance, $this->groupStage, $this->finalStage, $this->heatSize, $this->heatAdvance, $this->lobbyMinutes, $this->teamSize,
            $this->legs, $this->legPoints, $this->firstPlaceTie, $this->pairGame);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes, GameProfile $profile): self
    {
        return self::fromArray([...$this->toArray(), ...$changes], $profile);
    }

    /**
     * @return array{bestOf: int, finalBestOf: int, thirdPlace: bool, grandFinal: string, split: bool, iterations: int, rankBy: string, swissRounds: int|null, pointsWin: float, pointsTie: float, pointsBye: float, swissTieBreaks: list<string>, roundRobinTieBreaks: list<string>, groupSize: int, advance: int, groupStage: string, finalStage: string, heatSize: int, heatAdvance: int, lobbyMinutes: int, teamSize: int, legs: int, legPoints: bool, firstPlaceTie: string, pairGame: string|null}
     */
    public function toArray(): array
    {
        return [
            'bestOf' => $this->bestOf,
            'finalBestOf' => $this->finalBestOf,
            'thirdPlace' => $this->thirdPlace,
            'grandFinal' => $this->grandFinal,
            'split' => $this->split,
            'iterations' => $this->iterations,
            'rankBy' => $this->rankBy,
            'swissRounds' => $this->swissRounds,
            'pointsWin' => $this->pointsWin,
            'pointsTie' => $this->pointsTie,
            'pointsBye' => $this->pointsBye,
            'swissTieBreaks' => $this->swissTieBreaks,
            'roundRobinTieBreaks' => $this->roundRobinTieBreaks,
            'groupSize' => $this->groupSize,
            'advance' => $this->advance,
            'groupStage' => $this->groupStage,
            'finalStage' => $this->finalStage,
            'heatSize' => $this->heatSize,
            'heatAdvance' => $this->heatAdvance,
            'lobbyMinutes' => $this->lobbyMinutes,
            'teamSize' => $this->teamSize,
            'legs' => $this->legs,
            'legPoints' => $this->legPoints,
            'firstPlaceTie' => $this->firstPlaceTie,
            'pairGame' => $this->pairGame,
        ];
    }
}
