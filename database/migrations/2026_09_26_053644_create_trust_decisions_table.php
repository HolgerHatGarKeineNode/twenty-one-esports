<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The append-only log of admin trust decisions and their undos (security
     * re-check round 3): who did what to which report or key, why, and when.
     * The actor's pubkey is kept even if the account goes.
     */
    public function up(): void
    {
        Schema::create('trust_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_pubkey', 64);
            $table->string('action', 16);
            $table->string('target', 64)->index();
            $table->string('reason', 280);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * An append-only log must not vanish in a rollback. This runs before the
     * earlier migrations roll back, so their own refusals would come too late.
     */
    public function down(): void
    {
        $decisions = Schema::hasTable('trust_decisions') ? DB::table('trust_decisions')->count() : 0;

        if ($decisions > 0) {
            throw new RuntimeException("Cannot roll back: the append-only log holds {$decisions} trust decision(s). Export or archive them first; a rollback would drop them for good.");
        }

        Schema::dropIfExists('trust_decisions');
    }
};
