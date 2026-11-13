<?php

namespace App\Support\Tournaments;

use App\Games\BoardGame as BoardGameDefinition;
use App\Games\Chess;
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
     * What a combined tournament plays after its sequence ends level ({@see $decider}): the sequence's games again in
     * turn (`cycle`, as #28 played its two games), or always its first game (`first`).
     */
    public const DECIDERS = ['cycle', 'first'];

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
     * @param  list<string>  $games  A combined tournament (DerCaddy, 2026-11-11: "kombinierte Turniere … Blockli und
     *                               Blockspace gegeneinander"; user, 2026-11-11: the organizer composes the sequence): the
     *                               games every pairing plays in this order, one leg each ({@see TournamentRunner::legGame()}),
     *                               the tournament's own game first; a game may repeat. Chess or a board game with the
     *                               tournament's mode ({@see pairGames()}), at most `esports.tournaments.sequence_max_games`.
     *                               Fewer than two: one game, as before (an empty list)
     * @param  'cycle'|'first'  $decider  What a level sequence plays next ({@see DECIDERS})
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
        public array $games = [],
        public string $decider = 'cycle',
    ) {
        $this->pairGame = $games[1] ?? null;
    }

    /**
     * The second game of a combined tournament's sequence, null for one game: the game #28's copy and views name beside
     * the tournament's own ({@see $games} holds the whole sequence).
     */
    public ?string $pairGame;

    /** Whether the pairings play a sequence of games ({@see $games}): two or more, one leg each. */
    public function sequence(): bool
    {
        return count($this->games) >= 2;
    }

    /** The games a two-leg pairing plays before a decider: the length of a combined tournament's sequence ({@see $games}), else its two legs. */
    public function legCount(): int
    {
        return $this->sequence() ? count($this->games) : 2;
    }

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
        $games = self::gameSequence($values, $profile);
        $sequence = count($games) >= 2;
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
            // A combined tournament plays its sequence per pairing: always more than one game.
            legs: $sequence ? 2 : ($twoLegs ? $int('legs', self::STORED_LEGS, 1, 2) : self::STORED_LEGS),
            legPoints: ($twoLegs || $sequence) && (bool) ($values['legPoints'] ?? self::STORED_LEG_POINTS),
            // Options stored without the key predate the choice: their ties stay with the seed order, as they were drawn
            // and paid out; anything else unknown takes the default.
            firstPlaceTie: ! array_key_exists('firstPlaceTie', $values) ? self::FIRST_PLACE_TIE_SEED : match ($values['firstPlaceTie']) {
                'playoff' => 'playoff',
                'shared' => 'shared',
                self::FIRST_PLACE_TIE_SEED => self::FIRST_PLACE_TIE_SEED,
                default => $defaults->firstPlaceTie,
            },
            games: $games,
            decider: $sequence && ($values['decider'] ?? null) === 'first' ? 'first' : 'cycle',
        );
    }

    /**
     * The sequence of a combined tournament ({@see $games}) from stored or submitted values. Options stored by #28 name
     * only a second game (`pairGame`): they read as the tournament's game, then that one. A game no longer offered
     * (switched off, another mode, not combinable) is dropped, the list is cut at the configured length, and a list
     * that does not start with the tournament's game, or keeps fewer than two games, is one game: an empty list.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private static function gameSequence(array $values, GameProfile $profile): array
    {
        $listed = match (true) {
            is_array($values['games'] ?? null) => array_values($values['games']),
            is_string($values['pairGame'] ?? null) && $values['pairGame'] !== $profile->game => [$profile->game, $values['pairGame']],
            default => [],
        };

        if (! self::combinable($profile->game)) {
            return [];
        }

        $offered = [$profile->game, ...self::pairGames($profile)];
        $games = array_values(array_filter($listed, fn (mixed $slug): bool => is_string($slug) && in_array($slug, $offered, true)));
        $games = self::oneOfEachGroup($games);
        $games = array_slice($games, 0, self::maxSequence());

        return count($games) >= 2 && $games[0] === $profile->game ? $games : [];
    }

    /**
     * The games a sequence may take one more of: the tournament's own and the ones it combines with ({@see pairGames()}),
     * without a game whose group already plays in the sequence as another member (Go 13×13 beside Go 9×9).
     *
     * @param  list<string>  $sequence
     * @return list<string>
     */
    public static function addable(GameProfile $profile, array $sequence): array
    {
        $offered = [$profile->game, ...self::pairGames($profile)];

        return array_values(array_filter($offered, fn (string $slug): bool => self::oneOfEachGroup([...$sequence, $slug]) === [...$sequence, $slug]));
    }

    /**
     * A sequence with one member of each group at most: a game may repeat, but a second member of a group whose
     * other member already plays (two sizes of Go) is dropped.
     *
     * @param  list<string>  $games
     * @return list<string>
     */
    private static function oneOfEachGroup(array $games): array
    {
        $registry = app(GameRegistry::class);
        $taken = [];

        return array_values(array_filter($games, function (string $slug) use ($registry, &$taken): bool {
            $group = $registry->groupOf($slug)?->slug;

            if ($group === null) {
                return true;
            }

            $taken[$group] ??= $slug;

            return $taken[$group] === $slug;
        }));
    }

    /** The most games a sequence plays before its deciders (`esports.tournaments.sequence_max_games`), never fewer than two. */
    public static function maxSequence(): int
    {
        return max(2, (int) config('esports.tournaments.sequence_max_games', 5));
    }

    /**
     * The games a tournament of this game and mode can be combined with ({@see $pairGame}; DerCaddy, 2026-11-11: "Cooler
     * wäre ja, wenn man jedes Spiel mit jedem Spiel kombinieren könnte"): every other switched-on game played one against
     * one on the league server with a result of win, loss or draw ({@see combinable()}: chess and the board games) that
     * has the same mode, in the league's order. Series, lobbies and score games combine with nothing, and a game never
     * combines with another of its own group (two sizes of Go).
     *
     * @return list<string>
     */
    public static function pairGames(GameProfile $profile): array
    {
        if (! self::combinable($profile->game)) {
            return [];
        }

        $registry = app(GameRegistry::class);

        return array_values(array_filter(
            array_map(fn ($game): string => $game->slug(), $registry->all()),
            // Never a game of the same group (GameRegistry::covered()): Go 9×9 with Go 13×13 is one game twice.
            fn (string $slug): bool => ! in_array($slug, $registry->covered($profile->game), true) && self::combinable($slug) && $registry->mode($slug, $profile->mode) !== null,
        ));
    }

    /** A game a combined tournament can play ({@see pairGames()}): chess or a board game, both played here, one against one. */
    public static function combinable(string $slug): bool
    {
        $game = app(GameRegistry::class)->find($slug);

        return $game instanceof Chess || $game instanceof BoardGameDefinition;
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
            $this->legs, $this->legPoints, $this->firstPlaceTie, $this->games, $this->decider);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes, GameProfile $profile): self
    {
        return self::fromArray([...$this->toArray(), ...$changes], $profile);
    }

    /**
     * @return array{bestOf: int, finalBestOf: int, thirdPlace: bool, grandFinal: string, split: bool, iterations: int, rankBy: string, swissRounds: int|null, pointsWin: float, pointsTie: float, pointsBye: float, swissTieBreaks: list<string>, roundRobinTieBreaks: list<string>, groupSize: int, advance: int, groupStage: string, finalStage: string, heatSize: int, heatAdvance: int, lobbyMinutes: int, teamSize: int, legs: int, legPoints: bool, firstPlaceTie: string, games: list<string>, decider: string}
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
            'games' => $this->games,
            'decider' => $this->decider,
        ];
    }
}
