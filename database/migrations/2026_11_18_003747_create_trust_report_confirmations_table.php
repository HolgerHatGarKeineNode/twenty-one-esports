<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A league report counts only after an admin confirmed it (decision of
     * 2026-11-18): a report on its own must not lower anybody's rank. The
     * confirmation is stored like the dismissal (trust_report_dismissals):
     * who, why, when.
     *
     * No row is written for the reports that exist: a report without a
     * decision is waiting from here on, and none counts retroactively. Its
     * place in trust_counted_reports stays, without effect until an admin
     * confirms it.
     */
    public function up(): void
    {
        Schema::create('trust_report_confirmations', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 280);
            $table->timestamps();
        });
    }

    /** Without the confirmations every report is waiting again: no rank is lowered by a rollback. */
    public function down(): void
    {
        Schema::dropIfExists('trust_report_confirmations');
    }
};
