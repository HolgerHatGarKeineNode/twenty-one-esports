<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Prize pools and payouts (P9, NIP "Pots and zap targets", "Payout").
     *
     * tournaments: the organizer's target (shown as "X of Y", never paid from
     * by itself), the split per place in percent (null = 50/30/20), when the
     * pool opened (the 31923 got its `zap` tag) and closed (the admin check at
     * the end: receipts after it go to the reserve), and who approved the
     * payouts. The pot's source: `league` (the league wallet, counted from
     * receipts) or `wallet` (the tournament's own NWC wallet, encrypted
     * connection string, its balance read on a schedule with the time of the
     * last good read and the last error), null = no pot.
     *
     * tournament_sponsors: a sponsor's name, pledge and logo. The logo shows
     * once an invoice of the sponsor is paid (incoming_payments.sponsor_id).
     *
     * incoming_payments: every invoice the league's wallet made for a pot,
     * attributed by its payment hash to exactly one pot (`tournament:<id>` or
     * `reserve`). A pot's total is the sum of its settled rows.
     *
     * tournament_payouts: one row per player and tournament (NIP rule 28),
     * with a fixed idempotency key per (tournament, recipient, place), the
     * state machine open -> pending -> paying -> paid | failed, the invoice
     * and its proof, and a lease: only the holder of an unexpired lease may
     * work on a row.
     *
     * ledger_transfers: the double-entry book. One row is one booking from
     * one account to another, so every booking balances by construction; the
     * unique keys make each booking happen at most once.
     *
     * wallet_reconciliations: the daily comparison of the wallet's balance
     * with the sum of the pots.
     */
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->unsignedBigInteger('prize_target_sats')->nullable();
            $table->json('prize_split')->nullable();
            $table->timestamp('pool_opened_at')->nullable();
            $table->timestamp('pool_closed_at')->nullable();
            $table->timestamp('payouts_approved_at')->nullable();
            $table->foreignId('payouts_approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pot_source', 16)->nullable();
            $table->text('pot_nwc_uri')->nullable();
            $table->string('pot_lud16', 320)->nullable();
            $table->unsignedBigInteger('pot_balance_sats')->nullable();
            $table->timestamp('pot_balance_at')->nullable();
            $table->string('pot_balance_error', 40)->nullable();
        });

        Schema::create('tournament_sponsors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedBigInteger('pledged_sats');
            $table->string('logo_path')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('incoming_payments', function (Blueprint $table) {
            $table->id();
            $table->string('pot', 40)->index();
            $table->foreignId('tournament_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sponsor_id')->nullable()->constrained('tournament_sponsors')->nullOnDelete();
            $table->string('source', 16);
            $table->string('payment_hash', 64)->unique();
            $table->text('bolt11');
            $table->unsignedBigInteger('amount_sats');
            $table->text('zap_request')->nullable();
            $table->string('payer_pubkey', 64)->nullable();
            $table->string('comment', 280)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->timestamp('expires_at');
            $table->timestamp('settled_at')->nullable();
            $table->string('preimage', 64)->nullable();
            $table->boolean('late')->default(false);
            $table->foreignId('receipt_event_id')->nullable()->constrained('nostr_events')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            // Who asked for the invoice (security gate F2: open invoices are capped per user and per network).
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['status', 'requester_user_id']);
            $table->index(['status', 'requester_ip_hash']);
        });

        Schema::create('tournament_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained('tournament_participants')->nullOnDelete();
            $table->string('pubkey', 64);
            $table->string('name');
            $table->unsignedSmallInteger('place');
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

            $table->unique(['tournament_id', 'pubkey']);
        });

        Schema::create('ledger_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('from_account', 40)->index();
            $table->string('to_account', 40)->index();
            $table->unsignedBigInteger('sats');
            $table->string('reason', 24);
            $table->foreignId('incoming_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tournament_payout_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('tournament_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['reason', 'incoming_payment_id']);
            $table->unique(['reason', 'tournament_payout_id']);
            $table->unique(['reason', 'tournament_id']);
        });

        Schema::create('wallet_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('wallet_sats')->nullable();
            $table->bigInteger('ledger_sats');
            $table->bigInteger('deviation_sats')->nullable();
            $table->string('error', 120)->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_reconciliations');
        Schema::dropIfExists('ledger_transfers');
        Schema::dropIfExists('tournament_payouts');
        Schema::dropIfExists('incoming_payments');
        Schema::dropIfExists('tournament_sponsors');

        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payouts_approved_by_id');
            $table->dropColumn(['prize_target_sats', 'prize_split', 'pool_opened_at', 'pool_closed_at', 'payouts_approved_at',
                'pot_source', 'pot_nwc_uri', 'pot_lud16', 'pot_balance_sats', 'pot_balance_at', 'pot_balance_error']);
        });
    }
};
