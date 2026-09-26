<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trust job (P7d, NIP "Algorithm `anchored-trust-v1`").
     *
     * trust_runs: one row per computation, with the live season it ran in
     * (the gate opens only after a run in the current season) and the
     * anchor-list version it used.
     *
     * trust_ranks: the newest published assertion (`30382`) per player,
     * which the gate reads and pins. A player gets a row once ranked above 0
     * and keeps it (NIP: a rank that drops to 0 is republished as 0). Every
     * published version stays in nostr_events, served by id.
     */
    public function up(): void
    {
        Schema::create('trust_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trust_pubkey', 64);
            $table->foreignId('anchor_list_nostr_event_id')->constrained('nostr_events');
            $table->unsignedInteger('anchors');
            $table->unsignedInteger('lists');
            $table->unsignedInteger('ranked');
            $table->unsignedInteger('published');
            $table->timestamp('computed_at');
            $table->timestamps();
        });

        Schema::create('trust_ranks', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->unsignedTinyInteger('rank');
            $table->double('raw');
            $table->string('anchor', 64)->nullable();
            $table->unsignedTinyInteger('anchor_share')->nullable();
            $table->string('anchor_list_event_id', 64);
            $table->foreignId('trust_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('nostr_event_id')->constrained('nostr_events');
            $table->string('event_id', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_ranks');
        Schema::dropIfExists('trust_runs');
    }
};
