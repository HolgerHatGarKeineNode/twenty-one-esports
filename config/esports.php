<?php

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

    'games' => [
        Chess::class,
        RocketLeague::class,
        EaSportsFc27::class,
        EaSportsFc26::class,
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
    | it or the game is aborted (as `chess.first_move_seconds`).
    |
    */

    'board_games' => [
        'enabled' => (bool) env('ESPORTS_BOARD_GAMES', false),
        'first_move_seconds' => 30,
        'games' => [
            'nine-mens-morris' => ['enabled' => (bool) env('ESPORTS_BOARD_GAME_NINE_MENS_MORRIS', false), 'class' => NineMensMorris::class],
            'checkers' => ['enabled' => (bool) env('ESPORTS_BOARD_GAME_CHECKERS', false), 'class' => Checkers::class],
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
    | Casual 1v1 without a clan (P23, App\Support\Series\CasualMatches)
    |--------------------------------------------------------------------------
    |
    | games: the games with an instant casual 1v1 (queue, direct invite);
    | `looking_to_play` takes `<game>/1v1` for each. Always unrated.
    |
    | Two players pair on the same platform, or on two platforms when both
    | allow crossplay; `crossplay_excluded` lists the platforms of a game
    | that never play cross-platform (EA FC on Switch).
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
        'games' => ['rocket-league', 'ea-sports-fc-26', 'ea-sports-fc-27'],
        'mode' => '1v1',
        'crossplay_excluded' => ['ea-sports-fc-26' => ['switch'], 'ea-sports-fc-27' => ['switch']],
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
        'first_move_seconds' => ['blitz' => 300, 'correspondence' => 86400],
        'first_move_restarts' => 1,
        'drawn_replays' => 2,
        'unanswered_report_hours' => 2,
        'report_hours' => 2,
        'response_minutes' => 30,
        'round_clock' => ['noshow_minutes' => 15, 'grace_minutes' => 5, 'response_minutes' => 10],
        'messages_per_hour' => 5,
        'reminders' => array_values(array_map(intval(...), array_filter(array_map('trim', explode(',', (string) env('ESPORTS_TOURNAMENT_REMINDERS', '30,5'))), is_numeric(...)))),
        'remind_every_minutes' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic casual cups (P25, App\Support\Tournaments\CasualCups)
    |--------------------------------------------------------------------------
    |
    | enabled: the games whose cup series run (comma-separated
    | `ESPORTS_CASUAL_CUP_GAMES`). All four games run by default (Rocket
    | League and EA Sports FC since P25 S3, on the casual scheduled flow).
    | games: the mode each series plays, its name, and the series lengths
    | (finals = grand final).
    |
    | regions (user, 2026-09-28: separate EU and US cups): every enabled game
    | runs one cup series per region, keyed "<game>-<region>" and named
    | "<Game> Casual Cup <label> #n"; per game and region at most one cup is
    | open at a time. A cup starts at its region's slot (`weekday` at `time`
    | in `timezone`, daylight saving included): the first one that leaves at
    | least min_signup_hours of sign-up. Sign-up closes at the start. The
    | first region is where the cups opened before the regions went.
    | timezone: the zone of a cup without a region and of a player without
    | one.
    |
    | sizes (P27): a cup opens with the first size of places; whenever only
    | one place is left (3/4, 7/8 ...) the league raises it to the next size,
    | up to the last, until `growth_freeze_minutes` before sign-up closes.
    | A cup full at its last size, or full once growth is frozen, starts at
    | once. At the close it plays with whoever signed up: min_players or more
    | a double elimination, 2 to min_players - 1 a small cup's live evening
    | (below); fewer than 2 extend sign-up once to the region's next slot,
    | then the cup is called off. More than 8 players play a 16-slot bracket; the top
    | seeds get the byes. gap_hours: the next cup of a game opens this long
    | after the previous final or call-off.
    |
    | Rounds: window_hours (large_window_hours with more than 8 players) from
    | the moment a round opens, which is as soon as the round before it is
    | done; max_days after the start every open match is decided at once.
    | auto_slot: a chess match nobody started is started by the league at
    | this time (the region's zone) on the window's last evening. invite_minutes: how
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
        'enabled' => array_values(array_filter(array_map('trim', explode(',', (string) env('ESPORTS_CASUAL_CUP_GAMES', 'chess,rocket-league,ea-sports-fc-26,ea-sports-fc-27'))))),
        'games' => [
            'chess' => ['name' => 'Chess', 'mode' => 'blitz', 'best_of' => 1, 'final_best_of' => 1],
            'rocket-league' => ['name' => 'Rocket League', 'mode' => '1v1', 'best_of' => 3, 'final_best_of' => 3],
            'ea-sports-fc-26' => ['name' => 'EA FC 26', 'mode' => '1v1', 'best_of' => 1, 'final_best_of' => 3],
            'ea-sports-fc-27' => ['name' => 'EA FC 27', 'mode' => '1v1', 'best_of' => 1, 'final_best_of' => 3],
        ],
        'sizes' => [4, 8, 16],
        'growth_freeze_minutes' => 60,
        'min_players' => 6,
        'regions' => [
            'eu' => ['label' => 'EU', 'timezone' => 'Europe/Berlin', 'weekday' => 'saturday', 'time' => '20:00'],
            'us' => ['label' => 'US', 'timezone' => 'America/New_York', 'weekday' => 'saturday', 'time' => '20:00'],
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
        'confirmations' => (int) env('ESPORTS_BITCOIN_CONFIRMATIONS', 6),
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
    | - `nwc_receive_uri` only receives: `make_invoice`, `lookup_invoice`,
    |   `get_balance` for zaps into the reserve through `pool@<host>`.
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
    | `zap_signers`: the pubkeys whose zap receipts are shown (NIP-57: the
    | receipt is signed by the recipient's LNURL server). The default is the
    | `nostrPubkey` of the stream's lud16 (`twentyone.nostr.lud16`,
    | theben@getalby.com), read from
    | https://getalby.com/.well-known/lnurlp/theben on 2026-09-28. Any other
    | signer's receipt is dropped: anyone can publish a 9735.
    |
    | `zap_recipient`: whom a stream zap pays (hex or npub); unset = the
    | stream key. Receipt and request must both carry it as `p`, and a
    | request's `lnurl` must be the one of `twentyone.nostr.lud16`: that
    | signer also signs receipts for everybody else's zaps.
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
        'zap_recipient' => env('ESPORTS_STREAM_ZAP_RECIPIENT'),
        // Characters one message may have, and the pause between two posts of one browser.
        'max_length' => 280,
        'cooldown_ms' => 2000,
        // Messages per page: the newest ones when the page opens, then each older page scrolled up to.
        'history' => 50,
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
    | Polls: a question of at most `question_max` characters, 2 to
    | `max_options` answers of at most `option_max`, closing after one of
    | `durations` (seconds). Only polls and votes of accounts that count are
    | shown and counted: members, and players with a result in the league
    | (GameChannels::players()).
    |
    */

    'game_chat' => [
        'creator' => env('ESPORTS_GAME_CHAT_CREATOR'),
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

];
