<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Hyperbitcoinization lobby (plan "Hyperbitcoinization", P3): a table waits for its seats before the
     * match exists. `hyper_tables` is one table (seat count, mode, round limit; `open` until it starts, then
     * `started` with its `hyper_match_id`, or `cancelled`), `hyper_table_seats` one taken seat (a player or a
     * bot, the faction once chosen, `ready` for a rematch). A free seat has no row.
     *
     * A rematch is a table too (`rematch_of`, at most one per match), its seats taken from the old match.
     * `fill_at`: when bots take a live table's free seats (the sweep's indexed query). The unique indexes
     * keep a faction and a player once per table; NULLs (no faction yet, a bot) never collide, on SQLite and
     * PostgreSQL alike.
     */
    public function up(): void
    {
        Schema::create('hyper_tables', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('mode', 16);
            $table->unsignedTinyInteger('seats');
            $table->unsignedSmallInteger('round_limit')->default(0);
            $table->string('status', 16);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hyper_match_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rematch_of')->nullable()->unique()->constrained('hyper_matches')->cascadeOnDelete();
            $table->timestamp('fill_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'fill_at']);
        });

        Schema::create('hyper_table_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hyper_table_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('seat');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('bot')->default(false);
            $table->string('faction', 16)->nullable();
            $table->boolean('ready')->default(false);
            $table->timestamps();

            $table->unique(['hyper_table_id', 'seat']);
            $table->unique(['hyper_table_id', 'faction']);
            $table->unique(['hyper_table_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hyper_table_seats');
        Schema::dropIfExists('hyper_tables');
    }
};
