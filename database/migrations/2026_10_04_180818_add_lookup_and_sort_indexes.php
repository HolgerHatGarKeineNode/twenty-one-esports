<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes the shell and home read on every page (performance plan P2,
     * finding S4). PostgreSQL does not index a foreign key by itself, so
     * "the lineups of this player", "the clan's members" and "the clans this
     * player owns" scanned their tables on every logged-in page.
     *
     * Plain CREATE INDEX, not CONCURRENTLY: every table here had fewer than
     * 6 000 rows on production (measured 2026-10-04), so the build takes
     * milliseconds and the short write lock is no concern.
     *
     * - lineup_seats(user_id, lineup_id): OpenMatches::lineupsOf() and the
     *   shell's last-played join; the unique (lineup_id, user_id) leads with
     *   the lineup and does not serve a lookup by player.
     * - series_reports(series_match_id, id): SeriesMatch::latestReport() (max id per series).
     * - clan_members(clan_id): a clan's members; only user_id was unique.
     * - clans(owner_id): the clans a player owns (lineupsOf(), captaincy).
     * - chess_games(status, updated_at, id) and series_matches(finished_at, id):
     *   the newest results on home (HomeHub::results()).
     * - notifications(notifiable_type, notifiable_id, created_at): the bell lists
     *   a player's newest first; the morph index has no created_at.
     */
    public function up(): void
    {
        Schema::table('lineup_seats', function (Blueprint $table) {
            $table->index(['user_id', 'lineup_id']);
        });

        Schema::table('series_reports', function (Blueprint $table) {
            $table->index(['series_match_id', 'id']);
        });

        Schema::table('clan_members', function (Blueprint $table) {
            $table->index('clan_id');
        });

        Schema::table('clans', function (Blueprint $table) {
            $table->index('owner_id');
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->index(['status', 'updated_at', 'id']);
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->index(['finished_at', 'id']);
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->index(['notifiable_type', 'notifiable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['notifiable_type', 'notifiable_id', 'created_at']);
        });

        Schema::table('series_matches', function (Blueprint $table) {
            $table->dropIndex(['finished_at', 'id']);
        });

        Schema::table('chess_games', function (Blueprint $table) {
            $table->dropIndex(['status', 'updated_at', 'id']);
        });

        Schema::table('clans', function (Blueprint $table) {
            $table->dropIndex(['owner_id']);
        });

        Schema::table('clan_members', function (Blueprint $table) {
            $table->dropIndex(['clan_id']);
        });

        Schema::table('series_reports', function (Blueprint $table) {
            $table->dropIndex(['series_match_id', 'id']);
        });

        Schema::table('lineup_seats', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'lineup_id']);
        });
    }
};
