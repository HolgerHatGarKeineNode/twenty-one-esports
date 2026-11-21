<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A hold an admin sets by hand from the player's check view (plan
     * "Profile standard and impersonation", P3): `matched` is `manual` and
     * there is no matched account, so `matched_pubkey` may be empty. Every
     * existing row keeps its matched account.
     */
    public function up(): void
    {
        Schema::table('impersonation_holds', function (Blueprint $table) {
            $table->string('matched_pubkey', 64)->nullable()->change();
        });
    }

    /**
     * Back to a required matched account: refuses while a manual hold exists,
     * which would lose its row.
     */
    public function down(): void
    {
        $manual = DB::table('impersonation_holds')->whereNull('matched_pubkey')->count();

        if ($manual > 0) {
            throw new RuntimeException("Cannot roll back: impersonation_holds holds {$manual} manual hold(s) without a matched account.");
        }

        Schema::table('impersonation_holds', function (Blueprint $table) {
            $table->string('matched_pubkey', 64)->nullable(false)->change();
        });
    }
};
