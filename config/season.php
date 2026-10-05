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
    | minimum rank (rule 1 of the season chain), the default of the chain
    | draft.
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
    | Season chain, the defaults of the chain draft (user decision 2026-09-28)
    |--------------------------------------------------------------------------
    |
    | What the chain draft of the admin season page (ChainDraft, P43) starts
    | from before the board saved one; the board edits every value there, and
    | Block 0 signs the draft into the Season Genesis (`2156`). Weights are per
    | winning player in thousandths (`1000` = 1x), keyed `<game>/<mode>`; a
    | game and mode without a weight does not mine. Shares are per share key
    | and era in percent, daily limits per winning player, share key and UTC
    | day. A share group (`groups`, NIP `group`) counts its games as one for
    | both: the two EA Sports FC editions share one share and one daily limit,
    | and so do the board games nine men's morris and checkers (`board-games`,
    | plan "Mühle und Dame", P6; user decision 2026-09-29). `pairlimit` is
    | [per UTC day, per season]; `subtree` 101 switches rule 7 off; `moves`
    | counts for chess and the board games. The length is in weeks, eras last
    | `halving_days`.
    |
    | board_games_proposal: what the admin season page proposes for the board
    | games (P6), never part of a draft on its own: a weight per mode (the
    | correspondence mode of P8 at twice blitz, as chess daily), the share of
    | their group and its daily limit. The shares of the other games
    | are the board's decision of 2026-09-28 and stay; filling in the proposal
    | shrinks them in proportion to make room (35/40/25 become 32/36/22 next
    | to 10), and the board saves that or not (plan: no silent redistribution).
    |
    | chess_rapid_proposal: the weight of chess rapid (plan "Schach Rapid und
    | Clan", P1), decided by the user on 2026-10-05. Rapid joined after the
    | board's weights, so it mines only once the board fills it into the
    | draft, or into a parameter change of a live season, which also opens
    | its ladder there (NIP rev. 9.22). Until then rapid is casual in a live
    | season. It counts in the chess share and daily limit.
    |
    | age_of_empires_2_proposal: the same for Age of Empires II (plan "AoE2
    | und Trackmania", P1): a weight per mode as the other series games, its
    | own share and daily limit. These are DRAFT values nobody decided yet;
    | AoE2 mines only once the board fills them in (or its own) and saves.
    |
    */

    'chain' => [
        'supply' => 2_100_000,
        'subsidy' => 2_100,
        'weights' => [
            'chess/blitz' => 1000,
            'chess/correspondence' => 2000,
            'rocket-league/1v1' => 1000,
            'rocket-league/2v2' => 1000,
            'rocket-league/3v3' => 1000,
            'ea-sports-fc-26/1v1' => 1000,
            'ea-sports-fc-26/2v2' => 1000,
            'ea-sports-fc-27/1v1' => 1000,
            'ea-sports-fc-27/2v2' => 1000,
        ],
        'groups' => [
            'ea-sports-fc' => ['ea-sports-fc-26', 'ea-sports-fc-27'],
            'board-games' => ['nine-mens-morris', 'checkers'],
        ],
        'shares' => [
            'chess' => 35,
            'rocket-league' => 40,
            'ea-sports-fc' => 25,
        ],
        'daily' => [
            'chess' => 5,
            'rocket-league' => 5,
            'ea-sports-fc' => 5,
        ],
        'board_games_proposal' => [
            'weights' => [
                'nine-mens-morris/blitz' => 1000,
                'nine-mens-morris/correspondence' => 2000,
                'checkers/blitz' => 1000,
                'checkers/correspondence' => 2000,
            ],
            'share' => 10,
            'daily' => 5,
        ],
        // DRAFT values (plan "AoE2 und Trackmania", P1, 2026-09-30), not decided by the board: as the series games.
        'age_of_empires_2_proposal' => [
            'weights' => [
                'age-of-empires-2/1v1' => 1000,
                'age-of-empires-2/2v2' => 1000,
                'age-of-empires-2/3v3' => 1000,
            ],
            'share' => 10,
            'daily' => 5,
        ],
        // Chess rapid (plan "Schach Rapid und Clan", P1): weight 1.5, the user's decision of 2026-10-05, between blitz
        // and daily. A mode of a game that mines already: no share or daily limit of its own (it counts in chess).
        'chess_rapid_proposal' => [
            'weights' => [
                'chess/rapid' => 1500,
            ],
        ],
        'pairlimit' => [1, 3],
        'subtree' => 90,
        'moves' => 20,
        'weeks' => 24,
        'halving_days' => 28,
        'claim_days' => 90,
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
