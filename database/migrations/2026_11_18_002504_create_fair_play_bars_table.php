<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fair play: an admin barred a player from rated play by hand
     * (App\Support\FairPlay\Bars): who, why (internal, never shown to
     * players), until when, set by whom and when, and maybe lifted before
     * its end (by whom, when). A row is never deleted; barring again writes
     * a new one. Pubkeys are kept next to the user ids, so the record
     * outlives a deleted account. The bars of a list of players are read by
     * `(pubkey, until)`.
     */
    public function up(): void
    {
        Schema::create('fair_play_bars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pubkey', 64);
            $table->string('reason', 280);
            $table->dateTime('until');
            $table->foreignId('barred_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('barred_by_pubkey', 64);
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lifted_by_pubkey', 64)->nullable();
            $table->timestamps();

            $table->index(['pubkey', 'until']);
        });
    }

    /**
     * The bars are the audit trail of admin decisions: a rollback that
     * would drop rows refuses.
     */
    public function down(): void
    {
        $rows = Schema::hasTable('fair_play_bars') ? DB::table('fair_play_bars')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: fair_play_bars holds {$rows} row(s) of admin decisions. Export or archive them first; a rollback would drop them for good.");
        }

        Schema::dropIfExists('fair_play_bars');
    }
};
