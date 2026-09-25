<?php

namespace App\Support\SeasonChain;

/**
 * A mined block. Its height, era and reward are fixed at attestation and
 * never change; a void (season review) keeps the block and its height.
 */
final class Block
{
    public function __construct(
        public readonly int $height,
        public readonly Candidate $candidate,
        public readonly int $era,
        public readonly int $rewardPerPlayer,
        public readonly int $reward,
    ) {}
}
