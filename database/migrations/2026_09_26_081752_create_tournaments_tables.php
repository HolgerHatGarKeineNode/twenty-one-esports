<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tournaments (P8a): the tournament with its format and options, the
     * organizers an admin unlocks, the named tournament directors, the
     * participants, and a generic bracket (stages → rounds → matches → slots)
     * every format fits: a slot holds a participant or where one comes from
     * (winner or loser of a match, a heat place, a group place).
     *
     * Tournament matches never mine season-chain blocks; their pot is their
     * own (P9). Nothing here is on Nostr yet: the NIP-52 calendar event
     * (31923) is published when a tournament opens sign-up (P8b).
     */
    public function up(): void
    {
        Schema::create('tournament_organizers', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->string('added_by_pubkey', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->string('format', 32);
            $table->json('options');
            $table->unsignedSmallInteger('capacity');
            $table->timestamp('starts_at');
            $table->unsignedInteger('time_window');
            $table->boolean('on_site')->default(false);
            $table->unsignedSmallInteger('stations')->nullable();
            $table->json('times')->nullable();
            $table->string('results_mode', 16)->default('players');
            $table->string('status', 16)->default('draft')->index();
            $table->string('seed', 128)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('tournament_directors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tournament_id', 'user_id']);
        });

        Schema::create('tournament_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lineup_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->integer('rating')->default(1000);
            $table->unsignedSmallInteger('seed')->nullable();
            $table->unsignedSmallInteger('group')->nullable();
            $table->timestamps();
            $table->index(['tournament_id', 'seed']);
        });

        Schema::create('tournament_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('number');
            $table->string('format', 32);
            $table->string('status', 16)->default('pending');
            $table->timestamps();
            $table->unique(['tournament_id', 'number']);
        });

        Schema::create('tournament_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_stage_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('status', 16)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tournament_stage_id', 'number']);
        });

        Schema::create('tournament_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tournament_round_id')->constrained()->cascadeOnDelete();
            $table->string('key', 32);
            $table->unsignedSmallInteger('group')->nullable();
            $table->string('bracket', 16);
            $table->unsignedSmallInteger('position');
            $table->boolean('if_needed')->default(false);
            $table->string('status', 16)->default('waiting');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['tournament_id', 'key']);
        });

        Schema::create('tournament_match_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_match_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot');
            $table->json('source');
            $table->foreignId('tournament_participant_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tournament_match_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_match_slots');
        Schema::dropIfExists('tournament_matches');
        Schema::dropIfExists('tournament_rounds');
        Schema::dropIfExists('tournament_stages');
        Schema::dropIfExists('tournament_participants');
        Schema::dropIfExists('tournament_directors');
        Schema::dropIfExists('tournaments');
        Schema::dropIfExists('tournament_organizers');
    }
};
