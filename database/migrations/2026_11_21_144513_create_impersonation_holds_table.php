<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Impersonation holds (plan "Profile standard and impersonation", P2,
     * App\Support\FairPlay\Impersonation): an account whose name reads like
     * (skeleton) or whose picture URL equals that of another account the
     * league knows is held from new rated play until an admin decided.
     * `matched` says what matched (`name`, `picture` or `both`), with the
     * held account's name skeleton and picture URL at detection, so a
     * released match is not raised again for the same name and picture.
     * `decision` is `released`, `linked` or `barred`; the row is never
     * deleted. Pubkeys are kept next to the user ids, so the record outlives
     * a deleted account. The open holds of a list of players are read by
     * `(pubkey, decided_at)`.
     */
    public function up(): void
    {
        Schema::create('impersonation_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pubkey', 64);
            $table->foreignId('matched_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('matched_pubkey', 64);
            $table->string('matched', 8);
            $table->string('name_skeleton', 100)->nullable();
            $table->string('picture', 2048)->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decided_by_pubkey', 64)->nullable();
            $table->string('decision', 8)->nullable();
            $table->string('note', 280)->nullable();
            $table->timestamps();

            $table->index(['pubkey', 'decided_at']);
            $table->index(['pubkey', 'matched_pubkey']);
        });
    }

    /**
     * The holds are the audit trail of admin decisions: a rollback that
     * would drop rows refuses.
     */
    public function down(): void
    {
        $rows = Schema::hasTable('impersonation_holds') ? DB::table('impersonation_holds')->count() : 0;

        if ($rows > 0) {
            throw new RuntimeException("Cannot roll back: impersonation_holds holds {$rows} row(s) of admin decisions. Export or archive them first; a rollback would drop them for good.");
        }

        Schema::dropIfExists('impersonation_holds');
    }
};
