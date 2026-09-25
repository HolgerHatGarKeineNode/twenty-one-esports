<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A user is a Nostr key: no email, no password, no remember token. The
     * kind-0 name and picture are a display cache only; profiles are edited
     * in the user's own Nostr client, never here.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->string('npub', 63)->unique();

            $table->string('name')->nullable();
            $table->string('picture', 2048)->nullable();
            $table->timestamp('profile_event_at')->nullable();

            $table->string('locale', 8)->nullable();
            $table->boolean('is_member')->default(false);
            $table->timestamp('member_checked_at')->nullable();

            $table->string('avatar_path')->nullable();
            $table->string('platform', 20)->nullable();
            $table->json('gamer_tags')->nullable();
            $table->string('timezone', 64)->nullable();

            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
