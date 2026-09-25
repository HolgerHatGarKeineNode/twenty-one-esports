<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4b: a clan invite is into the roster only (NIP rev. 6). The membership is
 * the consent to be placed in lineups, so a lineup seat no longer waits for
 * the player:
 *
 *  - an open seat of a player who is already a member of the lineup's clan
 *    becomes a seat (their membership is the consent);
 *  - an open seat of anyone else goes away; their invite stays open and now
 *    brings them into the roster only;
 *  - an open invite of a player who is already in that clan has nothing left
 *    to do and is withdrawn;
 *  - invites lose their lineup and role.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $memberSeats = DB::table('lineup_seats')
            ->whereNull('lineup_seats.accepted_at')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('lineups')
                ->join('clan_members', 'clan_members.clan_id', '=', 'lineups.clan_id')
                ->whereColumn('lineups.id', 'lineup_seats.lineup_id')
                ->whereColumn('clan_members.user_id', 'lineup_seats.user_id'))
            ->pluck('id');

        DB::table('lineup_seats')->whereIn('id', $memberSeats)->update(['accepted_at' => $now, 'updated_at' => $now]);
        DB::table('lineup_seats')->whereNull('accepted_at')->delete();

        DB::table('clan_invites')
            ->where('status', 'pending')
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('clan_members')
                ->whereColumn('clan_members.clan_id', 'clan_invites.clan_id')
                ->whereColumn('clan_members.user_id', 'clan_invites.invitee_id'))
            ->update(['status' => 'withdrawn', 'responded_at' => $now, 'updated_at' => $now]);

        Schema::table('clan_invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lineup_id');
            $table->dropColumn('role');
        });
    }

    /**
     * The columns come back empty: which lineup an old invite was for is gone.
     */
    public function down(): void
    {
        Schema::table('clan_invites', function (Blueprint $table) {
            $table->foreignId('lineup_id')->nullable()->after('clan_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->nullable()->after('invitee_id');
        });
    }
};
