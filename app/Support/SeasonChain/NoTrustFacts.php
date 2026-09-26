<?php

namespace App\Support\SeasonChain;

/**
 * No trust data: every player is unranked and no gatekeepers are connected,
 * so consensus rule 1 fails closed for every candidate.
 */
final class NoTrustFacts implements TrustFacts
{
    public function at(array $players, array $gatekeepers): array
    {
        return ['trust' => [], 'anchors' => [], 'connected' => false];
    }
}
