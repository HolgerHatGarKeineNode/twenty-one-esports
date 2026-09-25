<?php

namespace App\Support\SeasonChain;

/**
 * Whether a candidate mines, and if not, the first rule it fails. The era and
 * the reward are fixed here, at attestation; for a failing candidate they say
 * what it would have paid.
 */
final class Verdict
{
    /**
     * @param  ?string  $reason  a stable code, e.g. `share-cap`; null when it mines
     * @param  ?string  $subject  who or what the reason names (a player, a clan, an anchor)
     */
    public function __construct(
        public readonly ?ConsensusRule $rule,
        public readonly ?string $reason,
        public readonly int $era,
        public readonly int $rewardPerPlayer,
        public readonly int $reward,
        public readonly ?string $subject = null,
    ) {}

    public static function block(int $era, int $rewardPerPlayer, int $reward): self
    {
        return new self(null, null, $era, $rewardPerPlayer, $reward);
    }

    public function mines(): bool
    {
        return $this->rule === null;
    }
}
