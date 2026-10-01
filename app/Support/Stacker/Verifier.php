<?php

namespace App\Support\Stacker;

use App\Models\StackerRun;

/**
 * Replays a submitted Blockfill run and says whether it finished at exactly
 * the claimed ticks and state hash. NodeVerifier runs the shared engine in
 * Node; the feature tests bind a fake.
 */
interface Verifier
{
    public function verify(StackerRun $run): StackerVerdict;
}
