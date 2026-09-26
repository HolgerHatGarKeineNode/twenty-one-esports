<?php

namespace App\Support\Tournaments\Engine;

/**
 * One side of a bracket match: a known entrant, or where the entrant comes
 * from once earlier results are in (the winner or loser of a match, a place
 * in a heat, a place in a group).
 */
final readonly class Slot
{
    /**
     * @param  'entrant'|'winner'|'loser'|'rank'|'group-rank'  $take
     */
    private function __construct(
        public string $take,
        public ?int $entrant = null,
        public ?string $match = null,
        public ?int $group = null,
        public int $rank = 1,
    ) {}

    public static function entrant(int $entrant): self
    {
        return new self('entrant', entrant: $entrant);
    }

    public static function winnerOf(string $match): self
    {
        return new self('winner', match: $match);
    }

    public static function loserOf(string $match): self
    {
        return new self('loser', match: $match);
    }

    /** Place `$rank` (1 = best) of a match with more than two sides (a heat). */
    public static function rankOf(string $match, int $rank): self
    {
        return new self('rank', match: $match, rank: $rank);
    }

    /** Place `$rank` of group `$group` once every match of the group is in. */
    public static function groupRank(int $group, int $rank): self
    {
        return new self('group-rank', group: $group, rank: $rank);
    }

    public function isKnown(): bool
    {
        return $this->take === 'entrant';
    }

    /**
     * @return array{take: string, entrant?: int, match?: string, group?: int, rank?: int}
     */
    public function toArray(): array
    {
        return match ($this->take) {
            'entrant' => ['take' => 'entrant', 'entrant' => (int) $this->entrant],
            'winner', 'loser' => ['take' => $this->take, 'match' => (string) $this->match],
            'rank' => ['take' => 'rank', 'match' => (string) $this->match, 'rank' => $this->rank],
            default => ['take' => 'group-rank', 'group' => (int) $this->group, 'rank' => $this->rank],
        };
    }

    /**
     * @param  array{take: string, entrant?: int, match?: string, group?: int, rank?: int}  $value
     */
    public static function fromArray(array $value): self
    {
        return match ($value['take']) {
            'entrant' => self::entrant((int) ($value['entrant'] ?? 0)),
            'winner' => self::winnerOf((string) ($value['match'] ?? '')),
            'loser' => self::loserOf((string) ($value['match'] ?? '')),
            'rank' => self::rankOf((string) ($value['match'] ?? ''), (int) ($value['rank'] ?? 1)),
            default => self::groupRank((int) ($value['group'] ?? 0), (int) ($value['rank'] ?? 1)),
        };
    }
}
