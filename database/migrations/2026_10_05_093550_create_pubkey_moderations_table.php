<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site-wide moderation of a Nostr key by an admin (App\Support\Moderation\SiteModeration):
     * a mute hides the key's messages and its name in every chat and list of
     * the site, a ban adds no participation at all. By hex pubkey, so a key
     * without an account can be muted or banned before it ever logs in.
     *
     * One row per step, kept as the log: undoing sets `lifted_at` and who
     * lifted it, the row stays. A key is muted or banned while it has a row
     * of that action without `lifted_at`.
     */
    public function up(): void
    {
        Schema::create('pubkey_moderations', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->index();
            $table->string('action', 8);
            $table->string('reason', 500);
            $table->string('actor_pubkey', 64);
            $table->timestamp('lifted_at')->nullable();
            $table->string('lifted_by_pubkey', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pubkey_moderations');
    }
};
