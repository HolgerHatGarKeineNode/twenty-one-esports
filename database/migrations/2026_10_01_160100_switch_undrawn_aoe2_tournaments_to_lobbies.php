<?php

use App\Support\Tournaments\LobbySwitch;
use Illuminate\Database\Migrations\Migration;

/**
 * Data only: Age of Empires II tournaments are one lobby match (plan "AoE2
 * und Trackmania", P10, user 2026-10-01). Every AoE2 tournament that is not
 * drawn yet, open casual cups included, switches to Free for All with the
 * lobby options (a cup also to the lobby cup's places), its calendar event
 * republished; the stream bot replaces its note on its next run. Drawn
 * tournaments, other games and team modes keep their format
 * (LobbySwitch::run(), one log line each). Idempotent; nothing to undo.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(LobbySwitch::class)->run();
    }

    public function down(): void {}
};
