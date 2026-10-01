<?php

use App\Support\Stacker\StackerRuns;
use Illuminate\Database\Migrations\Migration;

/**
 * Blockfill (plan "Blockfill", P5 follow-up): a run is held for a check
 * only when it carries cheat hints and would place in its week's first ten,
 * and one tick with more than 3 key presses is no hint on its own
 * (resources/js/stacker/hints.js). The runs held under the older rules go
 * through the new ones once (StackerRuns::releaseHeld(), also
 * `blockfill:release-held`): released, or held with their new hints. A run
 * whose replay the verifier cannot read now stays held, as before.
 *
 * Not reversible: a released run counts on its week's board.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(StackerRuns::class)->releaseHeld(now());
    }

    public function down(): void
    {
        //
    }
};
