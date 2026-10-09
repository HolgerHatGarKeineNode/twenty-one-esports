<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hyperbitcoinization tables fill free seats with bots only when their creator chose it (user 2026-10-09: off by
     * default). Off, a table waits for players and starts once every seat is taken.
     */
    public function up(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->boolean('bots')->default(false)->after('friendly');
        });
    }

    public function down(): void
    {
        Schema::table('hyper_tables', function (Blueprint $table) {
            $table->dropColumn('bots');
        });
    }
};
