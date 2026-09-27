<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lobby and account cards of a casual 1v1 (P23, slice S2; NIP "Lobby and
 * account cards"):
 *
 * - `lobby_seen_at`: the guest's client opened a valid card from the host.
 *   Like `lobby_shared_at` only a flag: the card itself never reaches the
 *   server.
 * - `host_swapped_at`: the host could not share ("Can't share, swap host")
 *   and handed the host seat to the guest; the lobby deadline runs again
 *   from then, once per match.
 *
 * Additive: existing matches keep null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->timestamp('lobby_seen_at')->nullable();
            $table->timestamp('host_swapped_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropColumn(['lobby_seen_at', 'host_swapped_at']);
        });
    }
};
