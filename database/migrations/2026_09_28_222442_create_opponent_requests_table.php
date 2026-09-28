<?php

use App\Support\SeasonChain\OpponentRequests;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per player and requester who listed them (P57,
     * {@see OpponentRequests}): when the player was told about the request
     * (once per requester) and whether they declined it. League data only;
     * the requester's public opponent list is untouched.
     */
    public function up(): void
    {
        Schema::create('opponent_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('requester_pubkey', 64);
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'requester_pubkey']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opponent_requests');
    }
};
