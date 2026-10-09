<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How a Hyperbitcoinization rematch table closed without a match (plan "Hyperbitcoinization", P6): `closed`
     * is `declined` (a player said no, `closed_by`) or `expired` (nobody started it within
     * `esports.hyper.rematch_minutes`). Null for every other table.
     */
    public function up(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->string('closed', 16)->nullable()->after('status');
            $table->foreignId('closed_by')->nullable()->after('closed')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed');
        });
    }
};
