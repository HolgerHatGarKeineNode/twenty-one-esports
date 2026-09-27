<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Every tournament pot is the tournament's own NWC wallet (user,
 * 2026-09-27: "Jedes Turnier bekommt seine eigene NWC und eigenen Pot.
 * niemals einen fremden oder von der Season"); the league wallet is the
 * Season-Chain's only.
 *
 * - `prize_mode`: `percent` (null = percent, the split in `prize_split`) or
 *   `fixed` (sats per place in `prize_fixed`; user, same day: "Was ist wenn
 *   er feste Beträge pro Platzierung will? Das muss möglich sein.").
 * - `pot_can_receive`: whether the pot's connection may `make_invoice`
 *   (top-ups from anyone); null = not known yet.
 *
 * Existing league pots (`pot_source` = `league`, or a pool opened before
 * sources existed) become "no pot", each with a moderation log entry. The
 * league wallet was never configured in production, so none is expected;
 * a pot whose payouts were already approved is left as it is and logged,
 * never rewritten under a running payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->string('prize_mode', 8)->nullable();
            $table->json('prize_fixed')->nullable();
            $table->boolean('pot_can_receive')->nullable();
        });

        $league = DB::table('tournaments')
            ->where(fn ($query) => $query->where('pot_source', 'league')->orWhere(fn ($legacy) => $legacy->whereNull('pot_source')->whereNotNull('pool_opened_at')))
            ->get(['id', 'pot_source', 'pool_opened_at', 'payouts_approved_at']);

        foreach ($league as $row) {
            if ($row->payouts_approved_at !== null) {
                Log::warning('Prize pots: a league pot with approved payouts was left as it is', ['tournament' => $row->id]);

                continue;
            }

            DB::transaction(function () use ($row): void {
                DB::table('tournaments')->where('id', $row->id)->update(['pot_source' => null, 'pool_opened_at' => null, 'prize_target_sats' => null, 'updated_at' => now()]);
                DB::table('tournament_moderation_entries')->insert([
                    'tournament_id' => $row->id,
                    'user_id' => null,
                    'user_name' => 'TWENTY ONE',
                    'action' => 'edited',
                    'subject' => 'Prize pot',
                    'reason' => 'League pots were retired: a tournament pot is always the tournament\'s own NWC wallet. Connect one in the prize pot section.',
                    'details' => json_encode(['pot_source' => [$row->pot_source ?? 'league', null]]),
                    'created_at' => now(),
                ]);
            });
        }

        if ($league->isNotEmpty()) {
            Log::info('Prize pots: league pots migrated to "no pot"', ['tournaments' => $league->pluck('id')->all()]);
        }
    }

    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropColumn(['prize_mode', 'prize_fixed', 'pot_can_receive']);
        });
    }
};
