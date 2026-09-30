<?php

use App\Support\Tournaments\CasualCups;
use Illuminate\Database\Migrations\Migration;

/**
 * Data only: the casual cups spread over the weekend, one slot per game
 * (user, 2026-09-30). Every open cup nobody signed up for moves to its
 * game's next slot that leaves the minimum sign-up, its calendar event
 * republished, nobody told; cups with a sign-up keep their start
 * (CasualCups::moveToGameSlots(), one log line per moved cup). Idempotent;
 * nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(CasualCups::class)->moveToGameSlots();
    }

    public function down(): void {}
};
