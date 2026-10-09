<?php

namespace App\Support\Tournaments;

use App\Games\BoardGame;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Planning values of one game and mode for the tournament estimator
 * (TOURNAMENT-FORMATS.md, section 2; `TF_GAMES` of the artboard script).
 * Every value is an assumption until measured on real tournaments; the
 * organizer can change game, setup and break per tournament ("Change times").
 *
 * Times are in the profile's unit: minutes, or days for daily chess.
 *
 * Online a match needs more than its games (P18, user decision 2026-09-27):
 * `overhead` is the time per match for finding the opponent, the friend
 * request, the lobby and the report; `longPlay` stretches a game to its
 * longest (EA Sports FC extra time and penalties, Rocket League overtime).
 * Both are unmeasured assumptions: FC 10 min and 1.3, RL 5 min and 1.25,
 * AoE2 10 min and 1.5 (a game has no clock and runs long far more often
 * than it ends early), blitz and rapid 3 min and 1.0 (the clock bounds a game),
 * daily chess 0 and 1.0.
 */
final readonly class GameProfile
{
    /**
     * @param  string  $key  opaque id of the profile in the chooser (`blitz`, `rl3`, `ea-sports-fc-27/1v1`)
     * @param  'min'|'day'  $unit
     * @param  list<int>  $bestOfOptions
     * @param  'game'|'series'|'score'|'hyper'|'none'  $what
     */
    public function __construct(
        public string $key,
        public string $game,
        public string $mode,
        public string $unit,
        public float $gameLength,
        public float $setup,
        public float $break,
        public int $bestOf,
        public int $finalBestOf,
        public array $bestOfOptions,
        public bool $allAtOnce,
        public string $what,
        public int $teamSize = 1,
        public float $overhead = 0.0,
        public float $longPlay = 1.0,
    ) {}

    /**
     * The league defaults of a registered game and mode.
     */
    public static function for(string $game, string $mode): self
    {
        return match ("{$game}/{$mode}") {
            // Only what a tournament match can be played as (P8b DoD gate): one chess game per match
            // (no 2-game match yet), and the series lengths the game registry allows (Bo3, Bo5).
            'chess/blitz' => new self('blitz', $game, $mode, 'min', 14, 0, 3, 1, 1, [1], false, 'game', overhead: 3),
            // Rapid 10+5 (plan "Schach Rapid und Clan", P1): 2 × (10 min + 40 moves × 5 s) ≈ 27 min a game, unmeasured.
            'chess/rapid' => new self('rapid', $game, $mode, 'min', 27, 0, 3, 1, 1, [1], false, 'game', overhead: 3),
            'chess/correspondence' => new self('daily', $game, $mode, 'day', 30, 0, 1, 1, 1, [1], true, 'game'),
            // Board games (plan "Mühle und Dame", P5): one blitz game 5+3 per match, planned as blitz chess. No game offers
            // blitz since 2026-10-07 (user: correspondence only); kept so tournaments made before still plan and render.
            'nine-mens-morris/blitz', 'checkers/blitz' => new self("{$game}/{$mode}", $game, $mode, 'min', 14, 0, 3, 1, 1, [1], false, 'game', overhead: 3),
            // Blockli plays each pairing twice with the colours swapped (plan "Blockli", P4): two games a slot.
            'blockli/blitz' => new self("{$game}/{$mode}", $game, $mode, 'min', 14, 0, 3, 2, 2, [2], false, 'game', overhead: 3),
            // Their correspondence mode (P8): one game per match, planned as daily chess.
            'nine-mens-morris/correspondence', 'checkers/correspondence', 'blockli/correspondence' => new self("{$game}/{$mode}", $game, $mode, 'day', 30, 0, 1, 1, 1, [1], true, 'game'),
            'rocket-league/1v1' => new self('rl1', $game, $mode, 'min', 8, 5, 5, 3, 5, [3, 5], false, 'series', 1, 5, 1.25),
            'rocket-league/2v2' => new self('rl2', $game, $mode, 'min', 8, 5, 5, 3, 5, [3, 5], false, 'series', 2, 5, 1.25),
            'rocket-league/3v3' => new self('rl3', $game, $mode, 'min', 8, 5, 5, 3, 5, [3, 5], false, 'series', 3, 5, 1.25),
            // EA Sports FC: planned at about 15 min a game (league default, not measured yet), Bo1 rounds and a Bo3 final.
            'ea-sports-fc-26/1v1', 'ea-sports-fc-27/1v1' => new self("{$game}/{$mode}", $game, $mode, 'min', 15, 5, 5, 1, 3, [1, 3], false, 'series', 1, 10, 1.3),
            'ea-sports-fc-26/2v2', 'ea-sports-fc-27/2v2' => new self("{$game}/{$mode}", $game, $mode, 'min', 15, 5, 5, 1, 3, [1, 3], false, 'series', 2, 10, 1.3),
            // Age of Empires II: planned at about 21 min a game (the median of a small sample, plan "AoE2 und Trackmania"), Bo1 rounds and a Bo3 final.
            'age-of-empires-2/1v1', 'age-of-empires-2/2v2', 'age-of-empires-2/3v3' => new self("{$game}/{$mode}", $game, $mode, 'min', 21, 5, 5, 1, 3, [1, 3], false, 'series', (int) $mode[0], 10, 1.5),
            // Hyperbitcoinization (plan "Hyperbitcoinization", P5): one match per table, the league starts it. Live planned at
            // 60 min (about 18 rounds of 90 s turns at 4 seats, simulator mean 17.6 rounds; unmeasured with people), 5 min to
            // find the tab; correspondence like daily chess, a turn a day.
            'hyperbitcoinization/live' => new self("{$game}/{$mode}", $game, $mode, 'min', 60, 0, 5, 1, 1, [1], false, 'hyper', overhead: 5, longPlay: 1.5),
            'hyperbitcoinization/correspondence' => new self("{$game}/{$mode}", $game, $mode, 'day', 30, 0, 1, 1, 1, [1], true, 'hyper'),
            default => self::score($game, $mode) ?? throw new InvalidArgumentException("No tournament profile for [{$game}/{$mode}]."),
        };
    }

    /**
     * The profile of a stored tournament's game and mode: the league
     * default, or for a game no longer registered (a score or board game
     * switched off) a stand-in that plans nothing: no duration, no estimate,
     * no wait, and nothing starts ({@see isUnknown()}). Pages that list or
     * show such a tournament keep working instead of failing. The stand-in
     * is only for a game the registry does not know (a mode missing from a
     * registered game is a defect and still throws), kept for the request,
     * and logged as one line at most once a day per game and mode (round-4
     * F2: a report per call wrote ~250 KB per guest page view).
     */
    public static function ofTournament(string $game, string $mode): self
    {
        try {
            return self::for($game, $mode);
        } catch (InvalidArgumentException $e) {
            if (app(GameRegistry::class)->find($game) !== null) {
                throw $e;
            }

            return once(function () use ($game, $mode): self {
                if (Cache::add("game-profile:stand-in:{$game}/{$mode}", true, now()->addDay())) {
                    Log::warning("No tournament profile for [{$game}/{$mode}]: the game is not registered, so its tournaments use the stand-in that plans nothing.");
                }

                return new self("{$game}/{$mode}", $game, $mode, 'min', 0, 0, 0, 1, 1, [1], false, 'none');
            });
        }
    }

    /**
     * A score game's mode (plan "AoE2 und Trackmania", P4): one leaderboard
     * whose single "game" is the submission window, in days (the game's
     * default window; the organizer's "Change times" sets another). Everyone
     * plays at once, nothing is set up between rounds, there is no break.
     */
    private static function score(string $game, string $mode): ?self
    {
        $score = app(GameRegistry::class)->find($game);

        if (! $score instanceof ScoreGame || $score->mode($mode) === null) {
            return null;
        }

        return new self("{$game}/{$mode}", $game, $mode, 'day', $score->defaultWindowMinutes() / 1440, 0, 0, 1, 1, [1], true, 'score');
    }

    /**
     * The same profile with the organizer's own times; null keeps the default.
     */
    public function withTimes(?float $gameLength = null, ?float $setup = null, ?float $break = null): self
    {
        return new self($this->key, $this->game, $this->mode, $this->unit,
            $gameLength ?? $this->gameLength, $setup ?? $this->setup, $break ?? $this->break,
            $this->bestOf, $this->finalBestOf, $this->bestOfOptions, $this->allAtOnce, $this->what, $this->teamSize, $this->overhead, $this->longPlay);
    }

    public function isChess(): bool
    {
        return $this->game === 'chess';
    }

    /**
     * A match is one game on a board game other than chess, played on the
     * board game core (nine men's morris, checkers; plan "Mühle und Dame",
     * P5): neither chess nor a series.
     */
    public function isBoard(): bool
    {
        return in_array($this->game, BoardGame::RESERVED_SLUGS, true);
    }

    /**
     * A match is a Hyperbitcoinization match (plan "Hyperbitcoinization", P5): a free-for-all table of 3 to 6
     * players or a 1v1, started and decided on the league's server (HyperMatches), never a two-sided game record.
     */
    public function isHyper(): bool
    {
        return $this->what === 'hyper';
    }

    /**
     * A leaderboard of a score game (plan "AoE2 und Trackmania", P4): no
     * match between sides, every entry's best value in the window ranks it.
     */
    /**
     * The stand-in of a game no longer registered ({@see ofTournament()}).
     */
    public function isUnknown(): bool
    {
        return $this->what === 'none';
    }

    public function isScore(): bool
    {
        return $this->what === 'score';
    }

    /**
     * A match is a best-of series between two sides (Rocket League, EA Sports FC, Age of Empires II).
     */
    public function isSeries(): bool
    {
        return $this->what === 'series';
    }

    public function isDaily(): bool
    {
        return $this->unit === 'day';
    }

    /**
     * The same profile with another team size: a Hyperbitcoinization clan bracket (plan "Hyperbitcoinization",
     * P5b) plays 2v2 or 3v3 in the tournament's mode (live or correspondence), set by its options, not its mode.
     */
    public function withTeamSize(int $teamSize): self
    {
        return new self($this->key, $this->game, $this->mode, $this->unit, $this->gameLength, $this->setup, $this->break,
            $this->bestOf, $this->finalBestOf, $this->bestOfOptions, $this->allAtOnce, $this->what, max(1, $teamSize), $this->overhead, $this->longPlay);
    }

    /**
     * Series modes with more than one player per side enter teams, and a Hyperbitcoinization clan bracket (P5b);
     * everything else single players.
     */
    public function entersTeams(): bool
    {
        return ($this->isSeries() || $this->isHyper()) && $this->teamSize > 1;
    }

    /**
     * Time one match needs, worst case: a round waits for its slowest match.
     * Daily chess plays both games of a 2-game match at the same time.
     */
    public function slot(int $bestOf): float
    {
        if ($this->allAtOnce) {
            return $this->gameLength;
        }

        return $this->setup + $bestOf * $this->gameLength;
    }

    /**
     * Time one match needs online, typically: the slot plus the overhead of
     * meeting the opponent and reporting.
     */
    public function onlineSlot(int $bestOf): float
    {
        return $this->slot($bestOf) + $this->overhead;
    }

    /**
     * The longest a match plays once both sides are there: every game to its
     * longest (extra time, overtime), plus the setup.
     */
    public function longestPlay(int $bestOf): float
    {
        return $this->allAtOnce ? $this->gameLength * $this->longPlay : $this->setup + $bestOf * $this->gameLength * $this->longPlay;
    }
}
