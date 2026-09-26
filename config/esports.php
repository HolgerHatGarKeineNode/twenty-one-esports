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
    | Read-only relays the browser asks for kind-0 profiles: the user's own at
    | login, and on every page those of the players shown there (P10a,
    | resources/js/profiles.js). The signed profiles are handed to the server
    | and verified there (App\Support\Nostr\ProfileCache); the server itself
    | never connects to a relay for this.
    |
    | Tests point this at their own relay, so it can be set per environment.
    |
    */

    'profile_relays' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'ESPORTS_PROFILE_RELAYS',
        'wss://purplepag.es,wss://relay.damus.io,wss://nos.lol,wss://relay.primal.net',
    ))))),

    /*
    |--------------------------------------------------------------------------
    | Profiles of other players (P10a)
    |--------------------------------------------------------------------------
    |
    | ttl_minutes: a profile a browser confirmed or updated within this time
    | is not asked from relays again by any page.
    | nip05_recheck_hours: how long a NIP-05 check stands before a newer
    | profile triggers the next one.
    | throttle_per_minute: profile hand-ins per minute and client (IP).
    | wait_ms: how long a page waits for relays before it gives up.
    |
    */

    'profiles' => [
        'ttl_minutes' => 360,
        'nip05_recheck_hours' => 24,
        'throttle_per_minute' => 20,
        'wait_ms' => 2500,
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
    | lobby_poll_seconds: pairings, accepted invites and invites received reach
    | the lobby by push on the player's private channel. The lobby asks the
    | server itself only this often: while searching or waiting for a friend
    | (a push the server could not deliver), and on any lobby while the
    | websocket is down. Never below 30 (it once polled every 2 s).
    |
    | disconnect_claim_seconds: how long a live opponent must be gone from the
    | game before the other player may claim the win (ChessOverlays "Opponent
    | disconnected: claim the win after 60 s").
    |
    | challenge_hours: how long a daily chess challenge stays open (ChessChallenge
    | "has 48 h to accept"). The time per daily move comes from the mode's PGN
    | TimeControl (`1/86400`, App\Games\Chess).
    |
    | rated_queue: whether rated blitz is offered (P7d, App\Support\Chess\RatedChess).
    | Off until the lobby shows a Rated choice: while off, the rated queue
    | refuses and /mining and AdminSeason show chess rewards as not open,
    | because no chess win can mine. On, rated blitz still needs a live
    | season, trust ranks and two Trusted players who list each other.
    |
    */

    'chess' => [
        'rated_queue' => (bool) env('ESPORTS_RATED_CHESS', false),
        'first_move_seconds' => 30,
        'disconnect_claim_seconds' => (int) env('ESPORTS_DISCONNECT_CLAIM_SECONDS', 60),
        'challenge_hours' => 48,
        'queue' => [
            'start_rating' => 1000,
            'range' => ['initial' => 150, 'step' => 150, 'every_seconds' => 30, 'max' => 600],
        ],
        'pairing_limit_per_day' => ['rated' => 3, 'casual' => null],
        'invite_seconds' => 120,
        'lobby_poll_seconds' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Series matches (P6, Rocket League)
    |--------------------------------------------------------------------------
    |
    | respond_max_days: latest "reply by" of a challenge (NIP rule 11: 7 days
    | suggested). plan_max_days: latest suggested start.
    | now_minutes: "Challenge now" proposes a start this many minutes ahead,
    | and the other captain has until then to accept.
    | noshow_minutes: from this long after the start, a captain whose opponent
    | is not in the lobby can report a no-show (MatchRoom.dc.html: 15 min).
    | regions: the lobby regions offered in the match room.
    |
    */

    'series' => [
        'respond_max_days' => 7,
        'plan_max_days' => 14,
        'now_minutes' => 10,
        'noshow_minutes' => 15,
        'regions' => ['EU', 'US-East', 'US-West', 'South America', 'Middle East', 'Oceania', 'Asia'],
    ],

    /*
    |--------------------------------------------------------------------------
    | League key (season chain, P7c)
    |--------------------------------------------------------------------------
    |
    | The key the league signs its own events with: the admin list (30000),
    | the season announcement (31923), the Season Genesis (2156), parameter
    | changes (2158) and every League Attestation (2154). Hex or nsec, in
    | `.env` only; it never leaves the server process
    | (App\Support\SeasonChain\LeagueKey). Without it Block 0 cannot be
    | released, so rated play stays closed (fail closed).
    |
    | Rated play needs an open ladder (NIP rule 11). A ladder is open while a
    | released season is live (App\Support\SeasonChain\Seasons), never
    | from a setting: before Block 0 and between seasons every match is
    | casual.
    |
    */

    'league' => [
        'nsec' => env('ESPORTS_LEAGUE_NSEC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trust key and trust job (P7d, NIP "Trust", `anchored-trust-v1`)
    |--------------------------------------------------------------------------
    |
    | The trust key signs the trust job's own events: its description (0),
    | the anchor list (30000) and one trust assertion (30382) per ranked
    | player. Hex or nsec, in `.env` only, and never the league key (NIP-85:
    | one key per algorithm). Without it, or without the league key, the job
    | does not run, no ranks exist and rated play stays closed (fail closed).
    |
    | Anchors are the paid members of the association for the current and
    | the previous year (`membership.api_url`, `GET /api/members/{year}`) and
    | the league admins. Opponent lists and reports are read from `relays`.
    |
    */

    'trust' => [
        'nsec' => env('ESPORTS_TRUST_NSEC'),
        // Newest reports read per reporter and run: a burst buries only its author's own reports.
        'reports_limit_per_author' => 50,
        // Reports of one author that count per season (mass reports, NIP "Reports").
        'reports_per_author' => (int) env('ESPORTS_TRUST_REPORTS_PER_AUTHOR', 3),
        // Reports that count per season from all reporters under one anchor (their largest share).
        'reports_per_anchor' => (int) env('ESPORTS_TRUST_REPORTS_PER_ANCHOR', 5),
        'name' => 'TWENTY ONE Esports trust',
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

    // Total time one relay gets for one read of the trust job (all its REQs); more is a failed read.
    'relay_fetch_budget_seconds' => 15,

    /*
    |--------------------------------------------------------------------------
    | Game chat (NIP-17)
    |--------------------------------------------------------------------------
    |
    | Relays the browser publishes gift wraps (kind 1059) to and reads them
    | from, for the game chat and the match room chat; the notification DMs
    | go out to them too. Comma-separated `ESPORTS_CHAT_RELAYS`; without it:
    | the ndak test bed locally, nothing in testing, and everywhere else
    | nos.lol and Primal, approved for the chat on 2026-09-25 (P5d). Damus was
    | approved too and dropped: it accepts gift wraps but serves them to no
    | one (its NIP-42 AUTH fails, measured 2026-09-26). An empty
    | `ESPORTS_CHAT_RELAYS=` switches the chat off.
    |
    | Deliberately independent of `relays` above: approving public relays for
    | encrypted chat does not approve publishing league events there.
    |
    */

    'chat' => [
        'relays' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'ESPORTS_CHAT_RELAYS',
            match (env('APP_ENV')) {
                'local' => 'ws://127.0.0.1:7777,ws://127.0.0.1:7780,ws://127.0.0.1:7782',
                'testing' => '',
                default => 'wss://nos.lol,wss://relay.primal.net',
            },
        ))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Two channels, each switched per player in the chess settings:
    |
    | - Nostr DM (NIP-17) from the league's own notification key, never the
    |   league key (NIP "Notifications"). Its secret lives only in `.env`
    |   (hex or nsec); without it no DM is sent. Its kind 0 says `bot: true`
    |   and that it reads no replies (`php artisan esports:notification-profile`).
    |   Sent to the chat relays above.
    |
    | - Browser push (Web Push, RFC 8030) with VAPID keys (RFC 8292) from
    |   `.env`; generate a pair with `php artisan esports:vapid-keys`. Without
    |   keys the settings page offers no push. Keys are never committed.
    |
    */

    'notifications' => [
        'nsec' => env('ESPORTS_NOTIFICATION_NSEC'),
        'name' => 'TWENTY ONE esports notifications',
        // P5c: seconds an "Opponent found" toast counts down before it opens the game.
        'countdown_seconds' => 5,
    ],

    /*
    | The match dock (P5f) refreshes on the player's websocket events, series
    | changes included (P7c, App\Events\SeriesMatchChanged). Without a
    | websocket it polls every `poll_seconds`; with one it still polls every
    | `poll_seconds_with_socket` as a safety net for a missed event (a
    | reconnect, a failed push). Only while the tab is visible.
    */
    'dock' => [
        'poll_seconds' => 20,
        'poll_seconds_with_socket' => 120,
    ],

    'webpush' => [
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
        'subject' => env('WEBPUSH_VAPID_SUBJECT', env('APP_URL')),
        'ttl_seconds' => 86400,
        'timeout_seconds' => 5,
    ],

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
