<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The season settlement (P37, NIP "Review and corrections", "Payout"):
     *
     * - `users.lud16_changed_at`: when the Lightning address of a player's
     *   cached Nostr profile last changed; a season payout to an address
     *   changed less than 72 h ago waits (address freeze).
     * - `season_block_voids`: the review's corrections, one `void-block`
     *   label (1985) per voided block, with the public reason.
     * - `seasons.settlement_approved_*`: the admin approved the list of who
     *   gets how much; voids end there.
     * - `season_payouts`: one payout per player and season, the same state
     *   machine as tournament payouts (PayoutRunner).
     * - `ledger_transfers.season_payout_id`: a paid season payout leaves the
     *   league wallet's reserve, booked once.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('lud16_changed_at')->nullable()->after('lud16');
        });

        Schema::table('seasons', function (Blueprint $table) {
            $table->timestamp('settlement_approved_at')->nullable();
            $table->foreignId('settlement_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('season_block_voids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('height');
            $table->foreignId('season_attestation_id')->constrained()->restrictOnDelete();
            $table->string('reason', 500);
            $table->foreignId('voided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('voided_by_pubkey', 64);
            $table->foreignId('nostr_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['season_id', 'height']);
        });

        Schema::create('season_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pubkey', 64);
            $table->string('name');
            $table->unsignedInteger('blocks');
            $table->json('heights');
            $table->unsignedBigInteger('amount_sats');
            $table->string('idempotency_key', 64)->unique();
            $table->string('lud16', 320)->nullable();
            $table->string('status', 16)->index();
            $table->string('reason', 40)->nullable();
            $table->text('bolt11')->nullable();
            $table->string('payment_hash', 64)->nullable()->unique();
            $table->timestamp('invoice_expires_at')->nullable();
            $table->string('preimage', 64)->nullable();
            $table->unsignedBigInteger('fees_msats')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('lease_until')->nullable();
            $table->string('lease_owner', 32)->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamps();

            $table->unique(['season_id', 'pubkey']);
        });

        Schema::table('ledger_transfers', function (Blueprint $table) {
            $table->foreignId('season_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['reason', 'season_payout_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ledger_transfers', function (Blueprint $table) {
            $table->dropUnique(['reason', 'season_payout_id']);
            $table->dropConstrainedForeignId('season_payout_id');
        });

        Schema::dropIfExists('season_payouts');
        Schema::dropIfExists('season_block_voids');

        Schema::table('seasons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('settlement_approved_by_id');
            $table->dropColumn('settlement_approved_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lud16_changed_at');
        });
    }
};
