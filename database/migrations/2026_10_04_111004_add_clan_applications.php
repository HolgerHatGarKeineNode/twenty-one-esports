<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clan applications (plan "Clan-Bewerbungen", P2): a player applies to a
     * clan without a link, with a short form. An application is a join
     * request of origin `application`; the requests through a clan link keep
     * `link`.
     *
     * clans.applications_open: the clan's "Applications open" switch, on for
     * every clan (user 2026-10-04: „per default ist Bewerbung jetzt zu Beginn
     * für alle Clans an“), existing clans included.
     */
    public function up(): void
    {
        Schema::table('clans', function (Blueprint $table) {
            $table->boolean('applications_open')->default(true);
        });

        DB::table('clans')->update(['applications_open' => true]);

        Schema::table('clan_join_requests', function (Blueprint $table) {
            $table->string('origin', 16)->default('link');
            $table->json('games')->nullable();
            $table->json('platforms')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('message', 280)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clan_join_requests', function (Blueprint $table) {
            $table->dropColumn(['origin', 'games', 'platforms', 'timezone', 'message']);
        });

        Schema::table('clans', function (Blueprint $table) {
            $table->dropColumn('applications_open');
        });
    }
};
