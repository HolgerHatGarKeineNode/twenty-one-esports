<?php

use App\Games\AgeOfEmpires2;
use App\Games\Checkers;
use App\Games\Chess;
use App\Games\EaSportsFc26;
use App\Games\EaSportsFc27;
use App\Games\NineMensMorris;
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

    'profile_relays' => array_values(array_filter(array_map('trim', explode(',', env('APP_ENV') === 'testing'
        ? (string) env('ESPORTS_PROFILE_RELAYS', '')
        : ((string) env('ESPORTS_PROFILE_RELAYS') ?: 'wss://purplepag.es,wss://relay.damus.io,wss://nos.lol,wss://relay.primal.net,wss://nostr.mom,wss://relay.snort.social'))))),

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
    | Image proxy for foreign avatars (performance plan P5)
    |--------------------------------------------------------------------------
    |
    | The einundzwanzig-group image proxy (`GET /img/{preset}?src=`): it fetches
    | a kind-0 picture once, crops it and serves a small WebP from its disk
    | cache. Measured 2026-10-04 on the 52 foreign prod avatars: 25.1 MB
    | direct, 0.13 MB through the `avatar` preset. Empty: the browser loads the
    | picture from its own host, as before. Example:
    | `https://group.einundzwanzig.space/img`. Only App\Support\ImageProxy and
    | proxiedAvatar() (resources/js/imageProxy.js) build these URLs; our own
    | uploads and generated avatars stay local, and the stream and card
    | builders that fetch bytes on the server read the original URL.
    |
    */

    'image_proxy_url' => rtrim((string) env('IMG_PROXY_URL', ''), '/'),

    /*
    |--------------------------------------------------------------------------
    | wire:navigate on the shell (performance plan P6b)
    |--------------------------------------------------------------------------
    |
    | On: links between the shell's navigable pages (home, /play, the lists,
    | the player's own pages and settings tabs; App\Support\Navigation\Navigate)
    | swap the page in the kept window instead of loading it in full: the
    | websocket stays up and the online presence no longer flickers per click.
    | Game, board, room, lobby and chat pages load in full either way.
    |
    | On by default since P6b: ten navigations leave the listeners, Echo
    | callbacks, intervals and components where one leaves them
    | (tests/Browser/NavigateSpikeTest.php; numbers in the plan's
    | p6b-ergebnis.md). ESPORTS_NAVIGATE=false is the kill switch: every link
    | is a plain link again.
    |
    */

    'navigate' => (bool) env('ESPORTS_NAVIGATE', true),

    /*
    |--------------------------------------------------------------------------
    | NIP-05 names on the league's domain (P47)
    |--------------------------------------------------------------------------
    |
    | A player may claim `name@<app host>` (App\Support\Nostr\Nip05Names),
    | served from /.well-known/nostr.json. `change_days`: a claimed name
    | changes (or is claimed again after a release) at most once in this
    | many days, and a name given up is held that long against other keys.
    | `reserved`: names nobody claims, on top of the league's own NIP-05 name
    | and the Lightning address of the pool (both from config), also in
    | look-alike spelling; generic words among them stay free inside a longer
    | name. `staff_words`: words no name may contain at all, in any spelling
    | that reads like them (Nip05Names::isReserved(), P47 re-audit N3).
    |
    */

    'nip05' => [
        'min_length' => 3,
        'max_length' => 30,
        'change_days' => 30,
        'reserved' => [
            'admin', 'administrator', 'root', 'system', 'league', 'liga', 'twentyone', 'twenty-one', 'twenty_one', '21',
            'einundzwanzig', 'verein', 'esports', 'support', 'help', 'hilfe', 'info', 'contact', 'kontakt', 'team', 'staff',
            'official', 'offiziell', 'mod', 'moderator', 'moderation', 'e21', 'security', 'abuse', 'postmaster', 'webmaster', 'hostmaster',
            'noreply', 'no-reply', 'bot', 'stream', 'live', 'news', 'nostr', 'pool', 'wallet', 'payout', 'payouts',
            'www', 'mail', 'api', 'relay', 'tournament', 'tournaments', 'director', 'organizer', 'null', 'undefined',
            'helpdesk',
        ],
        'staff_words' => ['admin', 'support', 'official', 'moderator', 'einundzwanzig', 'twentyone', 'staff', 'helpdesk'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Zap the winner (P47)
    |--------------------------------------------------------------------------
    |
    | App\Support\Lightning\WinnerZaps: invoices one player may ask the
    | winners' Lightning servers for per hour (each is a request the league
    | makes to a stranger's server on the player's behalf).
    |
    */

    'zaps' => [
        'invoices_per_hour' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gamer tags
    |--------------------------------------------------------------------------
    |
    | The account names a player can list on the gaming profile. They stay
    | private: the only place they leave the settings is the player's own
    | card composer in a casual 1v1 room (`ea`: the EA ID an account card
    | prefills, NIP "Lobby and account cards"), sent end-to-end encrypted.
    |
    */

    'gamer_tags' => [
        'epic' => 'Epic Games',
        'steam' => 'Steam',
        'psn' => 'PlayStation Network',
        'xbox' => 'Xbox',
        'nintendo' => 'Nintendo',
        'ea' => 'EA ID',
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

    /*
    | The order games are shown in everywhere (GameRegistry::ordered): the
    | `first` slugs in this order, then every other game as registered, then
    | the `last` slugs. User 2026-10-01: Blockfill third, Nine Men's Morris
    | and Checkers at the very end.
    */
    'game_order' => [
        'first' => ['chess', 'rocket-league', 'blockfill'],
        'last' => ['nine-mens-morris', 'checkers'],
    ],

    'games' => [
        Chess::class,
        RocketLeague::class,
        EaSportsFc27::class,
        EaSportsFc26::class,
        AgeOfEmpires2::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Board games (plan "Mühle und Dame")
    |--------------------------------------------------------------------------
    |
    | Board games other than chess (App\Games\BoardGame), played on our own
    | board game core next to chess. enabled: the switch for all of them
    | (`ESPORTS_BOARD_GAMES`, off by default); off, no board game is in the
    | registry: no route, no navigation, no cup. games: one entry per board
    | game, keyed by its reserved slug (BoardGame::RESERVED_SLUGS; nine men's
    | morris is never `mill`), each with its own switch and its class. The
    | classes come with P3 (nine men's morris) and P4 (checkers); an entry
    | without a class stays off. first_move_seconds (P2): before both sides
    | made their first move no clock runs; the side to move has this long for
    | it or the game is aborted (as `chess.first_move_seconds`). queue (P5):
    | the casual queue of each board game, paired by the casual rating of
    | that game within a range that widens while a player waits (as
    | `chess.queue.range`). invite_seconds (P5): how long an invite to a
    | player stays open. rated_queue (P6, App\Support\Board\RatedBoard): whether
    | the rated queue of the board games is offered (`ESPORTS_RATED_BOARD_GAMES`,
    | off by default, as `chess.rated_queue`). Off, no board game win can mine,
    | and /mining and AdminSeason show board game rewards as not open. On, a
    | rated game still needs a live season and two Trusted players who list
    | each other. correspondence (P8, App\Support\Board\BoardChallenges):
    | the challenges to a correspondence game (one move a day, as daily
    | chess): how long one stays open, and how many a player may send in 24
    | hours, in total and to the same player (as `chess.challenge_hours`,
    | `chess.challenges_per_day`, `chess.challenges_per_recipient_per_day`).
    | Challenge notifications off the page share the recipient's daily cap
    | with daily chess (`chess.challenge_dms_per_recipient_per_day`).
    |
    */

    'board_games' => [
        'enabled' => (bool) env('ESPORTS_BOARD_GAMES', false),
        'first_move_seconds' => 30,
        'queue' => [
            'range' => ['initial' => 150, 'step' => 150, 'every_seconds' => 30, 'max' => 600],
        ],
        'invite_seconds' => 120,
        'rated_queue' => (bool) env('ESPORTS_RATED_BOARD_GAMES', false),
        'correspondence' => [
            'challenge_hours' => 48,
            'challenges_per_day' => (int) env('ESPORTS_BOARD_CHALLENGES_PER_DAY', 20),
            'challenges_per_recipient_per_day' => (int) env('ESPORTS_BOARD_CHALLENGES_PER_RECIPIENT_PER_DAY', 3),
        ],
        'games' => [
            'nine-mens-morris' => ['enabled' => (bool) env('ESPORTS_BOARD_GAME_NINE_MENS_MORRIS', false), 'class' => NineMensMorris::class],
            'checkers' => ['enabled' => (bool) env('ESPORTS_BOARD_GAME_CHECKERS', false), 'class' => Checkers::class],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Score games (plan "AoE2 und Trackmania", P4)
    |--------------------------------------------------------------------------
    |
    | Highscore and time attack games (App\Games\ScoreGame): every player
    | tries alone for a best value on a course inside a window; the league
    | reads it from a source (App\Support\Scores). No real game yet: `demo`
    | registers App\Games\ScoreDemo (ESPORTS_SCORE_GAME_DEMO, off by
    | default) so the flow runs end to end; with it off and `games` empty,
    | nothing of this exists on the site (no route, no link, no job).
    |
    | points: the ladder's points per place, best first (F1 style defaults,
    | to be confirmed by the user); a place beyond the list scores 0.
    | manual.grace_minutes: a manual submission is taken until this long
    | after the window closed (its `achieved_at` must still lie inside).
    | review_hours: the leaderboard is finalized this long after the window
    | closed, unless submissions still wait for an admin (a director or
    | admin can finalize earlier).
    | poller: the polite defaults of every HTTP source (user agent with a
    | contact, at most one request per `min_interval_ms`, `retries` with
    | exponential backoff from `backoff_ms`, a `Retry-After` wins up to
    | `max_retry_after_seconds`, no redirect, answers up to `max_body_bytes`
    | read within `read_deadline_seconds` in all).
    |
    */

    'score_games' => [
        'demo' => (bool) env('ESPORTS_SCORE_GAME_DEMO', false),
        'games' => [],
        'points' => [25, 18, 15, 12, 10, 8, 6, 4, 2, 1],
        'manual' => [
            'grace_minutes' => 60,
            'submissions_per_day' => 20,
        ],
        'review_hours' => 24,
        // Finishes of an account id nobody stored or confirmed are deleted after this many days (round-4 F6).
        'prune_days' => 30,
        'poller' => [
            'user_agent' => env('ESPORTS_SCORE_POLLER_USER_AGENT', 'einundzwanzig-esports (+'.env('APP_URL', 'http://localhost').')'),
            'min_interval_ms' => 1000,
            'timeout_seconds' => 10,
            'retries' => 3,
            'backoff_ms' => 2000,
            'max_retry_after_seconds' => 60,
            'max_body_bytes' => 1_048_576,
            'read_deadline_seconds' => 10,
        ],
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
    | challenges_per_day / challenges_per_recipient_per_day: how many daily
    | challenges one player may send in 24 hours, in total and to the same
    | player. A challenge is a DM to someone who may never have logged in
    | (App\Support\Chess\DailyChallenges), so the limits keep one account
    | from filling inboxes; same pattern as `opponents` below.
    |
    | challenge_dms_per_recipient_per_day: challenge notifications off the page
    | (DM, push) one player receives in 24 hours from all challengers
    | together; later challenges still arrive, in the bell and on the page.
    |
    | rated_queue: whether rated blitz is offered (P7d, App\Support\Chess\RatedChess).
    | Off until the lobby shows a Rated choice: while off, the rated queue
    | refuses and /mining and AdminSeason show chess rewards as not open,
    | because no chess win can mine. On, rated blitz still needs a live
    | season, trust ranks and two Trusted players who list each other.
    |
    */

    /*
    | Opponent lists (P7e, App\Support\SeasonChain\Opponents): how often one
    | player may change their list. Every change is a signed version the
    | league archives and publishes, so the limits keep one account from
    | flooding the archive and the relays (P7e gate, Low).
    */
    'opponents' => [
        'changes_per_minute' => (int) env('ESPORTS_OPPONENT_CHANGES_PER_MINUTE', 10),
        'changes_per_day' => (int) env('ESPORTS_OPPONENT_CHANGES_PER_DAY', 100),
        // P57: "X added you as an opponent" goes out at most this often per requester and UTC day
        // (a list made in another client can add hundreds at once); the rest still show on the page.
        'requests_per_day' => (int) env('ESPORTS_OPPONENT_REQUESTS_PER_DAY', 20),
    ],

    'chess' => [
        'rated_queue' => (bool) env('ESPORTS_RATED_CHESS', false),
        'first_move_seconds' => 30,
        'disconnect_claim_seconds' => (int) env('ESPORTS_DISCONNECT_CLAIM_SECONDS', 60),
        'challenge_hours' => 48,
        'challenges_per_day' => (int) env('ESPORTS_CHALLENGES_PER_DAY', 20),
        'challenges_per_recipient_per_day' => (int) env('ESPORTS_CHALLENGES_PER_RECIPIENT_PER_DAY', 3),
        'challenge_dms_per_recipient_per_day' => (int) env('ESPORTS_CHALLENGE_DMS_PER_RECIPIENT_PER_DAY', 10),
        'queue' => [
            'start_rating' => 1000,
            'range' => ['initial' => 150, 'step' => 150, 'every_seconds' => 30, 'max' => 600],
            // Plan "Schach Rapid und Clan" (user, 2026-10-05): after this long alone in one mode, the searching card
            // says how many search the other live mode and offers to switch (ChessQueue::switchHint).
            'switch_hint_seconds' => 30,
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
    | is not in the lobby can report a no-show (MatchRoom.dc.html: 15 min;
    | 20 since 2026-10-03, when players could not find their cup matches).
    | regions: the lobby regions offered in the match room.
    |
    | lobby_rules: the league's defaults for the lobby a host creates in the
    | game, per game (App\Support\Series\LobbyRules), for casual 1v1 and
    | clan series alike. Shown on /rules, the game page and in the match
    | room, and sent as one line with the host's lobby card. Age of Empires
    | II (plan "AoE2 und Trackmania", P9): map `map`; `civilizations`
    | `free` = each player picks any; spectators allowed with a delay of
    | `spectator_delay_minutes` (a player watching their own match from a
    | second account is a dispute); a disconnect within the first
    | `restart_minutes` of a game restarts it once with the same
    | civilisations and colours, a later one is a loss unless both agree.
    | These values are defaults pending the user's confirmation.
    |
    | `lobby`: the game's tournaments are lobby tournaments (plan "AoE2 und
    | Trackmania", P10, user 2026-10-01: "Es soll nur ein einziges Match
    | sein"), App\Support\Tournaments\Lobbies. Free for All in one round:
    | every entry plays one match in a lobby of at most `max_players`, the
    | entries split evenly over the fewest lobbies; nobody advances. At
    | least `min_entries` sign-ups, else the tournament is called off. A
    | diplomacy game: everyone starts alone (an odd number is no special
    | case), `lock_teams` off, `allied_victory` on, victory by `victory`
    | after `time_limit_minutes`. The map size follows the lobby's player
    | count (`map_sizes`), fixed with the lobby at the draw. Players report
    | the places with a screenshot of the end screen until
    | `report_minutes` after the time limit; a director confirms. A casual
    | cup of the game opens with the first of `cup_sizes` and grows through
    | them like every casual cup (above 8 a full lobby at a time), up to
    | `cup_capacity` places. `setup_minutes` plans the time to fill the
    | lobby before the game starts.
    |
    */

    'series' => [
        'respond_max_days' => 7,
        'plan_max_days' => 14,
        'now_minutes' => 10,
        'noshow_minutes' => 20,
        'regions' => ['EU', 'US-East', 'US-West', 'South America', 'Middle East', 'Oceania', 'Asia'],
        'lobby_rules' => [
            'age-of-empires-2' => [
                'map' => 'Arabia',
                'civilizations' => 'free',
                'spectator_delay_minutes' => 2,
                'restart_minutes' => 5,
                'lobby' => [
                    // Two players are a match too (user, 2026-10-04: "AoE2 hätte man doch mit 2 Teilnehmern spielen können").
                    'min_entries' => 2,
                    'max_players' => 8,
                    'map' => 'Arabia',
                    'civilizations' => 'free',
                    'population' => 200,
                    'lock_teams' => false,
                    'allied_victory' => true,
                    'victory' => 'time-limit',
                    'time_limit_minutes' => 120,
                    'spectator_delay_minutes' => 2,
                    'restarts' => 1,
                    'restart_minutes' => 5,
                    'setup_minutes' => 15,
                    'report_minutes' => 60,
                    'cup_sizes' => [4, 8, 16, 24, 32, 40],
                    'cup_capacity' => 40,
                    'map_sizes' => [2 => 'Tiny', 3 => 'Small', 4 => 'Medium', 5 => 'Normal', 6 => 'Normal', 7 => 'Large', 8 => 'Large'],
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chess team matches (plan "Schach Rapid und Clan", P4; NIP rev. 9.22)
    |--------------------------------------------------------------------------
    |
    | App\Support\Chess\ChessTeamMatches. Each captain names the players until
    | `lock_minutes` before the start; then the league orders the boards by
    | rapid Elo, and a side without a lineup loses the whole team match by
    | forfeit (user, 2026-10-05: 30 minutes, no automatic lineup). A pair of
    | clans plays one rated team match per `rated_pair_days` (rolling;
    | friendlies are not limited). A challenge "now" starts `lock_minutes`
    | plus `esports.series.now_minutes` ahead, so both captains still have
    | time to name their players before the lock. At the start every board
    | starts on its own; the side to move has `first_move_seconds` from the
    | start for the first move or loses that board by forfeit (P5, user
    | 2026-10-05: 600 seconds, as tournament rapid).
    |
    */

    'team_matches' => [
        'lock_minutes' => 30,
        'rated_pair_days' => 7,
        'first_move_seconds' => 600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Casual 1v1 without a clan (P23, App\Support\Series\CasualMatches)
    |--------------------------------------------------------------------------
    |
    | games: the games with an instant casual 1v1 (queue, direct invite);
    | `looking_to_play` takes `<game>/1v1` for each. Always unrated.
    |
    | Two players pair on the same platform, or on two platforms when both
    | allow crossplay; `crossplay_excluded` lists the platforms of a game
    | that never play cross-platform (EA FC on Switch). An empty list says
    | so on purpose: Age of Empires II excludes none, so PC, Xbox and
    | PlayStation pair when both allow crossplay. That AoE2 DE plays across
    | all three is game knowledge, not verified in the client (plan "AoE2
    | und Trackmania", P9); a default pending the user's confirmation.
    |
    | ready_seconds: both players press Ready this long after the pairing;
    | a miss voids the match (not a no-show). invite_seconds: how long a
    | direct invite stays open. rematch_seconds: how long a rematch invite
    | from the room of a finished match stays open; rematch_minutes: how long
    | after the result the room offers one.
    |
    | The deadlines below are pinned on the match at the pairing, so a later
    | change reaches only later matches. `casual:tick` applies them every
    | minute:
    |
    | - lobby_minutes: the host shares the lobby this long after the start;
    |   after that the guest may claim a no-show;
    | - join_minutes: the guest joins this long after the lobby was shared;
    |   after that the host may claim a no-show;
    | - contest_minutes: a no-show claim the accused side did not contest by
    |   then is a forfeit;
    | - report_minutes: nobody reported this long after the start: void;
    | - confirm_minutes: a report the other side did not answer by then is
    |   confirmed by the league.
    |
    | lock: `noshows` forfeited no-shows within `window_hours` lock the player
    | out of casual play (queue, invites) for `minutes` from the last one.
    |
    | Scheduled 1v1 challenges (P23 S4, App\Support\Series\CasualChallenges):
    | 1 to 3 suggested times and a reply deadline, within
    | `series.plan_max_days` / `series.respond_max_days`. The opponent picks
    | one. `reminder_minutes` before it both get a reminder; the check-in
    | opens `checkin_before_minutes` before and closes `checkin_after_minutes`
    | after it. A side that did not check in by then forfeits (it counts for
    | the lock like a no-show); neither checked in: void. Once both are in,
    | the match runs as an instant one from the lobby on.
    | challenges_per_day / challenges_per_recipient_per_day: challenges one
    | player may send in 24 hours, in total and to the same player (as
    | `chess.challenges_per_day`).
    |
    */

    'casual' => [
        'games' => ['rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27', 'age-of-empires-2'],
        'mode' => '1v1',
        'crossplay_excluded' => ['ea-sports-fc-26' => ['switch'], 'ea-sports-fc-27' => ['switch'], 'age-of-empires-2' => []],
        'ready_seconds' => (int) env('ESPORTS_CASUAL_READY_SECONDS', 60),
        'invite_seconds' => (int) env('ESPORTS_CASUAL_INVITE_SECONDS', 120),
        'rematch_seconds' => (int) env('ESPORTS_CASUAL_REMATCH_SECONDS', 60),
        'rematch_minutes' => (int) env('ESPORTS_CASUAL_REMATCH_MINUTES', 15),
        'lobby_minutes' => (int) env('ESPORTS_CASUAL_LOBBY_MINUTES', 5),
        'join_minutes' => (int) env('ESPORTS_CASUAL_JOIN_MINUTES', 10),
        'contest_minutes' => (int) env('ESPORTS_CASUAL_CONTEST_MINUTES', 5),
        'report_minutes' => (int) env('ESPORTS_CASUAL_REPORT_MINUTES', 60),
        'confirm_minutes' => (int) env('ESPORTS_CASUAL_CONFIRM_MINUTES', 30),
        'reminder_minutes' => (int) env('ESPORTS_CASUAL_REMINDER_MINUTES', 15),
        'checkin_before_minutes' => (int) env('ESPORTS_CASUAL_CHECKIN_BEFORE_MINUTES', 10),
        'checkin_after_minutes' => (int) env('ESPORTS_CASUAL_CHECKIN_AFTER_MINUTES', 10),
        'challenges_per_day' => (int) env('ESPORTS_CASUAL_CHALLENGES_PER_DAY', 10),
        'challenges_per_recipient_per_day' => (int) env('ESPORTS_CASUAL_CHALLENGES_PER_RECIPIENT_PER_DAY', 3),
        'lock' => [
            'noshows' => (int) env('ESPORTS_CASUAL_LOCK_NOSHOWS', 2),
            'window_hours' => (int) env('ESPORTS_CASUAL_LOCK_WINDOW_HOURS', 24),
            'minutes' => (int) env('ESPORTS_CASUAL_LOCK_MINUTES', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tournaments in players mode (P18, "nothing hangs")
    |--------------------------------------------------------------------------
    |
    | first_move_seconds: a tournament chess game gives the side to move this
    | long for its first move, per mode, instead of `chess.first_move_seconds`.
    | A side that misses it loses the match by forfeit (unrated, like a
    | director forfeit). Slice 2 makes this a setting of each tournament.
    | Blitz 10 minutes since 2026-10-03 (was 5): in a live cup 4 of 9 games
    | were forfeited with 0-1 moves because players did not find them in time;
    | the round clock's no-show wait went from 15 to 20 minutes with it.
    |
    | first_move_nudge_seconds: this long into a tournament game's first-move
    | window, the player still to move gets one reminder ("<opponent> is
    | waiting — your cup game is live", TournamentReminders).
    |
    | first_move_restarts: when both sides miss their first move (White did
    | not move and Black never opened the board), the game restarts this many
    | times; after that the match is decided by the double no-show rule
    | (Swiss/round robin: a loss for both; knockout: the higher seed advances).
    |
    | drawn_replays: a drawn knockout chess game is replayed with the colours
    | swapped at most this many times; after that the higher seed advances.
    |
    | unanswered_report_hours: a series report nobody confirmed or disputed
    | for this long joins the admin queue as "unanswered report".
    |
    | Deadlines per tournament (slice 2, App\Support\Tournaments\TournamentDeadlines):
    | each tournament may set its own; these are the defaults. The check-in
    | window of chess is `first_move_seconds`, the no-show wait of a series
    | `series.noshow_minutes`. report_hours: a tournament series nobody
    | reported this long after its start joins the admin queue.
    | response_minutes: a tournament series report the other side did not
    | answer for this long is confirmed by the league, unrated (CEO default);
    | a reported no-show the other side did not answer for this long is a
    | forfeit, unrated. Ladder series outside tournaments keep
    | `unanswered_report_hours`. The `tournaments:tick` command applies them.
    |
    | round_clock: an online tournament of a minute game (not daily chess) is
    | played on one day, so its series deadlines follow from the match's own
    | start instead of the long defaults above (P18, user decision 2026-09-27):
    | a no-show can be reported after `noshow_minutes`, the result is due
    | after the no-show wait, the longest play of the series
    | (GameProfile::longestPlay()) and `grace_minutes`, and the other side
    | answers within `response_minutes`. The tournament's own values still win.
    |
    | messages_per_hour: how many messages an organizer or admin may send to
    | all players of one tournament per hour (P18, TournamentControl::message()).
    |
    | reminders: minutes before the league decides a waiting match on its own
    | (P18 slice 5, TournamentReminders, run by `tournaments:tick`) at which
    | the players it waits on get a reminder, each point once per deadline
    | (comma-separated `ESPORTS_TOURNAMENT_REMINDERS`). A point is skipped when
    | the wait began inside it: a 5-minute first-move window gets no 5-minute
    | reminder. remind_every_minutes: how often an organizer or admin may
    | remind one player of one match by hand.
    |
    */

    'tournaments' => [
        'first_move_seconds' => ['blitz' => 600, 'rapid' => 600, 'correspondence' => 86400],
        'first_move_restarts' => 1,
        'drawn_replays' => 2,
        'unanswered_report_hours' => 2,
        'report_hours' => 2,
        'response_minutes' => 30,
        'round_clock' => ['noshow_minutes' => 20, 'grace_minutes' => 5, 'response_minutes' => 10],
        // Lobby check-in of a tournament series (user, 2026-10-04): this long after the start, a side that did not check in
        // while the other did is reported as a no-show by the league; it answers within `response_minutes` or forfeits.
        'auto_noshow_minutes' => (int) env('ESPORTS_TOURNAMENT_AUTO_NOSHOW_MINUTES', 30),
        'messages_per_hour' => 5,
        'reminders' => array_values(array_map(intval(...), array_filter(array_map('trim', explode(',', (string) env('ESPORTS_TOURNAMENT_REMINDERS', '30,5'))), is_numeric(...)))),
        'remind_every_minutes' => 10,
        'first_move_nudge_seconds' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic casual cups (P25, App\Support\Tournaments\CasualCups)
    |--------------------------------------------------------------------------
    |
    | enabled: the games whose cup series run (comma-separated
    | `ESPORTS_CASUAL_CUP_GAMES`). All four games run by default (Rocket
    | League and EA Sports FC since P25 S3, on the casual scheduled flow).
    | games: the mode each series plays, its name, the series lengths
    | (finals = grand final) and its weekly `slot` (user, 2026-09-30: the
    | cups spread over the weekend, Friday evening to Sunday, the two FC
    | games on Friday, the board games in the afternoon, chess and Rocket
    | League on Saturday evening, AoE2 on Sunday evening). slot: the cups'
    | default for a game without one of its own.
    |
    | regions (user, 2026-09-28: separate EU and US cups): every enabled game
    | runs one cup series per region, keyed "<game>-<region>" and named
    | "<Game> Casual Cup <label> #n"; per game and region at most one cup is
    | open at a time. A cup starts at its game's slot (`weekday` at `time`)
    | on its region's clock (`timezone`, daylight saving included), so an EU
    | and a US cup of a game start at the same local time: the first slot
    | that leaves at least min_signup_hours of sign-up. Sign-up closes at the
    | start. The first region is where the cups opened before the regions
    | went. timezone: the zone of a cup without a region and of a player
    | without one.
    |
    | sizes (P27): a cup opens with the first size of places; whenever only
    | one place is left (3/4, 7/8 ...) the league raises it to the next size,
    | up to the last, until `growth_freeze_minutes` before sign-up closes.
    | A cup full at its last size, or full once growth is frozen, starts at
    | once. At the close it plays with whoever signed up: min_players or more
    | a double elimination, 2 to min_players - 1 a small cup's live evening
    | (below); fewer than 2 extend sign-up once to the game's next slot,
    | then the cup is called off. More than 8 players play a 16-slot bracket; the top
    | seeds get the byes. gap_hours: the next cup of a game opens this long
    | after the previous final or call-off.
    |
    | Rounds: window_hours (large_window_hours with more than 8 players) from
    | the moment a round opens, which is as soon as the round before it is
    | done; max_days after the start every open match is decided at once.
    | auto_slot: a chess match nobody started is started by the league at
    | this time (the region's zone) on the window's last evening; a board
    | game match (nine men's morris, checkers; plan "Mühle und Dame", P5)
    | runs as chess, and its cup runs only while its board game is on. invite_minutes: how
    | long a "Play your cup match" invite stays open. Rocket League and EA
    | Sports FC (S3): either player proposes one to three times inside the
    | window, the other accepts one within answer_hours (at the latest by the
    | first); the league starts the series for the agreed time (or the auto
    | slot) with the casual check-in and deadlines (`esports.casual`).
    |
    | evening (P25 S2): a cup with fewer than min_players after its extension
    | still runs, as one live evening instead of round windows: 2 players
    | play one match (chess: duel_games games, colours alternating; the
    | series games a best of duel_best_of), 3 to 5 a round robin; 0 or 1 is
    | called off. It starts at `start` (the region's zone) `days_after_close`
    | days after sign-up closed; the league starts each round's games at the
    | round's start, rounds follow each other after `break_minutes`. A round
    | is decided `grace_minutes` after its planned length (the game profile's
    | game length per game). max_play_minutes: the play budget per player
    | the formats are chosen to stay within.
    |
    */

    'casual_cups' => [
        'enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_CASUAL_CUP_GAMES', 'chess,rocket-league,ea-sports-fc-26,ea-sports-fc-27,age-of-empires-2,nine-mens-morris,checkers'))))),
        'games' => [
            // Rapid 10+5 since plan "Schach Rapid und Clan", P2 (user, 2026-10-05); a cup already made keeps its `mode`.
            'chess' => ['name' => 'Chess', 'mode' => 'rapid', 'best_of' => 1, 'final_best_of' => 1, 'slot' => ['weekday' => 'saturday', 'time' => '20:00']],
            'rocket-league' => ['name' => 'Rocket League', 'mode' => '1v1', 'best_of' => 3, 'final_best_of' => 3, 'slot' => ['weekday' => 'saturday', 'time' => '20:00']],
            'ea-sports-fc-26' => ['name' => 'EA FC 26', 'mode' => '1v1', 'best_of' => 1, 'final_best_of' => 3, 'slot' => ['weekday' => 'friday', 'time' => '18:00']],
            'ea-sports-fc-27' => ['name' => 'EA FC 27', 'mode' => '1v1', 'best_of' => 1, 'final_best_of' => 3, 'slot' => ['weekday' => 'friday', 'time' => '20:00']],
            'age-of-empires-2' => ['name' => 'AoE2', 'mode' => '1v1', 'best_of' => 1, 'final_best_of' => 3, 'slot' => ['weekday' => 'sunday', 'time' => '20:00']],
            // Board games (plan "Mühle und Dame", P5): a cup runs only while the board game is switched on.
            'nine-mens-morris' => ['name' => "Nine Men's Morris", 'mode' => 'blitz', 'best_of' => 1, 'final_best_of' => 1, 'slot' => ['weekday' => 'saturday', 'time' => '15:00']],
            'checkers' => ['name' => 'Checkers', 'mode' => 'blitz', 'best_of' => 1, 'final_best_of' => 1, 'slot' => ['weekday' => 'sunday', 'time' => '15:00']],
        ],
        'slot' => ['weekday' => 'saturday', 'time' => '20:00'],
        'sizes' => [4, 8, 16],
        'growth_freeze_minutes' => 60,
        'min_players' => 6,
        // 6 to this many players play a round robin at the close instead of a double elimination full of byes.
        'round_robin_up_to' => 8,
        'regions' => [
            'eu' => ['label' => 'EU', 'timezone' => 'Europe/Berlin'],
            'us' => ['label' => 'US', 'timezone' => 'America/New_York'],
        ],
        'min_signup_hours' => 48,
        'gap_hours' => 24,
        'window_hours' => 48,
        'large_window_hours' => 36,
        'max_days' => 14,
        'auto_slot' => '20:00',
        'timezone' => 'Europe/Berlin',
        'invite_minutes' => 10,
        'answer_hours' => 12,
        'evening' => [
            'start' => '20:00',
            'days_after_close' => 1,
            'break_minutes' => 3,
            'grace_minutes' => 15,
            'duel_games' => 3,
            'duel_best_of' => 3,
            'max_play_minutes' => 60,
        ],
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

        // The site's mutes and bans as the league key's public NIP-51 mute list (kind 10000,
        // App\Support\Moderation\LeagueMuteList), carried over onto the newest list a Nostr client
        // wrote for the key (private part and foreign tags unchanged). On by default; nothing is signed
        // unless every write relay of the key's NIP-65 relay list among the league relays answered the read
        // (the quorum, security gate 2026-10-05, F1). ESPORTS_PUBLISH_MUTE_LIST=false is the kill switch:
        // nothing is read or signed, and `nostr:republish` stops sending the stored versions as well.
        'mute_list' => (bool) env('ESPORTS_PUBLISH_MUTE_LIST', true),

        // League relays the mute list writer neither reads nor counts towards its quorum: measured
        // unreachable from production on 2026-09-26 (relay.damus.io answers 403, nos.lol does not
        // connect). Comma-separated in ESPORTS_MUTE_LIST_UNREACHABLE; empty string for none.
        'mute_list_unreachable' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_MUTE_LIST_UNREACHABLE', 'wss://nos.lol,wss://relay.damus.io'))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bitcoin blocks for tournament draws (P8b, NIP 2155 `sha256-v1`)
    |--------------------------------------------------------------------------
    |
    | An Esplora-compatible API (mempool.space shape): `GET /blocks/tip/height`
    | `GET /block-height/{height}` and `GET /block/{hash}`. When sign-up closes
    | the draw commits to the next block; its hash seeds the mix teams and the
    | bracket once it has `confirmations` confirmations (NIP: about six blocks)
    | and was mined after the commitment. Read only; if the API cannot be
    | reached the draw simply waits (fail closed).
    |
    */

    'bitcoin' => [
        'api' => env('ESPORTS_BITCOIN_API', 'https://mempool.space/api'),
        'timeout_seconds' => 5,
        'confirmations' => (int) env('ESPORTS_BITCOIN_CONFIRMATIONS', 1),
        // tip: the newest block at the close seeds the draw, so it starts at once; next: the first block after it.
        'draw_block' => env('ESPORTS_BITCOIN_DRAW_BLOCK', 'tip'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fair play (P41)
    |--------------------------------------------------------------------------
    |
    | Linked accounts: an admin links the accounts of one person and names the
    | main account (App\Support\FairPlay\AccountLinks). Every other account
    | of the person plays no rated match and wins no prize; results between
    | the accounts are void. Nothing is detected automatically.
    |
    | False reports: an admin who decides a dispute against the captain who
    | reported (the reported result was false) records a confirmed false
    | report. `false_reports` of them within `window_days` days bar that
    | player from rated play for `lock_days` days from the last one
    | (App\Support\FairPlay\FairPlay::lockedUntil()). /rules states these
    | numbers, read at render time.
    |
    */

    'fair_play' => [
        'false_reports' => (int) env('ESPORTS_FAIR_PLAY_FALSE_REPORTS', 2),
        'window_days' => (int) env('ESPORTS_FAIR_PLAY_WINDOW_DAYS', 30),
        'lock_days' => (int) env('ESPORTS_FAIR_PLAY_LOCK_DAYS', 7),
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
    | Badge key (P11, NIP "Rank badges")
    |--------------------------------------------------------------------------
    |
    | Signs the NIP-58 rank badges: one definition (30009) per player, game
    | and mode, replaced on every rank change, and one award (8) per
    | definition. Signing happens on the server on every rank change, so this
    | is its own key and never the league key: a leak can forge cosmetic
    | badges, which anyone can check against the ladder, never a result.
    | Hex or nsec, in `.env` only. Without it no badge is signed (fail closed).
    |
    | artwork: the version in the badge image URLs. Clients cache images by
    | URL, so new artwork needs a new number.
    |
    */

    'badges' => [
        'nsec' => env('ESPORTS_BADGE_NSEC'),
        'artwork' => 1,
        // Share posts (kind 1) per player and hour that the league accepts and relays.
        'shares_per_hour' => 10,
        // "Show on my Nostr profile" calls (prepare and submit) per player and minute; each may check signatures.
        'profile_calls_per_minute' => 10,
        // Share card and badge image requests per IP and minute.
        'cards_per_minute' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallets: the league's (Season-Chain only) and each tournament pot's own
    |--------------------------------------------------------------------------
    |
    | Every tournament pot is its tournament's own NIP-47 wallet, connected on
    | the tournament's pages and stored encrypted with the tournament (user,
    | 2026-09-27). Tournaments never use the league wallet below.
    |
    | The league wallet belongs to the Season-Chain (the reserve, season
    | settlement later): one wallet, two NIP-47 (Nostr Wallet Connect)
    | connections, both `nostr+walletconnect://...` URIs in `.env` only:
    |
    | - `nwc_uri` can pay, with a budget limit set in the wallet;
    | - `nwc_receive_uri` only receives: `make_invoice`, `lookup_invoice`
    |   for zaps into the reserve through `pool@<host>`. The wallet's balance
    |   is never read (user, 2026-10-05).
    |
    | Fail closed: without a connection nothing is attempted; the pages say
    | so. The secrets never reach a log, an exception, a response, a Livewire
    | payload or an event (App\Support\Wallet\NwcConnection).
    |
    | Keys (hex or nsec, `.env` only): `lnurl_nsec` signs the zap receipts
    | (9735) of the league's own LNURL endpoint. `pool_npub` is the pool
    | key's public key, the recipient of zaps into the reserve; the pool key
    | itself stays offline and publishes a kind 0 whose `lud16` is
    | `<lnurl_username>@<APP_URL host>`.
    |
    | `invoice_networks`: BOLT11 prefixes accepted from wallets and Lightning
    | addresses (`bc` mainnet; tests use `bcrt`). `lnurl_insecure_hosts`:
    | `host:port` pairs whose Lightning addresses are fetched over plain
    | http without the public-address check; empty everywhere but the
    | integration suite's local fake. `lnurl_request_seconds` and
    | `lnurl_budget_seconds`: the total deadline of one request to a Lightning
    | address, and of both requests of one invoice together (P47 audit F1).
    | `nwc_insecure_relays`: the same for
    | NIP-47 relays (`host:port` reached over ws:// without the check);
    | ignored in production. Every other NWC relay is wss:// on port 443 of a
    | DNS name whose addresses are all public, and the socket is pinned to
    | the address that was checked.
    |
    | `open_invoices_per_user` / `open_invoices_per_ip`: unpaid, unexpired
    | invoices (tournament top-ups and the LNURL endpoint) one requester may
    | hold at once (an event's shared network gets the larger cap).
    |
    | `fixed_prize_max_sats` / `fixed_prizes_max_total_sats`: the largest
    | fixed prize per place, and all fixed prizes of a tournament together.
    |
    */

    'wallet' => [
        'nwc_uri' => env('ESPORTS_NWC_URI'),
        'nwc_receive_uri' => env('ESPORTS_NWC_RECEIVE_URI'),
        'nwc_timeout_seconds' => (float) env('ESPORTS_NWC_TIMEOUT', 30),
        'lnurl_nsec' => env('ESPORTS_LNURL_NSEC'),
        'pool_npub' => env('ESPORTS_POOL_NPUB'),
        'lnurl_username' => 'pool',
        // Zap amounts the league's endpoint accepts, in sats.
        'min_sats' => 1,
        'max_sats' => 10_000_000,
        'invoice_expiry_seconds' => 900,
        'invoice_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_INVOICE_NETWORKS', 'bc'))))),
        'lnurl_insecure_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_LNURL_INSECURE_HOSTS', ''))))),
        'lnurl_request_seconds' => 8,
        'lnurl_budget_seconds' => 12,
        'nwc_insecure_relays' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_NWC_INSECURE_RELAYS', ''))))),
        // How long one payout attempt may hold a payout (Lightning address, invoice, payment).
        'payout_lease_seconds' => 180,
        // How long a payment from the league wallet waits for the spend lock another payment holds (gate F1 on 8a171405).
        'spend_lock_wait_seconds' => 15,
        // Invoices anyone may open per IP and minute (zap panel and LNURL callback).
        'invoices_per_minute' => 10,
        'open_invoices_per_user' => 5,
        'fixed_prize_max_sats' => 10_000_000,
        'fixed_prizes_max_total_sats' => 50_000_000,
        'open_invoices_per_ip' => 20,
        // Sponsor invoices (organizer's page): unpaid ones per tournament, and per organizer and hour.
        // They are outside the top-up caps above, so a sponsor never uses up the organizer's own top-ups.
        'sponsor_invoices_open_per_tournament' => 3,
        'sponsor_invoices_per_hour' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Relays the league publishes to
    |--------------------------------------------------------------------------
    |
    | Comma-separated websocket URLs. Locally the ndak test bed (rnostr 7777,
    | strfry 7780, khatru 7782); empty in testing (phpunit.xml). In production
    | an unset or EMPTY `ESPORTS_RELAYS` falls back to the public set below
    | (user, 2026-09-28: „Du musst die default Relay Sets erweitern, wenn die
    | zu dünn sind, ich habe das im env nämlich leer gelassen“ — with the old
    | empty default no calendar event reached any relay). It covers the
    | account's own NIP-65 list (primal, nostr.mom; damus and nos.lol too,
    | although damus answered 403 and nos.lol was unreachable from the prod
    | server on 2026-09-26) plus the relays the stream uses from prod.
    |
    */

    'relays' => array_values(array_filter(array_map('trim', explode(',', match (env('APP_ENV')) {
        'local' => (string) (env('ESPORTS_RELAYS') ?: 'ws://127.0.0.1:7777,ws://127.0.0.1:7780,ws://127.0.0.1:7782'),
        'testing' => (string) env('ESPORTS_RELAYS', ''),
        default => (string) (env('ESPORTS_RELAYS') ?: 'wss://relay.primal.net,wss://nostr.mom,wss://relay.damus.io,wss://nos.lol,wss://relay.snort.social,wss://offchain.pub,wss://nostr.bitcoiner.social,wss://nostr.oxtr.dev'),
    })))),

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
    | one (its NIP-42 AUTH fails, measured 2026-09-26). An unset or empty
    | `ESPORTS_CHAT_RELAYS=` takes the default set (2026-09-28);
    | `ESPORTS_CHAT_RELAYS=off` switches the chat off.
    |
    | Deliberately independent of `relays` above: approving public relays for
    | encrypted chat does not approve publishing league events there.
    |
    */

    'chat' => [
        'relays' => env('ESPORTS_CHAT_RELAYS') === 'off' ? [] : array_values(array_filter(array_map('trim', explode(',', match (env('APP_ENV')) {
            'local' => (string) (env('ESPORTS_CHAT_RELAYS') ?: 'ws://127.0.0.1:7777,ws://127.0.0.1:7780,ws://127.0.0.1:7782'),
            'testing' => (string) env('ESPORTS_CHAT_RELAYS', ''),
            default => (string) (env('ESPORTS_CHAT_RELAYS') ?: 'wss://nos.lol,wss://relay.primal.net,wss://nostr.mom'),
        })))),
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
    | Which kind may go out by push or DM at all: NotificationKind::dmAllowed()
    | and pushAllowed() (the audit table is there).
    |
    | on_site_seconds (App\Support\Notifications\OnSite): a player whose
    | page was visible this recently is on the site. Push and DM are not sent
    | to them at all (not held back): the bell and the toast reach them. Pages
    | ping every 30 s while visible and stop when hidden or closed.
    |
    | your_move (App\Support\Notifications\YourMoveThrottle): "your move" in a
    | correspondence game is never a DM, and a push only while the player is
    | not playing. at_board_minutes: none if the player made their own
    | previous move in that game less than this long ago. per_game_minutes:
    | at most one per player and game in this span. The bell gets every move.
    | 0 switches a rule off.
    |
    */

    'notifications' => [
        'nsec' => env('ESPORTS_NOTIFICATION_NSEC'),
        'name' => 'TWENTY ONE esports notifications',
        // P5c: seconds an "Opponent found" toast counts down before it opens the game.
        'countdown_seconds' => 5,
        'on_site_seconds' => (int) env('ESPORTS_ON_SITE_SECONDS', 75),
        'your_move' => [
            'at_board_minutes' => (int) env('ESPORTS_YOUR_MOVE_AT_BOARD_MINUTES', 15),
            'per_game_minutes' => (int) env('ESPORTS_YOUR_MOVE_PER_GAME_MINUTES', 60),
        ],
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

    /*
    | The live stream around the site (P20b): every page asks GET
    | /stream/status this often while it is visible (resources/js/liveFeed.js),
    | and an IP may ask this often per minute (a LAN party shares one IP).
    */
    'live' => [
        'poll_seconds' => 15,
        'status_per_minute' => 240,
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
    | (SEASON-CHAIN.md, decisions 5, 8 and 13). The planned Block 0, the
    | supply and the genesis message are the board's chain draft on the
    | admin season page (ChainDraft, P43), not settings of the server.
    |
    | display_timezone: zone for dates shown to guests and to players without
    | a timezone of their own.
    |
    */

    'preseason' => [
        'display_timezone' => 'Europe/Berlin',
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream chat bot (P22)
    |--------------------------------------------------------------------------
    |
    | Short NIP-53 live chat messages (kind 1311) under the 24/7 stream's
    | kind-30311 event (`twentyone.stream`: its key, `d` and relays), from
    | its own key: tips about the site and current facts from the database,
    | each with a direct link (App\Support\StreamBot). `twentyone:stream-bot`
    | runs every minute and decides itself whether to post.
    |
    | Off unless `enabled` AND a valid `nsec` (hex or nsec, `.env` only; never
    | the league or the stream key). Its kind 0 (`--profile`) says `bot: true`.
    |
    | Cadence: only while the stream is live (its session file names an
    | accepted `live` 30311 younger than `live_minutes` and the public
    | playlist is fresh); at most one post every `interval_minutes` ±
    | `jitter_minutes`; never two in a row unless a human wrote in the chat
    | in between or `alone_minutes` passed; at most `daily_cap` per day in
    | `timezone`; nothing during `quiet_hours` (`"23-07"` or
    | `"22:30-07:00"`; empty = none, unreadable = always quiet).
    |
    | `chat_relays`: where the bot looks for human chat messages (one REQ,
    | kind 1311 with the stream's `a` tag, since its last post). Empty = it
    | never sees any, so only the `alone_minutes` rule lets it post again.
    |
    | Rotation: no builder again within `builder_gap` posts, no fact (a
    | tournament, a game, a feature tip) again within `repeat_hours`.
    |
    */

    'stream_bot' => [
        'enabled' => (bool) env('ESPORTS_STREAM_BOT_ENABLED', false),
        'nsec' => env('ESPORTS_STREAM_BOT_NSEC'),
        'chat_relays' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_STREAM_BOT_CHAT_RELAYS', ''))))),
        'quiet_hours' => env('ESPORTS_STREAM_BOT_QUIET_HOURS'),
        'timezone' => 'Europe/Berlin',
        'interval_minutes' => 20,
        'jitter_minutes' => 5,
        'alone_minutes' => 45,
        'daily_cap' => 24,
        'builder_gap' => 4,
        'repeat_hours' => 12,
        // A failed post (no relay accepted) is tried again after this long.
        'retry_minutes' => 5,
        // The stream counts as live while its last accepted `live` 30311 is this young (republished every 20 min) ...
        'live_minutes' => 30,
        // ... and the public playlist was written within this many seconds.
        'playlist_fresh_seconds' => 60,
        // How far back "recent" facts reach.
        'winner_days' => 14,
        'clan_days' => 7,
        'rank_up_hours' => 48,
        // Kind-1 notes on the bot's own profile, one per published tournament (twentyone:stream-bot:tournaments):
        // at most `per_run` per run (the backlog goes out a few at a time), a failed send is retried
        // after `retry_minutes` with the same signed event.
        'tournament_notes' => [
            'per_run' => 3,
            'retry_minutes' => 10,
        ],
        // Pride notes on the same profile (twentyone:stream-bot:pride, PrideNotes): the dynamic stream
        // slides with their players tagged, each type at most once a day in its slot (from `time` for
        // `window_hours`, in its zone) and only when its text changed. Spread for EU and US readers.
        'pride_notes' => [
            'enabled' => (bool) env('ESPORTS_STREAM_BOT_PRIDE_NOTES', true),
            'window_hours' => 3,
            'retry_minutes' => 10,
            // The rendered slides the notes link to (kept 30 days), served at /stream/pride/<hash>.png.
            'image_dir' => storage_path('app/stream/pride'),
            'slots' => [
                'climbers' => ['time' => '12:00', 'timezone' => 'Europe/Berlin'],
                'signups' => ['time' => '13:00', 'timezone' => 'America/New_York'],
                'win' => ['time' => '19:00', 'timezone' => 'Europe/Berlin'],
                'prizes' => ['time' => '19:00', 'timezone' => 'America/New_York'],
            ],
        ],
        // A finished tournament's champion on the same profile (twentyone:stream-bot:champions, ChampionNotes): one note
        // per special tournament or casual cup with a single champion, with its rendered champion slide; only those
        // finished within `days` (no flood of old ones), at most `per_run` per run, a failed send retried after
        // `retry_minutes` with the same signed event. The slides are kept with the pride slides (`pride_notes.image_dir`).
        'champion_notes' => [
            'enabled' => (bool) env('ESPORTS_STREAM_BOT_CHAMPION_NOTES', true),
            'days' => 5,
            'per_run' => 3,
            'retry_minutes' => 10,
        ],
        // The GG in the stream chat the moment a tournament is decided (twentyone:stream-bot:gg, ChampionChat): once per
        // tournament, past the rotation's interval, cap and never-twice rule, but only on air and outside the quiet hours,
        // and only within `window_minutes` of the finish (a late GG is no GG). A send no relay took is retried after
        // `retry_minutes` with the same signed event.
        'gg' => [
            'window_minutes' => (int) env('ESPORTS_STREAM_BOT_GG_WINDOW_MINUTES', 30),
            'retry_minutes' => 2,
        ],
        // Reminders on the same profile while a tournament still has free places (P49,
        // twentyone:stream-bot:free-places): one note per slot, a slot being hours before sign-up
        // closes. A slot is due from its moment until the next slot's moment (or the stop), and
        // only the latest due slot is posted, so a missed slot is skipped, never posted late.
        // Nothing within `stop_before_close_minutes` of the close, at most `per_run` per run.
        'free_places' => [
            'special_slots_hours' => [168, 72, 24, 3],
            'cup_slots_hours' => [24, 3],
            'stop_before_close_minutes' => 60,
            'per_run' => 3,
            'retry_minutes' => 10,
        ],
        // Blockfill's notes on the same profile (twentyone:stream-bot:blockfill, BlockfillNotes): a verified
        // run that takes the running week's first place gets a note, but no sooner than `top_minutes` after
        // the bot's last Blockfill note, and none in the week's last hour, when the winner note is next.
        // Several first places since the last such note become one summary note naming everyone who held it.
        'blockfill_notes' => [
            'top_minutes' => 60,
            'top_on_profile' => true,
        ],
        // TMNF's week notes, the same rules (twentyone:stream-bot:tmnf, TmnfNotes); its new best times go
        // to the stream chat instead of the profile (StreamBotBuilders `tmnf_top`, user 2026-10-02).
        'tmnf_notes' => [
            'top_minutes' => 60,
            'top_on_profile' => false,
        ],
        // Pacing of the profile notes per note type (ProfileNotes, user 2026-10-02: the profile looked
        // spammy): `cooldown_minutes` after the type's last delivered note, at most `daily_cap` delivered
        // per Berlin day; 0 or a missing type is no limit. A held-back note goes out on a later run,
        // unless its own window closed meanwhile (a free-places slot, a first place gone stale).
        'profile_limits' => [
            'tournament' => ['cooldown_minutes' => 60, 'daily_cap' => 6],
            'free_places' => ['cooldown_minutes' => 60, 'daily_cap' => 6],
            'blockfill_top' => ['cooldown_minutes' => 0, 'daily_cap' => 3],
            'tmnf_top' => ['cooldown_minutes' => 0, 'daily_cap' => 3],
        ],
        'profile' => [
            'name' => 'TWENTY ONE Bot',
            'about' => 'The bot of the TWENTY ONE Esports stream chat: what is on at esports.einundzwanzig.space, with links. Chats only while the stream is live, and posts every new tournament here. It reads no replies.',
            'picture' => 'https://blossom.einundzwanzig.space/c6f8d996841c1a1b81102ff268a9f4408536a17fb35dfb87eb71b407bad41d8f.png',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stream chat on /live (P24)
    |--------------------------------------------------------------------------
    |
    | The NIP-53 live chat of the 24/7 stream, read and written straight from
    | the browser (resources/js/liveChat.js): kind 1311 (and zap receipts,
    | kind 9735) with the stream's `a` tag. The server never talks to a relay
    | for it and stores nothing but a viewer's own mutes (ChatMute).
    |
    | `relays`: where the page reads and posts. Unset = the stream's relays
    | (`twentyone.stream.relays`) plus the bot's `chat_relays`; set but empty
    | = no chat (the tests force that, so no page ever reaches a real relay).
    |
    | Zap receipts shown (NIP-57: the receipt is signed by the recipient's
    | LNURL server; anyone can publish a 9735, so any other signer's receipt
    | is dropped): those of the league's own server (`esports.wallet.
    | lnurl_nsec`, behind `pool@<host>`, the profile's lud16 since
    | 2026-10-03: stream zaps go to the league reserve), always, and those of
    | `zap_signers`, the servers of earlier addresses, so their receipts
    | still show. The default is the `nostrPubkey` of `zap_signers_lud16`,
    | theben@getalby.com (the profile's lud16 until 2026-10-03), read from
    | https://getalby.com/.well-known/lnurlp/theben on 2026-09-28. A
    | request's `lnurl`, when it names one, must be its signer's: getalby
    | also signs receipts for everybody else's zaps.
    |
    | `zap_recipient`: whom a stream zap pays (hex or npub); unset = the pool
    | key (`esports.wallet.pool_npub`, the profile key), else the stream key.
    | Receipt and request must both carry it as `p`.
    |
    */

    'stream_chat' => [
        'relays' => env('ESPORTS_STREAM_CHAT_RELAYS') === null
            ? null
            : array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_STREAM_CHAT_RELAYS'))))),
        'zap_signers' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'ESPORTS_STREAM_ZAP_SIGNERS',
            '79f00d3f5a19ec806189fcab03c1be4ff81d18ee4f653c88fac41fe03570f432',
        ))))),
        'zap_signers_lud16' => env('ESPORTS_STREAM_ZAP_SIGNERS_LUD16', 'theben@getalby.com'),
        // The legacy signers count only receipts made before this unix time: the profile's lud16 left getalby on
        // 2026-10-03, and getalby's one key signs for every Alby user, so a fresh receipt could be anyone's (audit L1).
        'zap_signers_until' => (int) env('ESPORTS_STREAM_ZAP_SIGNERS_UNTIL', 1791590400),
        'zap_recipient' => env('ESPORTS_STREAM_ZAP_RECIPIENT'),
        // Characters one message may have, and the pause between two posts of one browser.
        'max_length' => 280,
        'cooldown_ms' => 2000,
        // Messages per page: the newest ones when the page opens, then each older page scrolled up to.
        'history' => 50,
        // Relay-list indexers (NIP-65 10002): where the chat looks up the write relays of a profile the
        // profile and chat relays do not have. Tests set their own (empty by default).
        'indexer_relays' => array_values(array_filter(array_map('trim', explode(',', env('APP_ENV') === 'testing'
            ? (string) env('ESPORTS_STREAM_CHAT_INDEXERS', '')
            : ((string) env('ESPORTS_STREAM_CHAT_INDEXERS') ?: 'wss://purplepag.es,wss://user.kindpag.es'))))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Game channels (P21, NIP "Game channels")
    |--------------------------------------------------------------------------
    |
    | The global chat of each game on its overview page (/chess, /games/{slug}):
    | a NIP-28 public channel per game (kind 40, messages kind 42) with NIP-88
    | polls (kind 1068, votes kind 1018), read and written in the browser on the
    | chat relays (`chat.relays`); the server never sees a message and stores
    | only a viewer's own mutes (ChatMute).
    |
    | `creator`: the pubkey (npub or hex) whose kind 40 names each channel.
    | Unset = the league key's pubkey. The channel ids follow from it
    | (App\Support\GameChat\GameChannels); `php artisan esports:game-channels`
    | signs and publishes the kind 40 and 41 with the league key.
    |
    | `publish_relays`: where that command publishes the kind 40 and 41 on top
    | of the chat relays (comma-separated `ESPORTS_GAME_CHANNEL_RELAYS`; an
    | unset or empty one takes the default set; nothing locally and in
    | testing, where the chat relays suffice). The default (user, 2026-10-05: „Mehr
    | Relays einsetzen und Relays dürfen soft failen!") adds the public relays
    | that answered from the prod server on 2026-09-26 (primal, nostr.mom,
    | snort, offchain.pub, nostr.bitcoiner.social, nostr.oxtr.dev). A relay
    | that refuses (rate limit, a newer kind 41, a timeout) is logged and
    | never fails the run. The kind 41 still names only the chat relays.
    |
    | Polls: a question of at most `question_max` characters, 2 to
    | `max_options` answers of at most `option_max`, closing after one of
    | `durations` (seconds). Only polls and votes of accounts that count are
    | shown and counted: members, and players with a result in the league
    | (GameChannels::players()).
    |
    */

    'game_chat' => [
        'creator' => env('ESPORTS_GAME_CHAT_CREATOR'),
        'publish_relays' => array_values(array_filter(array_map('trim', explode(',', match (env('APP_ENV')) {
            'local', 'testing' => (string) env('ESPORTS_GAME_CHANNEL_RELAYS', ''),
            default => (string) (env('ESPORTS_GAME_CHANNEL_RELAYS') ?: 'wss://relay.primal.net,wss://nostr.mom,wss://relay.snort.social,wss://offchain.pub,wss://nostr.bitcoiner.social,wss://nostr.oxtr.dev'),
        })))),
        'max_length' => 280,
        'cooldown_ms' => 2000,
        'history' => 120,
        'poll' => [
            'question_max' => 140,
            'option_max' => 60,
            'max_options' => 4,
            'durations' => [3600, 86400, 3 * 86400, 7 * 86400],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Comments, likes and RSVPs on Nostr (P48)
    |--------------------------------------------------------------------------
    |
    | NIP-22 comments (kind 1111) and NIP-25 likes (kind 7) on a tournament's
    | calendar event, a rated game's record and a rated series' challenge, and
    | NIP-52 RSVPs (kind 31925) to a tournament; each signed by the player on
    | click and relayed by the league (App\Support\Comments). The browser reads
    | them from at most `read_relays` of the league relays (`relays` above).
    |
    | per_hour: what the league accepts and relays per player and hour, every
    | attempt counted. page: comments per read ("Load more" reads the next
    | page), at most `max_shown` on one page view. max_length: characters of
    | one comment. read_limit: reactions and RSVPs read per page view.
    |
    */

    'comments' => [
        'max_length' => 1000,
        'page' => 20,
        'max_shown' => 200,
        'read_limit' => 500,
        'read_relays' => 5,
        'per_hour' => [
            'comments' => 20,
            'reactions' => 60,
            'rsvps' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Blockfill (plan "Blockfill", P2)
    |--------------------------------------------------------------------------
    |
    | Our own stacking game: the league hands out a run (one-time token and
    | seed), the browser plays it on resources/js/stacker, and a queued Node
    | verifier replays the submitted inputs (App\Support\Stacker\StackerRuns).
    | enabled: the switch (`ESPORTS_BLOCKFILL`, off by default); off, the
    | routes in routes/stacker.php are not registered at all. engine: the
    | engine version new runs are issued on (frozen versions stay playable
    | for replays). start_seconds: a token that is not started within this
    | long expires; the page asks for a run right before its countdown, so
    | the seed is known at most this long before the clock runs. limits:
    | what a submission may carry (ticks of play, inputs, bytes of the
    | request body). slack_seconds: the wall-clock bracket, the time between
    | start and submission must be at least the played time and at most
    | this much more. verifier: the Node process (binary, heap limit,
    | timeout), the queue its job runs on (the queue worker must listen on
    | it too) and stale_minutes, after which a verification still running is
    | given up as pending (`stacker:sweep`; `stacker:reverify` sends pending
    | runs again). Rate limits: per player (issue_per_hour plus one issue per
    | issue_every_seconds, submits_per_minute), per network (IPv4 address
    | or IPv6 /64: issue_per_ip_per_hour and issue_per_ip_per_minute,
    | submits_per_ip_per_minute; the main control) and for everyone together
    | (issue_global_per_minute, only a circuit breaker with a fixed window:
    | with 30 issues per network and minute, a burst has to come from at
    | least 100 networks to reach it, and while it is reached nobody can
    | start a run until the minute is over). limits.inputs_per_tick and
    | limits.input_slack bound a run's inputs by its played time (at most
    | ceil(ticks * inputs_per_tick) + input_slack; measured bot runs use 0.76
    | to 0.83 per tick). The played time is the player's choice, so a replay
    | can still reach ~33 KB at the 36,000-tick limit. Storage: a verified
    | run keeps its replay only while it is among the replay_keep_top
    | fastest of its week (Monday 00:00 Berlin), or among the
    | replay_keep_shared fastest of that week that are moments or were
    | shared (a moment's page plays the replay, so a shared link keeps
    | working), so at most the sum of both is kept per week; every other run
    | keeps ticks and hash only,
    | practice and rejected runs keep no replay. Runs waiting for the
    | verifier (verifying, pending) hold their submitted replay; at most
    | replay_inflight_max of them at once, inflight_per_account per account
    | and inflight_per_network per network (a submission beyond that is
    | answered 503 and nothing is stored). The sweep sends pending runs with
    | a replay again by itself: redrive_batch of them while the verifier
    | answers, one as a probe otherwise. A pending run drops its replay
    | after pending_replay_hours (it can then not be verified any more; the
    | player plays again). prune_days, after which runs
    | without a verified time are deleted (`model:prune`, daily).
    |
    */

    'blockfill' => [
        'enabled' => (bool) env('ESPORTS_BLOCKFILL', false),
        'engine' => 'bf1',
        'start_seconds' => 10,
        'limits' => [
            'ticks' => 36000,
            'inputs' => 20000,
            'bytes' => 65536,
            'inputs_per_tick' => 1.0,
            'input_slack' => 64,
        ],
        'slack_seconds' => 20,
        'issue_every_seconds' => 2,
        'issue_per_hour' => 400,
        'submits_per_minute' => 30,
        'issue_per_ip_per_hour' => 1200,
        'issue_per_ip_per_minute' => 30,
        'submits_per_ip_per_minute' => 90,
        'issue_global_per_minute' => 3000,
        'replay_keep_top' => 100,
        'replay_keep_shared' => 500,
        'replay_inflight_max' => 2000,
        'inflight_per_account' => 3,
        'inflight_per_network' => 50,
        'redrive_batch' => 200,
        'pending_replay_hours' => 24,
        'prune_days' => 30,
        'verifier' => [
            'node' => env('ESPORTS_BLOCKFILL_NODE', 'node'),
            'script' => 'js/stacker/verify.mjs',
            'heap_mb' => 64,
            'timeout_seconds' => 5,
            'queue' => 'stacker-verify',
            'stale_minutes' => 10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TrackMania Nations Forever (plan "Trackmania und Restposten")
    |--------------------------------------------------------------------------
    |
    | Our own TMNF dedicated server, read over its XML-RPC port (GBXRemote 2,
    | App\Support\Tmnf). enabled: the switch for everything of it
    | (`ESPORTS_TMNF`, off by default); off, the game is not registered: no
    | page, no link, no gamer tag field, no job, and `tmnf:listen` waits.
    |
    | xmlrpc: where the listener reaches the server and the authorization
    | level it logs in as (the SuperAdmin password stays in .env; locally
    | scripts/tmnf-server.sh writes it). server: what players see on the
    | week page to join (`address` is the host:port or the server login;
    | null hides the line). listener: the reconnect backoff of `tmnf:listen`.
    | link: how long a one-time chat code to link a login is valid.
    | tracks: the tracks an admin may pick for a week (/admin/league-weeks),
    | by their UID; `author_ms` is the track's author time, `file` where the
    | server finds it (relative to GameData/Tracks; the listener switches
    | the server with it). Only the very first draft, with no week before
    | it, takes entry N mod count for week N of the cycle. outlier_margin_ms: a finish faster than the
    | author time minus this margin is flagged for an admin, and held only
    | while it would enter the week's top `outlier_top` (else it counts at once).
    |
    */

    'tmnf' => [
        'enabled' => (bool) env('ESPORTS_TMNF', false),
        'xmlrpc' => [
            'host' => env('TMNF_XMLRPC_HOST', '127.0.0.1'),
            'port' => (int) env('TMNF_XMLRPC_PORT', 5005),
            'user' => env('TMNF_XMLRPC_USER', 'SuperAdmin'),
            'password' => env('TMNF_XMLRPC_PASSWORD'),
            'timeout_seconds' => 5,
        ],
        'server' => [
            'name' => env('TMNF_SERVER_NAME', 'TWENTY ONE'),
            'address' => env('TMNF_SERVER_ADDRESS'),
            // The server's master-server login. A free Nations account may only join a player-hosted server
            // from its Favourites, so How to join hands out tmtp://#addfavourite=<login> (FreeZone FAQ, Nadeo 2010).
            'login' => env('TMNF_SERVER_LOGIN'),
        ],
        'listener' => [
            'backoff_initial_seconds' => 1,
            'backoff_max_seconds' => 60,
        ],
        'link' => [
            'code_minutes' => 30,
        ],
        'tracks' => [
            // Nadeo's stock tracks of the Nations campaign A01 to E05 (White, Green, Blue, Red, Black; Stadium), shipped
            // with the game and the dedicated server. UId, name, author and author time are what Nadeo's dedicated server
            // (build 2011-02-21, scripts/tmnf-server.sh) answered to GetChallengeInfo for each `file` on 2026-10-02; the
            // files lie under GameData/Tracks/Campaigns/Nations/ in the server archive (docker/tmnf/Dockerfile).
            'BeySZdnfuSh4nHY5xztiXLmlrXe' => ['name' => 'A01-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 24_540, 'file' => 'Campaigns\Nations\White\A01-Race.Challenge.Gbx'],
            'JwKdDsOUh4L9_eYyRsdiA2o1fW1' => ['name' => 'A02-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 16_250, 'file' => 'Campaigns\Nations\White\A02-Race.Challenge.Gbx'],
            'mWxQhvvPOoNfPaq18j3dokLqyO7' => ['name' => 'A03-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 18_750, 'file' => 'Campaigns\Nations\White\A03-Race.Challenge.Gbx'],
            'SEHmwPJVBl3NpHS56w6Sirac2Ic' => ['name' => 'A04-Acrobatic', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 5950, 'file' => 'Campaigns\Nations\White\A04-Acrobatic.Challenge.Gbx'],
            'I7rI7jAga6C4tGAe5OTDoyLF2fh' => ['name' => 'A05-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 16_910, 'file' => 'Campaigns\Nations\White\A05-Race.Challenge.Gbx'],
            '8oDWqaNMXpyFg6e_QUb07Wzpkk3' => ['name' => 'B01-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 26_200, 'file' => 'Campaigns\Nations\Green\B01-Race.Challenge.Gbx'],
            'b4ubJL0Aayrg7aqhe0RwI4jBQR1' => ['name' => 'B02-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 27_410, 'file' => 'Campaigns\Nations\Green\B02-Race.Challenge.Gbx'],
            'HIH70OwdsvC7ZX_oKOWhwqDITx2' => ['name' => 'B03-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 27_110, 'file' => 'Campaigns\Nations\Green\B03-Race.Challenge.Gbx'],
            'OfPhGxBSu5zHgl3GsndJGGens8k' => ['name' => 'B04-Acrobatic', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 13_020, 'file' => 'Campaigns\Nations\Green\B04-Acrobatic.Challenge.Gbx'],
            'R2W9o_MsXRP2PNp46stzmMWkgHb' => ['name' => 'B05-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 26_280, 'file' => 'Campaigns\Nations\Green\B05-Race.Challenge.Gbx'],
            'eDgWjoKe2dT3GfoTCGCmI_qMvfk' => ['name' => 'C01-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 29_580, 'file' => 'Campaigns\Nations\Blue\C01-Race.Challenge.Gbx'],
            'hlRjJEZGm0yr1sT91CtdIwmqsti' => ['name' => 'C02-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 42_470, 'file' => 'Campaigns\Nations\Blue\C02-Race.Challenge.Gbx'],
            'c4oQLgleEPkNtehypwdYXTkmVvi' => ['name' => 'C03-Acrobatic', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 13_900, 'file' => 'Campaigns\Nations\Blue\C03-Acrobatic.Challenge.Gbx'],
            'yWy7ROt2lgk2zL44HKdBgUjuthi' => ['name' => 'C04-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 39_800, 'file' => 'Campaigns\Nations\Blue\C04-Race.Challenge.Gbx'],
            'UR7xWwTkMeFB2kqVLVVOGDBCKFb' => ['name' => 'C05-Endurance', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 116_390, 'file' => 'Campaigns\Nations\Blue\C05-Endurance.Challenge.Gbx'],
            'E0ZXX6DbQ1wZXMiLYW77zgjFcB9' => ['name' => 'D01-endurance', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 153_260, 'file' => 'Campaigns\Nations\Red\D01-endurance.Challenge.Gbx'],
            'r7OqCgR3yODNwmJcPGyUafJKRAh' => ['name' => 'D02-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 52_630, 'file' => 'Campaigns\Nations\Red\D02-Race.Challenge.Gbx'],
            'KvPlXufFJaLjDGSRP1rcregOaX3' => ['name' => 'D03-Acrobatic', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 15_940, 'file' => 'Campaigns\Nations\Red\D03-Acrobatic.Challenge.Gbx'],
            'h1doGRJ46hAGF3AeCJPsPHE_Vb2' => ['name' => 'D04-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 52_860, 'file' => 'Campaigns\Nations\Red\D04-Race.Challenge.Gbx'],
            'iDnUBYbFhfD0QIPmIyRvy1UIw_0' => ['name' => 'D05-Race', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 71_430, 'file' => 'Campaigns\Nations\Red\D05-Race.Challenge.Gbx'],
            'tURAqsuKtDsCV7B0bWEszBV78Re' => ['name' => 'E01-Obstacle', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 45_560, 'file' => 'Campaigns\Nations\Black\E01-Obstacle.Challenge.Gbx'],
            'FW0uBAWV9d_9K9Dl2ZTYMn6iJHd' => ['name' => 'E02-Endurance', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 277_480, 'file' => 'Campaigns\Nations\Black\E02-Endurance.Challenge.Gbx'],
            'DTq2M5w_EKfxPgXLRJ29kHndMYj' => ['name' => 'E03-Endurance', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 329_780, 'file' => 'Campaigns\Nations\Black\E03-Endurance.Challenge.Gbx'],
            'xedOkArRfmIHfm9VFnKrLsOQ4hd' => ['name' => 'E04-Obstacle', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 121_060, 'file' => 'Campaigns\Nations\Black\E04-Obstacle.Challenge.Gbx'],
            'eMBnCjky7WmlP9G0R9xOrNFQV7c' => ['name' => 'E05-Endurance', 'author' => 'Nadeo', 'environment' => 'Stadium', 'author_ms' => 3_605_940, 'file' => 'Campaigns\Nations\Black\E05-Endurance.Challenge.Gbx'],
        ],
        'outlier_margin_ms' => 1_500,
        'outlier_top' => 10,
        // The in-game overlay (TmnfOverlay): the week's top 5 and the own line, the site, a finish note. On while
        // TMNF is on. board_seconds: a burst of finishes updates the board for everyone at most once in this time.
        'overlay' => [
            'enabled' => (bool) env('TMNF_OVERLAY', true),
            'board_seconds' => 5,
            'note_seconds' => 3,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | League weeks: the admins approve every week (user 2026-10-02)
    |--------------------------------------------------------------------------
    |
    | The weekly leaderboards the league opens by itself (Blockfill, TMNF)
    | start only once an admin approved them on /admin/league-weeks
    | (App\Support\Scores\LeagueWeekDrafts). The next week is made as a draft
    | with the previous week's settings at `draft_weekday` (ISO, 1 = Monday)
    | `draft_time` (Europe/Berlin) of the week before; the admins get a bell
    | entry, and a reminder `reminder_hours` before the planned start
    | (Monday 00:00 Berlin) while it is still not approved. An approved week
    | starts at its planned start, or at its approval when that came later;
    | it ends on the following Monday 00:00 either way. Not approved, no
    | week runs.
    |
    */

    'league_weeks' => [
        'draft_weekday' => 4,
        'draft_time' => '12:00',
        'reminder_hours' => 24,
    ],

];
