<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Tournaments\CasualCups;
use Illuminate\Database\Migrations\Migration;

/**
 * Data only: the casual cups opened before P27 started with every place
 * (16). Fit each open one to the growing sign-up (4 places, then 8, 16), and
 * publish a new version of its calendar event. Nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cups = app(CasualCups::class);

        Tournament::query()->whereNotNull('cup_series')->where('status', TournamentStatus::Signup)->with('event')->get()
            ->each(fn (Tournament $cup): bool => $cups->fitCapacity($cup));
    }

    public function down(): void {}
};
