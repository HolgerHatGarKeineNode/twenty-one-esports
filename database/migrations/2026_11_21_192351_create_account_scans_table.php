<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * AI scans an admin started for one account (App\Support\FairPlay\AccountScans,
     * plan "Profile standard and impersonation", P3, D11): a hint for the
     * admin, never a decision. `status` `done` with a `verdict`
     * (`fine` | `unclear` | `suspicious`) and short `reasons`, or `failed`
     * with an `error`. `inputs` is exactly what the model saw. Tokens and the
     * estimated cost in US dollars are kept per scan; who started it and when.
     */
    public function up(): void
    {
        Schema::create('account_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pubkey', 64)->index();
            $table->string('status', 8);
            $table->string('verdict', 12)->nullable();
            $table->json('reasons')->nullable();
            $table->json('inputs');
            $table->string('model', 64);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->string('error', 280)->nullable();
            $table->foreignId('started_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('started_by_pubkey', 64);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /** The scans are the record of what admins asked and paid for: a rollback that would drop rows refuses. */
    public function down(): void
    {
        $rows = Schema::hasTable('account_scans') ? DB::table('account_scans')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: account_scans holds {$rows} row(s). Export them first.");
        }

        Schema::dropIfExists('account_scans');
    }
};
