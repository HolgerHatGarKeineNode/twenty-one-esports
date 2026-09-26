<?php

namespace App\Support\Tournaments;

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
     * @param  'reset'|'single'|'skip'  $grandFinal
     * @param  'points'|'match-wins'|'game-wins'|'custom'  $rankBy
     * @param  int|null  $swissRounds  null = as many as fit the time (recommendation rule 2)
     * @param  list<string>  $swissTieBreaks
     * @param  list<string>  $roundRobinTieBreaks
     * @param  'round-robin'|'single-elimination'|'double-elimination'  $groupStage
     * @param  'single-elimination'|'double-elimination'  $finalStage
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
    ) {}

    public static function defaults(GameProfile $profile): self
    {
        return new self(bestOf: $profile->bestOf, finalBestOf: $profile->finalBestOf);
    }

    /**
     * Options from stored or submitted values; anything missing or out of
     * range falls back to the default of the game, so a stale draft never
     * breaks the chooser.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values, GameProfile $profile): self
    {
        $defaults = self::defaults($profile);
        $int = fn (string $key, int $default, int $min, int $max): int => is_numeric($values[$key] ?? null) && (int) $values[$key] >= $min && (int) $values[$key] <= $max ? (int) $values[$key] : $default;
        $float = fn (string $key, float $default): float => is_numeric($values[$key] ?? null) && (float) $values[$key] >= 0 && (float) $values[$key] <= 10 ? (float) $values[$key] : $default;
        $pick = fn (string $key, string $default, array $allowed): string => in_array($values[$key] ?? null, $allowed, true) ? (string) $values[$key] : $default;

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
            heatSize: $int('heatSize', $defaults->heatSize, 3, 16),
            heatAdvance: $int('heatAdvance', $defaults->heatAdvance, 1, 8),
        );
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
            $this->groupSize, $this->advance, $this->groupStage, $this->finalStage, $this->heatSize, $this->heatAdvance);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes, GameProfile $profile): self
    {
        return self::fromArray([...$this->toArray(), ...$changes], $profile);
    }

    /**
     * @return array{bestOf: int, finalBestOf: int, thirdPlace: bool, grandFinal: string, split: bool, iterations: int, rankBy: string, swissRounds: int|null, pointsWin: float, pointsTie: float, pointsBye: float, swissTieBreaks: list<string>, roundRobinTieBreaks: list<string>, groupSize: int, advance: int, groupStage: string, finalStage: string, heatSize: int, heatAdvance: int}
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
        ];
    }
}
