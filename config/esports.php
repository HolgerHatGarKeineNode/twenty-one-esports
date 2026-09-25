<?php

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

];
