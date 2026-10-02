<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A zap into a tournament's pot whose receipt the league verified once,
     * when it signed it (App\Support\Prizes\ZapReceipts, security gate on
     * 8a171405, F2): the zap sponsors' wall sums these rows in one query and
     * never re-verifies a receipt per page view.
     */
    public function up(): void
    {
        Schema::table('incoming_payments', function (Blueprint $table) {
            $table->boolean('zap_verified')->default(false);
            $table->index(['tournament_id', 'zap_verified']);
        });
    }

    public function down(): void
    {
        Schema::table('incoming_payments', function (Blueprint $table) {
            $table->dropIndex(['tournament_id', 'zap_verified']);
            $table->dropColumn('zap_verified');
        });
    }
};
