<?php

use App\Enums\TournamentStatus;
use App\Models\Tournament;
use App\Support\Tournaments\CasualCups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Data only: the Age of Empires II casual cups opened (or switched, P10)
 * with all 40 places now open small and grow with their sign-ups (user
 * 2026-10-01: "40 Sitze? Warum so viel?"). Each one still in sign-up
 * shrinks to the first lobby cup size above its sign-ups (1 in: 4, 9 in: 16)
 * and publishes a new version of its calendar event
 * (CasualCups::fitLobbyCapacity()). A cup whose event cannot be republished
 * keeps its places and is logged; the others go on. Idempotent; nothing to
 * undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cups = app(CasualCups::class);

        foreach (Tournament::query()->whereNotNull('cup_series')->where('status', TournamentStatus::Signup)->orderBy('id')->get() as $cup) {
            try {
                if ($cups->fitLobbyCapacity($cup)) {
                    Log::info('Lobby cup shrunk to its sign-ups', ['id' => $cup->id, 'name' => $cup->name, 'from' => $cup->capacity, 'to' => $cup->refresh()->capacity]);
                }
            } catch (Throwable $e) {
                report($e);
                Log::error('Lobby cup not shrunk: the calendar event was not republished', ['id' => $cup->id, 'error' => $e->getMessage()]);
            }
        }
    }

    public function down(): void {}
};
