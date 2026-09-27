<?php

use App\Enums\TournamentFormat;
use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

/*
| The migration that retired league pots (user, 2026-09-27): a league pot
| becomes "no pot" with a moderation entry; an own-wallet pot and a pot
| whose payouts were already approved are left as they are.
*/

test('league pots become no pot with a moderation entry, own-wallet and approved pots stay', function () {
    $migration = require database_path('migrations/2026_09_27_165031_own_wallet_prize_pots.php');
    $migration->down();

    $league = runningChess(TournamentFormat::SingleElimination, 4);
    $legacy = runningChess(TournamentFormat::SingleElimination, 4);
    $approved = runningChess(TournamentFormat::SingleElimination, 4);
    $own = runningChess(TournamentFormat::SingleElimination, 4);
    DB::table('tournaments')->where('id', $league->id)->update(['pot_source' => 'league', 'pool_opened_at' => now(), 'prize_target_sats' => 21_000]);
    DB::table('tournaments')->where('id', $legacy->id)->update(['pot_source' => null, 'pool_opened_at' => now()]);
    DB::table('tournaments')->where('id', $approved->id)->update(['pot_source' => 'league', 'pool_opened_at' => now(), 'payouts_approved_at' => now()]);
    DB::table('tournaments')->where('id', $own->id)->update(['pot_source' => 'wallet', 'pool_opened_at' => now()]);

    $migration->up();

    foreach ([$league, $legacy] as $tournament) {
        $row = DB::table('tournaments')->find($tournament->id);
        expect($row->pot_source)->toBeNull()->and($row->pool_opened_at)->toBeNull()->and($row->prize_target_sats)->toBeNull()
            ->and(DB::table('tournament_moderation_entries')->where('tournament_id', $tournament->id)->where('subject', 'Prize pot')->count())->toBe(1);
    }

    expect(DB::table('tournaments')->find($approved->id)->pot_source)->toBe('league')
        ->and(DB::table('tournaments')->find($own->id)->pot_source)->toBe(Tournament::POT_WALLET)
        ->and(DB::table('tournament_moderation_entries')->where('subject', 'Prize pot')->count())->toBe(2);
});
