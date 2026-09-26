<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rating (docs/nips/esports.md, "Rating" and "Rank tiers")
    |--------------------------------------------------------------------------
    |
    | The `rating` tag of every ladder of a season: start rating, k-factor and
    | scale; `provisional` is the number of rated results below which a side
    | uses `provisional_k` and shows no tier.
    |
    | daily_pair_limit: at most this many rated results per pairing (the same
    | two players or lineups) and UTC day move the rated rating, as for
    | casual below (farming guard, security gate P7c). null = no limit.
    |
    */

    'rating' => [
        'start' => 1000,
        'k' => 32,
        'provisional_k' => 40,
        'provisional' => 5,
        'scale' => 400,
        'daily_pair_limit' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Casual rating (user decision 2026-09-26)
    |--------------------------------------------------------------------------
    |
    | A second Elo per game and mode for casual play, the same arithmetic as
    | `rating` with its own values. Permanent (no season, no reset), shown as
    | provisional below `provisional` games, never a tier, never counted for
    | badges, the season chain, rewards or the league attestation. It runs
    | before Block 0 too.
    |
    | daily_pair_limit: at most this many casual results per pairing (the same
    | two players or lineups) and UTC day move the casual rating; later games
    | that day are played but leave it where it is. null = no limit.
    |
    */

    'casual' => [
        'start' => 1000,
        'k' => 32,
        'provisional_k' => 40,
        'provisional' => 5,
        'scale' => 400,
        'daily_pair_limit' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rank tiers, Pre-Season thresholds
    |--------------------------------------------------------------------------
    |
    | The 21 `tier` tokens, lowest first, with the minimum rating of each
    | (NIP rev. 5, "Rank tiers"). The lowest tier starts at 0.
    |
    */

    'tiers' => [
        'bronze-1' => 0,
        'bronze-2' => 900,
        'bronze-3' => 925,
        'silver-1' => 950,
        'silver-2' => 975,
        'silver-3' => 1000,
        'gold-1' => 1025,
        'gold-2' => 1050,
        'gold-3' => 1075,
        'platinum-1' => 1100,
        'platinum-2' => 1125,
        'platinum-3' => 1150,
        'diamond-1' => 1175,
        'diamond-2' => 1200,
        'diamond-3' => 1225,
        'champion-1' => 1250,
        'champion-2' => 1275,
        'champion-3' => 1300,
        'grand-champion-1' => 1325,
        'grand-champion-2' => 1375,
        'grand-champion-3' => 1425,
    ],

    /*
    |--------------------------------------------------------------------------
    | Global score and clan values
    |--------------------------------------------------------------------------
    |
    | global_rating_min_weight: a player needs this many rated results in the
    | season for a Global Rating. hashrate: the ladder's `hashrate` tag
    | (win, draw, loss, team win bonus). trust_minimum: the trust gate's
    | minimum rank (rule 1 of the season chain).
    |
    */

    'global_rating_min_weight' => 5,

    'clan_rating_top' => 3,

    'hashrate' => [
        'win' => 3,
        'draw' => 2,
        'loss' => 1,
        'team_win_bonus' => 5,
    ],

    'trust_minimum' => 50,

    /*
    |--------------------------------------------------------------------------
    | Season chain, Pre-Season genesis defaults (CEO default 2026-09-25)
    |--------------------------------------------------------------------------
    |
    | The tags of the Season Genesis (`2156`). Weights are per winning player
    | in thousandths (`1000` = 1x), keyed `<game>/<mode>`; a game and mode
    | without a weight does not mine. Shares are per game and era in percent,
    | daily limits per winning player, game and UTC day. `pairlimit` is
    | [per UTC day, per season]; `subtree` 101 switches rule 7 off.
    |
    */

    'chain' => [
        'supply' => 2_100_000,
        'subsidy' => 20_000,
        'weights' => [
            'chess/blitz' => 1000,
            'chess/correspondence' => 2000,
            'rocket-league/1v1' => 1000,
            'rocket-league/2v2' => 1000,
            'rocket-league/3v3' => 1000,
        ],
        'shares' => [
            'chess' => 40,
            'rocket-league' => 60,
        ],
        'daily' => [
            'chess' => 5,
            'rocket-league' => 5,
        ],
        'pairlimit' => [1, 3],
        'subtree' => 90,
        'moves' => 20,
        'halving_seconds' => 28 * 86400,
        'eras' => 6,
        'claim_seconds' => 90 * 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Estimator (AdminSeason)
    |--------------------------------------------------------------------------
    |
    | window_days: the forecast counts valid blocks per week over this window.
    | Warnings fire for a season shorter or longer than these weeks.
    |
    */

    'estimator' => [
        'window_days' => 28,
        'milestones' => [0.5, 0.75, 0.875, 0.94],
        'min_weeks' => 8,
        'max_weeks' => 26,
    ],

];
