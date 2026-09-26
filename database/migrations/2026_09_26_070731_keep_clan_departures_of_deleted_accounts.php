<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A deleted account no longer takes its clan departures with it (P7e):
     * the own-clan guard of the trust admin reads the leaver's pubkey from
     * the row, so the row stays with `user_id` null and the pubkey on it.
     */
    public function up(): void
    {
        Schema::table('clan_departures', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::table('clan_departures', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('pubkey', 64)->nullable()->after('user_id');
            $table->index('pubkey');
        });

        foreach (DB::table('users')->whereIn('id', DB::table('clan_departures')->select('user_id'))->get(['id', 'pubkey']) as $user) {
            DB::table('clan_departures')->where('user_id', $user->id)->update(['pubkey' => $user->pubkey]);
        }
    }

    /**
     * Back to the cascading, NOT NULL `user_id`. Departures of deleted
     * accounts cannot go back without deleting them, so the rollback refuses
     * while any exist instead of dropping the guard's history.
     */
    public function down(): void
    {
        $orphans = DB::table('clan_departures')->whereNull('user_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException("Cannot roll back: {$orphans} clan departure(s) belong to a deleted account (user_id is NULL). The old schema would delete them; decide about these rows first.");
        }

        Schema::table('clan_departures', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['pubkey']);
            $table->dropColumn('pubkey');
        });

        Schema::table('clan_departures', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
