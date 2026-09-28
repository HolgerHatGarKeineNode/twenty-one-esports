<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agreed start of a casual cup's series match (P25 S3,
 * App\Support\Tournaments\CupSchedules): who proposed which one to three
 * times until when, and the time the other side accepted. The league
 * starts the match's series for that time (check-in, then the casual
 * flow). Additive: null for every other match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->json('schedule')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_matches', function (Blueprint $table) {
            $table->dropColumn('schedule');
        });
    }
};
