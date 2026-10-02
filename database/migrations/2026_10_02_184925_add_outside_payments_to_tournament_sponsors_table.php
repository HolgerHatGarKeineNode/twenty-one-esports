<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A sponsor's pledge paid outside the pot's wallet (user, 2026-10-02:
     * "Rechnung wurde anders gezahlt"): the sats an organizer or admin marked
     * as paid, who did it, when, and their note. It counts toward the pot
     * like a paid invoice, but it is not in the wallet, so the payout never
     * takes it from there. One mark per sponsor; undone (all four null)
     * until the payouts are approved. Each mark and undo is also a line in
     * the tournament's moderation log.
     */
    public function up(): void
    {
        Schema::table('tournament_sponsors', function (Blueprint $table) {
            $table->unsignedBigInteger('paid_outside_sats')->nullable();
            $table->string('paid_outside_note', 200)->nullable();
            $table->foreignId('paid_outside_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_outside_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tournament_sponsors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('paid_outside_by_id');
            $table->dropColumn(['paid_outside_sats', 'paid_outside_note', 'paid_outside_at']);
        });
    }
};
