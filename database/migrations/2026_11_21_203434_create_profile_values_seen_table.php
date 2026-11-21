<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Since when an account carries a name or a picture (plan "Profile
     * standard and impersonation", P3, user 2026-11-21: an account that
     * renames to a name another account had first is held too):
     * App\Support\FairPlay\Impersonation notes it whenever the league stores
     * a profile (ProfileCache for our players, ListedProfiles for accounts
     * on members' lists). `kind` is `name` (the value is the name key,
     * Impersonation::nameKey()) or `picture` (the URL); `value_hash` is its
     * SHA-256, so one row per account and value. `first_seen_at` is the
     * league's own time: the time the account became known to the league
     * when this is the first name or picture it carried, else the time the
     * league first stored the changed profile; never a time the account set.
     * A row is never updated: changing back keeps the first time.
     */
    public function up(): void
    {
        Schema::create('profile_values_seen', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64);
            $table->string('kind', 8);
            $table->text('value');
            $table->string('value_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamps();

            $table->unique(['pubkey', 'kind', 'value_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_values_seen');
    }
};
