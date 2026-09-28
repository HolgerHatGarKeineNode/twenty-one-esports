<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * P45: notifications a player chose to get by Nostr DM once a day instead
 * of at once, waiting for the daily digest (`notifications:dm-digest`).
 * Each row is deleted when its digest goes out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_digest_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('title', 200);
            $table->string('body', 500);
            $table->string('url', 500);
            $table->unsignedBigInteger('match')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_digest_items');
    }
};
