<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The log of the quick actions on an account's check view
     * (App\Support\FairPlay\AccountChecks, plan "Profile standard and
     * impersonation", P3): `clear`, `hold`, `link`, `bar` or `exclude`, with
     * the admin's note and who did it. The effect itself lives where it
     * always lives (holds, account links, bars, trust exclusions); this row
     * says that it was done from here. Never deleted.
     */
    public function up(): void
    {
        Schema::create('account_check_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pubkey', 64)->index();
            $table->string('action', 12);
            $table->string('note', 280)->nullable();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('admin_pubkey', 64);
            $table->timestamps();
        });
    }

    /** The audit trail of admin actions: a rollback that would drop rows refuses. */
    public function down(): void
    {
        $rows = Schema::hasTable('account_check_actions') ? DB::table('account_check_actions')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: account_check_actions holds {$rows} row(s) of admin actions.");
        }

        Schema::dropIfExists('account_check_actions');
    }
};
