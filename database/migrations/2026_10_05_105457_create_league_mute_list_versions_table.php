<?php

use App\Support\Moderation\LeagueMuteList;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which `p` tags of each version of the league's mute list the site
     * added ({@see LeagueMuteList}): a version also carries a client's
     * foreign tags over, and the event alone cannot tell the two apart. A
     * lift removes only the keys recorded here, never a client's public `p`.
     */
    public function up(): void
    {
        Schema::create('league_mute_list_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nostr_event_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('site_keys');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_mute_list_versions');
    }
};
