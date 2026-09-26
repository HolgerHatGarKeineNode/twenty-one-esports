<?php

use App\Models\NostrEvent;
use App\Support\SeasonChain\OpponentLists;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The newest opponent list of each player, one row per league and author
     * (P7e gate, Low): the trust job and "who lists me" read these rows, not
     * every archived version. Filled from the archive; kept current whenever
     * a version is archived ({@see OpponentLists::track()}).
     */
    public function up(): void
    {
        Schema::create('opponent_lists_current', function (Blueprint $table) {
            $table->id();
            $table->string('d');
            $table->string('pubkey', 64);
            $table->foreignId('nostr_event_id')->constrained()->cascadeOnDelete();
            $table->string('event_id', 64);
            $table->unsignedBigInteger('signed_at');
            $table->text('entries');
            $table->timestamps();

            $table->unique(['d', 'pubkey']);
        });

        foreach (NostrEvent::query()->where('kind', OpponentLists::KIND)->where('d', 'like', 'esports/%')->orderBy('id')->cursor() as $version) {
            OpponentLists::track($version);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('opponent_lists_current');
    }
};
