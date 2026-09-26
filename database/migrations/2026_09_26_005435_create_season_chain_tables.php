<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The season chain in the app (P7c, docs/nips/esports.md "Season chain").
     *
     * seasons: one row per released chain season, written once at Block 0 from
     * the signed genesis (`2156`). There is no draft row: before Block 0 the
     * draft is config/season.php, so "no row" is the rest state.
     * - `parameters`: the genesis consensus parameters (weights, shares,
     *   daily, pairlimit, subtree, moves); supply, subsidy, halving, ends and
     *   claim are columns because they never change during a season.
     * - `digest`: the parameter digest the release label (`1985`) signed.
     *
     * season_parameter_changes: the public log of `2158`, never retroactive.
     *
     * season_attestations: every league attestation (`2154`) of a rated result
     * inside a chain season, in attestation order. `candidate` holds the facts
     * the consensus rules read (App\Support\SeasonChain\Candidate), so the
     * chain can be replayed from this table alone; it is null for a result
     * without a winner (draw, void), which is attested but is no candidate.
     * `height` is set when the candidate mines; otherwise `rule` and `reason`
     * say which rule rejected it. `link_event_id` is the id the `block` tag
     * names: the previous block (or the genesis), or the tip it was checked
     * against.
     */
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->string('league_pubkey', 64);
            $table->unsignedBigInteger('supply');
            $table->unsignedBigInteger('subsidy');
            $table->unsignedInteger('halving_seconds');
            $table->unsignedInteger('claim_seconds');
            $table->unsignedSmallInteger('minimum_trust');
            $table->json('parameters');
            $table->text('genesis_message');
            $table->string('digest', 64);
            $table->timestamp('genesis_at');
            $table->timestamp('ends_at');
            $table->foreignId('genesis_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('release_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('admin_list_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('announcement_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->foreignId('released_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('released_by_pubkey', 64);
            $table->timestamps();

            $table->index(['genesis_at', 'ends_at']);
        });

        Schema::create('season_parameter_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->timestamp('signed_at');
            $table->timestamp('effective_at');
            $table->foreignId('changed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_pubkey', 64);
            $table->text('reason');
            $table->json('parameters');
            $table->string('tip_event_id', 64);
            $table->foreignId('nostr_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->index(['season_id', 'effective_at']);
        });

        Schema::create('season_attestations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedSmallInteger('board')->default(1);
            $table->unsignedBigInteger('match_number')->nullable();
            $table->string('label', 32);
            $table->string('game', 32);
            $table->string('mode', 32);
            $table->string('ladder_address', 200);
            $table->timestamp('attested_at');
            $table->json('candidate')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedTinyInteger('rule')->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('subject', 200)->nullable();
            $table->unsignedSmallInteger('era')->nullable();
            $table->unsignedBigInteger('reward_per_player')->default(0);
            $table->unsignedBigInteger('reward')->default(0);
            $table->string('link_event_id', 64)->nullable();
            $table->string('event_id', 64)->unique();
            $table->foreignId('nostr_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['season_id', 'source', 'source_id', 'board']);
            $table->unique(['season_id', 'height']);
            $table->index(['season_id', 'attested_at']);
            $table->index(['season_id', 'ladder_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_attestations');
        Schema::dropIfExists('season_parameter_changes');
        Schema::dropIfExists('seasons');
    }
};
