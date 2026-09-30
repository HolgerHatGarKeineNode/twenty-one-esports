<?php

use App\Support\StreamBot\TournamentNotes;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Data only: the stream bot's notes of the cups that moved to their game's
 * slot (2026_09_30_223953) still name the old start (Sat 3 Oct 20:00).
 * Every note of a tournament in sign-up whose text lacks the current start
 * gets a NIP-09 deletion and a fresh note in its bot_posts row
 * (TournamentNotes::correctStaleStarts(), the same step every scheduled run
 * takes). No DM, no other notice. Fails loud: a stale note without the bot's
 * key or relays, or a deletion or new note no relay took, stops the deploy;
 * running it again is safe (a corrected note is no longer stale).
 * Idempotent; nothing to undo.
 */
return new class extends Migration
{
    /**
     * Relays cannot be rolled back: a failure after some notes went out must
     * keep their rows, or a second run would post those notes twice.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        foreach (app(TournamentNotes::class)->correctStaleStarts(CarbonImmutable::now()) as $line) {
            Log::info('Stream bot note start corrected: '.$line);
        }
    }

    public function down(): void {}
};
