<?php

namespace Tests\Support;

use App\Support\SeasonChain\TrustFacts;

/**
 * Every player trusted at rank 100, the gatekeepers connected, no anchor
 * subtree: consensus rules 1 and 7 pass, so the other rules decide.
 */
final class TrustedFacts implements TrustFacts
{
    public function at(array $players, array $gatekeepers): array
    {
        return ['trust' => array_fill_keys($players, 100), 'anchors' => [], 'connected' => true];
    }
}
