<?php

use App\Games\Chess;
use App\Games\RocketLeague;

return [

    /*
    |--------------------------------------------------------------------------
    | Board (always admins)
    |--------------------------------------------------------------------------
    |
    | Mirror of the EINUNDZWANZIG board, `config('einundzwanzig.config.current_board')`
    | in the einundzwanzig-verein repository (read by App\Support\Board there).
    | Update it by hand when the board changes. Further admins are granted in
    | the admin UI and live in the `admins` table. An entry that is not a valid
    | npub is ignored (fail closed).
    |
    */

    'board' => [
        'npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6',
        'npub1gvqkjccl9urg93svaw60jqkk3ux8r3ycl5t3rlvc9uzjeu0agfuss8x8qy',
        'npub10t8npnmqhpwx9w8k232kess7gqtdlr6kqjemdzf8jnughwqd0gwsez0924',
        'npub1r8343wqpra05l3jnc4jud4xz7vlnyeslf7gfsty7ahpf92rhfmpsmqwym8',
        'npub17fqtu2mgf7zueq2kdusgzwr2lqwhgfl2scjsez77ddag2qx8vxaq3vnr8y',
        'npub1v4lgwjv7qfn3t7qjscpsgz9vqvspf6hecdp2ckgp0dz89uqn5slsgrhw3p',
        'npub14r770s5wrqpm8jmzur5arnm9aum9x0wasaxwczael54xhjggl7ws5lygc6',
    ],

    /*
    |--------------------------------------------------------------------------
    | Verein membership
    |--------------------------------------------------------------------------
    |
    | Membership is a badge and perk flag, never a gate for playing. It is read
    | from the public `GET /api/members/{year}` list of the Verein.
    |
    | grace_years: how many previous years still count. 1 = "paid this year or
    | last year" (default), 0 = this year only.
    |
    */

    'membership' => [
        'api_url' => env('ESPORTS_MEMBERSHIP_API_URL', 'https://verein.einundzwanzig.space/api/members'),
        'grace_years' => (int) env('ESPORTS_MEMBERSHIP_GRACE_YEARS', 1),
        'stale_after_hours' => 24,
        'list_cache_minutes' => 60,
        'retry_after_minutes' => 10,
        'timeout_seconds' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile relays
    |--------------------------------------------------------------------------
    |
    | Read-only relays the login module asks for the user's kind-0 profile.
    | The signed profile is sent along with the login and verified here; the
    | server itself never connects to a relay for this.
    |
    */

    'profile_relays' => [
        'wss://purplepag.es',
        'wss://relay.damus.io',
        'wss://nos.lol',
        'wss://relay.primal.net',
    ],

    /*
    |--------------------------------------------------------------------------
    | Gamer tags
    |--------------------------------------------------------------------------
    |
    | The account names a player can list on the gaming profile.
    |
    */

    'gamer_tags' => [
        'epic' => 'Epic Games',
        'steam' => 'Steam',
        'psn' => 'PlayStation Network',
        'xbox' => 'Xbox',
        'nintendo' => 'Nintendo',
    ],

    /*
    |--------------------------------------------------------------------------
    | Game registry
    |--------------------------------------------------------------------------
    |
    | Games are code (App\Games\Contracts\Game). A new game is a new class
    | plus one line here; order is display order.
    |
    */

    'games' => [
        Chess::class,
        RocketLeague::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Live chess (P5)
    |--------------------------------------------------------------------------
    |
    | first_move_seconds: before both sides made their first move no clock
    | runs; whoever is to move has this long, then the server aborts the game
    | (ChessOverlays "Abort before the first move", ChessStates "Game aborted").
    |
    | queue.range: the blitz queue pairs two players when their ratings are
    | within both players' current range. The range starts at `initial` and
    | grows by `step` every `every_seconds` of waiting, up to `max`
    | (ChessStates: "after 30 s the range opens to ±300"). Everyone is rated
    | `start_rating` until Elo exists (P7).
    |
    | pairing_limit_per_day: most games the same two players may get per UTC
    | day (plan, open question 4: 3 for rated games); null = no limit. Casual
    | games have none.
    |
    | invite_seconds: how long a blitz invite to a friend stays open.
    |
    */

    'chess' => [
        'first_move_seconds' => 30,
        'queue' => [
            'start_rating' => 1000,
            'range' => ['initial' => 150, 'step' => 150, 'every_seconds' => 30, 'max' => 600],
        ],
        'pairing_limit_per_day' => ['rated' => 3, 'casual' => null],
        'invite_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relays the league publishes to
    |--------------------------------------------------------------------------
    |
    | Comma-separated websocket URLs. Locally the ndak test bed (rnostr 7777,
    | strfry 7780, khatru 7782); empty in testing (phpunit.xml) and empty by
    | default everywhere else. Never list a public relay here before the user
    | has approved publishing (plan: "publishing to public relays: never" in V1
    | development).
    |
    */

    'relays' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ESPORTS_RELAYS',
        env('APP_ENV') === 'local' ? 'ws://127.0.0.1:7777,ws://127.0.0.1:7780,ws://127.0.0.1:7782' : '',
    ))))),

    'relay_timeout_seconds' => 5,

    /*
    |--------------------------------------------------------------------------
    | EINUNDZWANZIG portal (meetup import for clans)
    |--------------------------------------------------------------------------
    |
    | Public `GET /api/meetups` (the portal's map list) with intro and logo.
    | A clan can always be created without it.
    |
    */

    'portal' => [
        'meetups_url' => env('ESPORTS_PORTAL_MEETUPS_URL', 'https://portal.einundzwanzig.space/api/meetups'),
        'cache_minutes' => 60,
        'timeout_seconds' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pre-Season (before Block 0)
    |--------------------------------------------------------------------------
    |
    | Until a season is released the home page shows the pre-launch state
    | (SEASON-CHAIN.md, decisions 5, 8 and 13).
    |
    | block0_at: planned Block 0 as an ISO 8601 datetime with offset, e.g.
    | 2026-10-02T19:00:00+02:00. null = "date coming soon", no countdown.
    | The board still releases Block 0 by hand; this is only the plan.
    |
    | pot_sats: the Pre-Season supply in sats; null = the pot card is hidden.
    |
    | genesis_message: the text written into Block 0; null = no teaser.
    |
    | display_timezone: zone for dates shown to guests and to players without
    | a timezone of their own.
    |
    */

    'preseason' => [
        'block0_at' => env('ESPORTS_BLOCK0_AT'),
        'pot_sats' => env('ESPORTS_PRESEASON_POT_SATS'),
        'genesis_message' => env('ESPORTS_GENESIS_MESSAGE'),
        'display_timezone' => 'Europe/Berlin',
    ],

];
