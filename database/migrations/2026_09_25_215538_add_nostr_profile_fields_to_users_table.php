<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * More of the kind-0 profile (P10a), still a display cache only.
     *
     * `nip05_verified_at` is set when the league fetched the address's
     * `/.well-known/nostr.json` and it named this pubkey; `nip05_checked_at`
     * when it last tried at all. `profile_checked_at` is the last time a
     * browser handed in a validly signed profile that was at least as new as
     * the cached one: before a TTL has passed, pages do not ask relays again.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('about')->nullable()->after('picture');
            $table->string('banner', 2048)->nullable()->after('about');
            $table->string('website', 2048)->nullable()->after('banner');
            $table->string('lud16', 320)->nullable()->after('website');
            $table->string('nip05', 320)->nullable()->after('lud16');
            $table->timestamp('nip05_verified_at')->nullable()->after('nip05');
            $table->timestamp('nip05_checked_at')->nullable()->after('nip05_verified_at');
            $table->timestamp('profile_checked_at')->nullable()->after('profile_event_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['about', 'banner', 'website', 'lud16', 'nip05', 'nip05_verified_at', 'nip05_checked_at', 'profile_checked_at']);
        });
    }
};
