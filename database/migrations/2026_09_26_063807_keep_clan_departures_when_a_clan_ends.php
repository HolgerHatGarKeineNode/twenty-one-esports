<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A clan that ends no longer takes its departures with it (P7d gate,
     * Low A): the own-clan guard of the trust admin reads them for the whole
     * season, so the rows stay, keyed by the old clan id, and carry the
     * clan's address and name for when the clan row is gone.
     */
    public function up(): void
    {
        Schema::table('clan_departures', function (Blueprint $table) {
            $table->dropForeign(['clan_id']);
        });

        Schema::table('clan_departures', function (Blueprint $table) {
            $table->index('clan_id');
            $table->string('clan_address')->nullable()->after('clan_id');
            $table->string('clan_name')->nullable()->after('clan_address');
        });

        foreach (DB::table('clans')->get(['id', 'owner_pubkey', 'slug', 'name']) as $clan) {
            DB::table('clan_departures')->where('clan_id', $clan->id)->update([
                'clan_address' => '32150:'.$clan->owner_pubkey.':'.$clan->slug,
                'clan_name' => $clan->name,
            ]);
        }
    }

    /**
     * Back to the cascading foreign key. Departures of clans that have ended
     * cannot go back without deleting them, so the rollback refuses while
     * any exist instead of dropping the guard's history.
     */
    public function down(): void
    {
        $orphans = DB::table('clan_departures')->whereNotIn('clan_id', DB::table('clans')->select('id'))->count();

        if ($orphans > 0) {
            throw new RuntimeException("Cannot roll back: {$orphans} clan departure(s) belong to a clan that has ended. The old schema would delete them; decide about these rows first.");
        }

        Schema::table('clan_departures', function (Blueprint $table) {
            $table->dropIndex(['clan_id']);
            $table->dropColumn(['clan_address', 'clan_name']);
            $table->foreign('clan_id')->references('id')->on('clans')->cascadeOnDelete();
        });
    }
};
