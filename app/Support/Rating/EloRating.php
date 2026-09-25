<?php

namespace App\Support\Rating;

use InvalidArgumentException;

/**
 * Elo as the ladder's `rating` and `provisional` tags define it
 * (docs/nips/esports.md, "Rating"): each side has its own k-factor, the
 * provisional one while it has fewer rated results than `provisional`
 * (counted before this result); deltas are rounded halves away from zero.
 */
final class EloRating
{
    public function __construct(
        public readonly int $start = 1000,
        public readonly int $k = 32,
        public readonly int $provisionalK = 40,
        public readonly int $provisional = 5,
        public readonly int $scale = 400,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('season.rating.start'),
            (int) config('season.rating.k'),
            (int) config('season.rating.provisional_k'),
            (int) config('season.rating.provisional'),
            (int) config('season.rating.scale'),
        );
    }

    /** E = 1 / (1 + 10^((Rd - Rc) / scale)) for the side rated $rating. */
    public function expectedScore(int $rating, int $opponentRating): float
    {
        return 1.0 / (1.0 + 10 ** (($opponentRating - $rating) / $this->scale));
    }

    /** The k-factor of a side with $ratedResults earlier rated results. */
    public function kFactor(int $ratedResults): int
    {
        return $ratedResults < $this->provisional ? $this->provisionalK : $this->k;
    }

    /**
     * One rated result. $score is the challenger's: 1 win, 0.5 draw, 0 loss.
     *
     * @return array{challenger: int, challenged: int, challenger_delta: int, challenged_delta: int}
     */
    public function rate(int $challenger, int $challenged, float $score, int $challengerResults, int $challengedResults): array
    {
        if (! in_array($score, [0.0, 0.5, 1.0], true)) {
            throw new InvalidArgumentException('The score is 1, 0.5 or 0.');
        }

        $surprise = $score - $this->expectedScore($challenger, $challenged);
        $challengerDelta = NipMath::round($this->kFactor($challengerResults) * $surprise);
        $challengedDelta = NipMath::round($this->kFactor($challengedResults) * $surprise);

        return [
            'challenger' => $challenger + $challengerDelta,
            'challenged' => $challenged - $challengedDelta,
            'challenger_delta' => $challengerDelta,
            'challenged_delta' => -$challengedDelta,
        ];
    }
}
