<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One running casual 1v1 per player, held by the database: a queue or
     * invite match claims both players in the transaction that creates it
     * (CasualMatches::create()). The unique user id refuses a second match
     * that passed its busy check before the first one committed; a claim of
     * a match that stopped running is taken over by the next one.
     */
    public function up(): void
    {
        Schema::create('casual_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('series_match_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('casual_claims');
    }
};
