<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's personal invite link to a tournament (P47, type
     * `tournament`): one per player and tournament, so the "I'm in" post and
     * the invite DM always carry the same link (the unique pair turns a
     * second request into a lookup). Opening it shows the tournament page;
     * the referral is credited when the invited player signs up.
     */
    public function up(): void
    {
        Schema::table('invite_links', function (Blueprint $table) {
            $table->foreignId('tournament_id')->nullable()->after('clan_id')->constrained()->cascadeOnDelete();
            $table->unique(['inviter_id', 'tournament_id']);
        });
    }

    public function down(): void
    {
        Schema::table('invite_links', function (Blueprint $table) {
            $table->dropUnique(['inviter_id', 'tournament_id']);
            $table->dropConstrainedForeignId('tournament_id');
        });
    }
};
