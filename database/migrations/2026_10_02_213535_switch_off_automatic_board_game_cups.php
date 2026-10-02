<?php

use App\Support\SeasonChain\LeagueKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * No new automatic casual cups for Nine Men's Morris and Checkers (user
 * 2026-10-03: „schalte für die beiden Games die Auto Casual Cups ab! Die
 * Altbestände kannst noch lassen, aber keine kommenden mehr."): one row
 * per game in the league settings log, "off", as an admin's change on
 * /admin/settings would write it (LeagueSettings). Nothing is deleted:
 * cups in sign-up or running play to their end.
 *
 * Only on a database that already holds a league (any user): a fresh
 * install and the test database keep the env list as their default. A game
 * that already has a row keeps it: an admin's choice stands.
 */
return new class extends Migration
{
    private const GAMES = ['nine-mens-morris', 'checkers'];

    public function up(): void
    {
        if (! DB::table('users')->exists()) {
            return;
        }

        $enabled = array_map(strval(...), (array) config('esports.casual_cups.enabled', []));
        $pubkey = LeagueKey::fromConfig()?->pubkey() ?? str_repeat('0', 64);

        foreach (self::GAMES as $game) {
            $key = "esports.casual_cups.games.{$game}.auto";

            if (DB::table('league_setting_changes')->where('key', $key)->exists()) {
                continue;
            }

            DB::table('league_setting_changes')->insert([
                'key' => $key,
                'before' => json_encode(in_array($game, $enabled, true) ? 'on' : 'off'),
                'after' => json_encode('off'),
                'changed_by_id' => null,
                'changed_by_pubkey' => $pubkey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The log is append-only; an admin switches a game back on at /admin/settings.
    }
};
