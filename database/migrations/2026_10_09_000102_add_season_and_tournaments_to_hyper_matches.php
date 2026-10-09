<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hyperbitcoinization in the season and in tournaments (plan "Hyperbitcoinization", P5).
     *
     * - `hyper_matches.season`: the slug of the chain season that was live when a rated match started (a match is
     *   rated when every seat is a player at the start and a season is live); its result counts in that season only.
     * - `hyper_matches.tournament_match_id`: the tournament match a match plays (its places go back to the bracket).
     * - `hyper_matches.poll_event_id` / `result_event_id`: the spectator poll (NIP-88) and the result the league
     *   signed for a rated or tournament match (behind `esports.hyper.publish`), so each is signed once.
     * - `hyper_seats.points`: the season points a seat of a rated free-for-all match earned (HyperSeason::points()),
     *   written once at the end, so a later change of the table does not rewrite a closed result.
     * - `hyper_ratings` / `hyper_rating_changes`: the season Elo of 1v1 (`duel`) and team matches (`team`), per
     *   player. Their own tables, not `ratings`: a Hyperbitcoinization result has 2 to 6 places and no ladder event,
     *   and every reader of `ratings` (profiles, recent results, rank badges, the season chain) knows two-sided
     *   results only.
     */
    public function up(): void
    {
        Schema::table('hyper_matches', function (Blueprint $table) {
            $table->string('season', 64)->nullable()->after('rated');
            $table->foreignId('tournament_match_id')->nullable()->after('season')->constrained()->nullOnDelete();
            $table->string('poll_event_id', 64)->nullable()->after('tournament_match_id');
            $table->string('result_event_id', 64)->nullable()->after('poll_event_id');

            $table->index(['season', 'status']);
        });

        Schema::table('hyper_seats', function (Blueprint $table) {
            $table->unsignedSmallInteger('points')->nullable()->after('place');
        });

        Schema::create('hyper_ratings', function (Blueprint $table) {
            $table->id();
            $table->string('season', 64);
            $table->string('kind', 8);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('rating');
            $table->unsignedInteger('results')->default(0);
            $table->unsignedInteger('wins')->default(0);
            $table->unsignedInteger('losses')->default(0);
            $table->timestamps();

            $table->unique(['season', 'kind', 'user_id']);
            $table->index(['season', 'kind', 'rating']);
        });

        Schema::create('hyper_rating_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hyper_rating_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hyper_match_id')->constrained()->cascadeOnDelete();
            $table->float('score');
            $table->integer('before');
            $table->integer('after');
            $table->integer('delta');
            $table->unsignedInteger('results_before');
            $table->timestamp('created_at')->nullable();

            $table->unique(['hyper_rating_id', 'hyper_match_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hyper_rating_changes');
        Schema::dropIfExists('hyper_ratings');

        Schema::table('hyper_seats', function (Blueprint $table) {
            $table->dropColumn('points');
        });

        Schema::table('hyper_matches', function (Blueprint $table) {
            $table->dropIndex(['season', 'status']);
            $table->dropConstrainedForeignId('tournament_match_id');
            $table->dropColumn(['season', 'poll_event_id', 'result_event_id']);
        });
    }
};
