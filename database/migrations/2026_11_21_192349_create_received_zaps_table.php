<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zaps a player received (NIP-57 receipts, kind 9735, `p` = the player),
     * as the league read them from the relays for the admin's soft marks
     * (App\Support\FairPlay\ReceivedZaps, plan "Profile standard and
     * impersonation", P3). `zapper_pubkey` signed the zap request inside the
     * receipt (its signature checked). `zap_reads` says when a player's zaps
     * were last read, so "none" can be told from "never read".
     */
    public function up(): void
    {
        Schema::create('received_zaps', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_id', 64)->unique();
            $table->string('recipient_pubkey', 64);
            $table->string('zapper_pubkey', 64);
            $table->unsignedBigInteger('sats');
            $table->timestamp('zapped_at');
            $table->timestamps();

            $table->index(['recipient_pubkey', 'zapper_pubkey']);
        });

        Schema::create('zap_reads', function (Blueprint $table) {
            $table->id();
            $table->string('pubkey', 64)->unique();
            $table->timestamp('read_at');
            $table->unsignedSmallInteger('relays_answered');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zap_reads');
        Schema::dropIfExists('received_zaps');
    }
};
