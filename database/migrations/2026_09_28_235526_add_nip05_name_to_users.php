<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional NIP-05 name on the league's domain (P47): `name@<app host>`,
     * served from /.well-known/nostr.json. Opt-in in the settings; lowercase
     * a-z, 0-9, `.`, `_`, `-`, unique (the index), reserved names refused
     * (App\Support\Nostr\Nip05Names).
     *
     * users:
     * - `nip05_name`: the claimed name; on the user row, so it goes with the account.
     * - `nip05_changed_at`: the last claim, change or release, for the change limit.
     * - `nip05_revoked_at` / `nip05_revoked_name`: the last name an admin took
     *   back, for the player's own settings page only. What keeps a name from
     *   being claimed again is a hold below, never this row.
     *
     * nip05_holds (P47 security audit F2): names nobody may claim, keyed by
     * name and independent of any account, so neither deleting the account
     * nor a second revocation undoes one.
     * - `reason` `revoked`: an admin took the name back; held until an admin
     *   lifts the hold (`held_until` null).
     * - `reason` `released`: given up, changed or gone with a deleted account;
     *   held for the change period (`held_until`) against every other key, so
     *   nobody else takes over a name others still know. `pubkey` is the key
     *   that held it: that key may take its own name back meanwhile.
     * - `lifted_at` / `lifted_by_id`: an admin ended the hold early.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nip05_name', 32)->nullable()->unique();
            $table->timestamp('nip05_changed_at')->nullable();
            $table->timestamp('nip05_revoked_at')->nullable();
            $table->string('nip05_revoked_name', 32)->nullable();
        });

        Schema::create('nip05_holds', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->index();
            $table->string('reason', 16);
            $table->string('pubkey', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('held_until')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nip05_holds');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nip05_name']);
            $table->dropColumn(['nip05_name', 'nip05_changed_at', 'nip05_revoked_at', 'nip05_revoked_name']);
        });
    }
};
