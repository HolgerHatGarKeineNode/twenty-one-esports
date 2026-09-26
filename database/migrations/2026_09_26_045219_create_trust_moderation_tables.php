<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin decisions on trust (NIP "Reports": "An admin either dismisses the
     * report (it stops counting) or excludes the target (raw 0)"). In V1 they
     * stay on the league server; their effect is public in the assertions.
     */
    public function up(): void
    {
        Schema::create('trust_report_dismissals', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 64)->unique();
            $table->foreignId('dismissed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 280);
            $table->timestamps();
        });

        Schema::create('trust_exclusions', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->foreignId('excluded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 280);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_exclusions');
        Schema::dropIfExists('trust_report_dismissals');
    }
};
