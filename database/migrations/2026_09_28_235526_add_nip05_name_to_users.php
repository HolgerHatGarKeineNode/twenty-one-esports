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
     * (App\Support\Nostr\Nip05Names). On the user row, so deleting the account
     * releases the name.
     *
     * - `nip05_changed_at`: the last claim, change or release, for the change
     *   limit; an admin's revocation sets it too.
     * - `nip05_revoked_at` / `nip05_revoked_name`: the last name an admin took
     *   back, shown to the player; nobody can claim it while this account
     *   exists.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nip05_name', 32)->nullable()->unique();
            $table->timestamp('nip05_changed_at')->nullable();
            $table->timestamp('nip05_revoked_at')->nullable();
            $table->string('nip05_revoked_name', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nip05_name']);
            $table->dropColumn(['nip05_name', 'nip05_changed_at', 'nip05_revoked_at', 'nip05_revoked_name']);
        });
    }
};
