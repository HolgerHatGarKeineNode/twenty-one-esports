<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fair play (P41):
     *
     * - `account_links`: an admin linked a second account to the main account
     *   of the same person (who, when, why), and maybe unlinked it later (who,
     *   when, why). A row is never deleted; linking again writes a new one.
     *   Pubkeys are kept next to the user ids, so the record outlives a
     *   deleted account.
     * - `fair_play_voids`: each result a link voided, with what it was and the
     *   Elo it took back, so the voiding can be read and checked later.
     * - `false_reports`: a dispute an admin decided against the captain who
     *   reported (one per report).
     */
    public function up(): void
    {
        Schema::create('account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('main_pubkey', 64)->index();
            $table->foreignId('linked_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('linked_pubkey', 64)->index();
            $table->foreignId('linked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('linked_by_pubkey', 64);
            $table->string('reason', 280);
            $table->timestamp('unlinked_at')->nullable();
            $table->foreignId('unlinked_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unlinked_by_pubkey', 64)->nullable();
            $table->string('unlink_reason', 280)->nullable();
            $table->timestamps();
        });

        Schema::create('fair_play_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_link_id')->constrained('account_links')->cascadeOnDelete();
            $table->string('source', 16);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('match_number')->nullable();
            $table->json('previous');
            $table->json('elo')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source', 'source_id']);
        });

        Schema::create('false_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pubkey', 64);
            $table->foreignId('series_match_id')->nullable()->constrained('series_matches')->nullOnDelete();
            $table->foreignId('series_report_id')->nullable()->unique()->constrained('series_reports')->nullOnDelete();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['pubkey', 'created_at']);
        });
    }

    /**
     * The link record and the false reports are the audit trail of admin
     * decisions: a rollback that would drop rows refuses.
     */
    public function down(): void
    {
        foreach (['account_links', 'false_reports'] as $table) {
            $rows = Schema::hasTable($table) ? DB::table($table)->count() : 0;

            if ($rows > 0) {
                throw new RuntimeException("Cannot roll back: {$table} holds {$rows} row(s) of admin decisions. Export or archive them first; a rollback would drop them for good.");
            }
        }

        Schema::dropIfExists('false_reports');
        Schema::dropIfExists('fair_play_voids');
        Schema::dropIfExists('account_links');
    }
};
