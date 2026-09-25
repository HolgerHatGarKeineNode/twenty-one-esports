<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every signed event the league accepted, byte-for-byte as signed, and the
     * answer of each relay it was published to.
     *
     * `event_id` is unique: a second submission of the same id is a no-op
     * (NIP rule 5, replay protection). `(kind, pubkey, d)` finds the stored
     * version of a replaceable or addressable event for the "newer only" check.
     */
    public function up(): void
    {
        Schema::create('nostr_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->string('pubkey', 64);
            $table->unsignedInteger('kind');
            $table->string('d')->nullable();
            $table->unsignedBigInteger('signed_at');
            $table->text('raw');
            $table->timestamps();

            $table->index(['kind', 'pubkey', 'd']);
        });

        Schema::create('relay_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nostr_event_id')->constrained()->cascadeOnDelete();
            $table->string('relay');
            $table->boolean('accepted')->nullable();
            $table->string('message', 500)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamps();

            $table->unique(['nostr_event_id', 'relay']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relay_deliveries');
        Schema::dropIfExists('nostr_events');
    }
};
