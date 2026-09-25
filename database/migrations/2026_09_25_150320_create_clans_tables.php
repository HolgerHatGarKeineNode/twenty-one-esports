<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Projection of the signed clan events (NIP kinds 32150, 32151, 12150).
     *
     * `clan_members.user_id` is unique: one player, one clan, held by the
     * database and not only by the code. `owner_pubkey` is the author of the
     * clan event and part of every clan and lineup address, so it stays even
     * when the owner's account is gone.
     */
    public function up(): void
    {
        Schema::create('clans', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 48)->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_pubkey', 64);
            $table->string('name', 64);
            $table->string('clantag', 4)->unique();
            $table->text('description')->nullable();
            $table->string('picture', 2048)->nullable();
            $table->string('meetup_name')->nullable();
            $table->string('meetup_city')->nullable();
            $table->string('meetup_url', 2048)->nullable();
            $table->decimal('meetup_latitude', 9, 6)->nullable();
            $table->decimal('meetup_longitude', 9, 6)->nullable();
            $table->string('event_id', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('clan_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('joined_at');
            $table->timestamps();
        });

        Schema::create('lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->string('event_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['clan_id', 'game', 'mode']);
        });

        Schema::create('lineup_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lineup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['lineup_id', 'user_id']);
        });

        Schema::create('clan_invites', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lineup_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('invitee_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('status', 16)->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['invitee_id', 'status']);
        });

        Schema::create('clan_departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 16);
            $table->timestamp('left_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_departures');
        Schema::dropIfExists('clan_invites');
        Schema::dropIfExists('lineup_seats');
        Schema::dropIfExists('lineups');
        Schema::dropIfExists('clan_members');
        Schema::dropIfExists('clans');
    }
};
