<?php

namespace App\Support\Series;

/**
 * What a captain fills in on /challenges/create. Times are unix seconds.
 * `boards` is set for a chess team match (2 or 3, NIP rev. 9.22) and null
 * for a series, whose length is `bestOf`.
 */
final readonly class ChallengeDraft
{
    /**
     * @param  list<int>  $proposals  one to three suggested starts
     */
    public function __construct(
        public int $challengerLineupId,
        public int $challengedLineupId,
        public int $bestOf,
        public bool $rated,
        public array $proposals,
        public int $respondBy,
        public ?string $message = null,
        public ?int $boards = null,
    ) {}
}
