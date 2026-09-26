<?php

namespace App\Support\SeasonChain;

use App\Models\TrustRank;
use App\Models\TrustRun;

/**
 * The trust facts of `anchored-trust-v1`, from the trust job's published
 * assertions ({@see TrustJob}) and the archived opponent lists
 * ({@see OpponentLists}).
 *
 * Available only once the job has run in the live season: before Block 0
 * and between seasons nothing is rated anyway, and a fresh season opens
 * rated play only with ranks computed after its Block 0. Without a rank a
 * player reads 0; without a league key nobody is connected (fail closed).
 * The gate only uses published assertions (NIP "Trust rank"), so every
 * rank it pins names the assertion id it came from.
 */
final class AnchoredTrustFacts implements TrustFacts
{
    public function available(): bool
    {
        $live = Seasons::live();

        return $live !== null && TrustRun::query()->where('season_id', $live->id)->exists();
    }

    public function at(array $players, array $gatekeepers): array
    {
        $ranks = TrustRank::query()->whereIn('pubkey', array_values(array_unique([...$players, ...$gatekeepers])))->get();
        $facts = ['trust' => [], 'anchors' => [], 'assertions' => [], 'lists' => [], 'connected' => false, 'key' => (string) TrustRun::query()->latest('id')->value('trust_pubkey')];

        foreach ($ranks as $rank) {
            $facts['trust'][$rank->pubkey] = $rank->rank;
            $facts['assertions'][$rank->pubkey] = $rank->event_id;
            $facts['anchors'][$rank->pubkey] = $rank->anchor === null || $rank->anchor_share === null ? null : [$rank->anchor, $rank->anchor_share];
        }

        [$first, $second] = $gatekeepers;
        $lists = OpponentLists::forLeague();
        $a = $lists?->newest($first);
        $b = $lists?->newest($second);

        // Condition 1: each gatekeeper's newest list names the other; the ids go into the `gate` rows.
        if ($a !== null && in_array($second, OpponentLists::entries($a), true)) {
            $facts['lists'][$first] = $a->event_id;
        }

        if ($b !== null && in_array($first, OpponentLists::entries($b), true)) {
            $facts['lists'][$second] = $b->event_id;
        }

        $facts['connected'] = $first !== $second && isset($facts['lists'][$first], $facts['lists'][$second]);

        return $facts;
    }
}
