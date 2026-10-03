<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Tournaments\TournamentDraws;
use App\Support\Tournaments\TournamentModeration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Entries left behind by deleted accounts (prod 2026-10-03: tournament 1
 * sat in "Draw pending" because its draw broke on the foreign key of a
 * deleted player's solo entry). In every tournament still in sign-up or
 * waiting for its draw, an entry whose players have no account left is
 * withdrawn, and a lineup drops the players gone while it still fields a
 * team; each with a league line in the moderation log
 * (TournamentModeration::withdrawOrphaned()). Running it twice changes
 * nothing. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $moderation = app(TournamentModeration::class);
        $withdrawn = [];

        foreach (Tournament::query()->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing])->pluck('id') as $id) {
            $count = DB::transaction(fn (): int => $moderation->withdrawOrphaned(Tournament::query()->lockForUpdate()->findOrFail((int) $id), TournamentDraws::ORPHAN_REASON));

            if ($count > 0) {
                $withdrawn[$id] = $count;
            }
        }

        if ($withdrawn !== []) {
            Log::info('Tournaments: entries of deleted accounts withdrawn', ['withdrawn' => $withdrawn]);
        }
    }

    public function down(): void
    {
        // The withdrawn entries are listed in their moderation logs; their players have no account to return to.
    }
};
