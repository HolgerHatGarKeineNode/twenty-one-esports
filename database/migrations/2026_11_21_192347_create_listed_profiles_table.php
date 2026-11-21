<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kind-0 profiles of accounts that Verein members list on their league
     * opponent lists (App\Support\FairPlay\ListedProfiles, plan "Profile
     * standard and impersonation", P3, D13): read from the relays by the
     * league, so a new account can be compared with an original that never
     * logged in. `first_seen_at` is the league's own time: when it first
     * archived a member's list naming the key, never a time the sender set.
     * `name_key` is the name as it reads (Impersonation::nameKey()), text:
     * NFKC lengthens a name (at most Impersonation::MAX_NAME_KEY_LENGTH is
     * stored, a longer key is not comparable and stays null).
     * `fetched_at`: the last read that brought a profile; `checked_at`: the
     * last read at all. `unlisted_at`: no member's newest list names the key
     * any more; the row stays (its first-seen time with it) and is not
     * compared until a member lists the key again.
     */
    public function up(): void
    {
        Schema::create('listed_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->string('name', 100)->nullable();
            $table->text('name_key')->nullable()->index();
            $table->string('picture', 2048)->nullable();
            $table->timestamp('profile_event_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('unlisted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listed_profiles');
    }
};
