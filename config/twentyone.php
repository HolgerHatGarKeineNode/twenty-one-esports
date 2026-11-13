<?php

return [

    /*
    |--------------------------------------------------------------------------
    | TWENTY ONE Esports Nostr identity
    |--------------------------------------------------------------------------
    |
    | The key the project publishes its own events with (profile, relay list,
    | live streams). The nsec never leaves the server process: it is only read
    | by App\Support\TwentyOne\TwentyOneSigner. The npub is public and feeds
    | the NIP-05 document; when both are set they must belong together.
    |
    */

    'nostr' => [
        'nsec' => env('TWENTYONE_NOSTR_NSEC'),
        'npub' => env('TWENTYONE_NOSTR_NPUB'),
        'publish_timeout_seconds' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile (kind 0)
    |--------------------------------------------------------------------------
    |
    | Published as the content of the kind-0 event. Empty values are left out
    | of the JSON. The images are the Blossom copies (content-addressed) of
    | public/images/twentyone/avatar-1024.png and banner.png.
    |
    */

    'profile' => [
        'name' => 'twentyonesports',
        'display_name' => 'TWENTY ONE Esports',
        'about' => "The esports arm of the German-speaking Bitcoin community EINUNDZWANZIG. Ladders, weekly highscores and tournaments for Bitcoiners at esports.einundzwanzig.space. Login via Nostr. This channel streams 24/7; zaps go to the league pool for prizes.\n\nDer Esports-Zweig der deutschsprachigen Bitcoin-Community EINUNDZWANZIG.",
        'picture' => 'https://blossom.einundzwanzig.space/c6f8d996841c1a1b81102ff268a9f4408536a17fb35dfb87eb71b407bad41d8f.png',
        'banner' => 'https://blossom.einundzwanzig.space/3651c44d9e469ec1ceb7cde8581694c86fce248fb5d5eb16ec2cc008b1a3545e.png',
        'website' => 'https://esports.einundzwanzig.space',
        'nip05' => 'esports@esports.einundzwanzig.space',
        // The league's own LNURL endpoint (PoolInvoices): every zap of this profile and its stream goes to the league reserve (user, 2026-10-03).
        'lud16' => 'pool@esports.einundzwanzig.space',
    ],

    /*
    |--------------------------------------------------------------------------
    | Relays
    |--------------------------------------------------------------------------
    |
    | `public`: announced in the kind-10002 relay list and in the NIP-05
    | document, and the default publish targets of `twentyone:profile`.
    |
    */

    'relays' => [
        // Inbox and outbox (kind 10002): three free public relays for redundancy (user, 2026-10-04). Damus left out:
        // it refuses or drops writes from the prod host (503/403 measured 2026-10-03).
        'public' => [
            'wss://nos.lol',
            'wss://relay.primal.net',
            'wss://nostr.mom',
        ],
        // DM inbox (NIP-17 kind 10050): where people's gift-wrapped DMs to this profile land.
        'dm' => [
            'wss://nos.lol',
            'wss://relay.primal.net',
            'wss://nostr.mom',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 24/7 stream (self-hosted HLS + NIP-53 kind 30311)
    |--------------------------------------------------------------------------
    |
    | `twentyone:stream:prepare` encodes `source` once into `prepared` (a loop
    | whose length is a multiple of the 6 s segment); `twentyone:stream` loops
    | it with `-c copy` into `hls_dir`, which the web server exposes as
    | `public_url`. The playlist file name is the last path segment of
    | `public_url`, so both always agree. `?:` keeps the default when a
    | variable is present but empty, as in .env.example.
    |
    */

    'stream' => [
        'source' => env('TWENTYONE_STREAM_SOURCE'),
        'prepared' => env('TWENTYONE_STREAM_PREPARED') ?: storage_path('app/stream/promo-stream.mp4'),
        'hls_dir' => env('TWENTYONE_STREAM_HLS_DIR') ?: storage_path('app/stream/hls'),
        'public_url' => env('TWENTYONE_STREAM_URL') ?: 'https://esports.einundzwanzig.space/live/stream.m3u8',
        'ffmpeg' => env('TWENTYONE_STREAM_FFMPEG') ?: 'ffmpeg',
        'ffprobe' => env('TWENTYONE_STREAM_FFPROBE') ?: 'ffprobe',

        // Relays for the kind 30311 live event. Separate from relays.public
        // because the prod host is refused by some relays (measured
        // 2026-09-26 from 21-dedicated-prod-web: relay.damus.io answers the
        // WebSocket upgrade with 403, nos.lol is unreachable; relay.zap.stream
        // answers "restricted: not authorized", relay.nos.social "kind not
        // allowed").
        'relays' => array_values(array_filter(explode(',', (string) env('TWENTYONE_STREAM_RELAYS')))) ?: [
            'wss://relay.primal.net',
            'wss://nostr.mom',
            'wss://relay.snort.social',
            'wss://offchain.pub',
            'wss://nostr.bitcoiner.social',
            'wss://nostr.oxtr.dev',
        ],

        'event' => [
            'd' => 'twentyone-247',
            'title' => 'TWENTY ONE Esports — 24/7 Stream',
            'summary' => '24/7 stream from TWENTY ONE Esports, the esports arm of EINUNDZWANZIG: ladders, weekly highscores and tournaments for Bitcoiners. Play at esports.einundzwanzig.space. Login via Nostr.',
            'image' => 'https://blossom.einundzwanzig.space/0ae840119d4dc63522b76e596642a79cd92c4ed9378742b008a33228691dd88c.png',
        ],

        // NIP-53 lets clients treat a `live` event without update for 1 h as ended.
        'republish_minutes' => 20,

        // A changed title/summary (a new game) is republished at most this often.
        'text_change_seconds' => 60,

        // The 30311 title/summary take turns (StreamTexts::rotate): the scene's live games, the games on offer,
        // the games played, the weekly highscore chases. One text this many minutes, then the next.
        'texts' => [
            // 0: no turns, the scene's or the loop's texts only (the test suite runs so; its own rotation test sets 10).
            'rotate_minutes' => (int) env('TWENTYONE_STREAM_ROTATE_MINUTES', 10),
        ],

        // The 30311 picture (StreamCover): the next slide every `minutes`, one
        // file overwritten in place, served as /stream/cover.png?v=<hash>.
        'cover' => [
            'minutes' => (int) (env('TWENTYONE_STREAM_COVER_MINUTES') ?: 15),
            'path' => storage_path('app/stream/cover.png'),
        ],

        // Longest wait of the once-a-second database poll (lock or lost server).
        'poll_timeout_ms' => 2000,

        // An encoder that wrote no segment for this long (3 x 6 s) is restarted.
        'watchdog_seconds' => 18,

        // Total budget for the `ended` publish of `twentyone:stream:end`, all
        // relays in parallel. A SIGTERM (every deploy) publishes nothing.
        'shutdown_publish_seconds' => 8,

        // The session (`starts` of the 30311) survives a restart whose last
        // accepted `live` is younger than this: zap.stream shows only the
        // chat from `starts` on. `twentyone:stream:end` clears the file.
        'session_file' => env('TWENTYONE_STREAM_SESSION_FILE') ?: storage_path('app/stream/session.json'),
        'session_resume_minutes' => 30,

        'backoff' => [
            'initial_seconds' => 5,
            'max_seconds' => 300,
        ],

        /*
        | The programme pushed to YouTube as well (YouTubeRelay): a separate ffmpeg reads the active encoder's own
        | playlist and copies it to `url`/`key` over RTMP, so a failing ingest never touches the encoder. Only while
        | `key` is set; then the scene and the prepared loop get a keyframe every 2 s (YouTube wants at most 4 s).
        | The video is copied as it is encoded: 1 fps in the scene, 30 fps in the loop.
        */
        'youtube' => [
            'key' => (string) env('TWENTYONE_STREAM_YOUTUBE_KEY', ''),
            'url' => env('TWENTYONE_STREAM_YOUTUBE_URL') ?: 'rtmp://a.rtmp.youtube.com/live2',
            // Re-encoded video for YouTube (0 = copy the HLS video as it is); YouTube asks 1.5 to 4 Mbit/s for 720p.
            'video_kbps' => (int) env('TWENTYONE_STREAM_YOUTUBE_VIDEO_KBPS', 0),
            'fps' => (int) env('TWENTYONE_STREAM_YOUTUBE_FPS', 30),
            'backoff' => [
                'initial_seconds' => 5,
                'max_seconds' => 120,
            ],

            /*
            | The live chat of that YouTube restream, summarised by the caster (YouTubeChat, YouTubeChatSummary; user
            | 2026-10-12: "Kannst du den Chat auch aus https://www.youtube.com/live/TPNa5BhYfnE lesen und kommentieren
            | lassen? Aber nur zusammengefasst."). Official Data API v3 with an API key (public data): videos.list
            | (1 unit) finds the active live chat of `video_id`, or search.list (live broadcasts of `channel_id`, its own
            | bucket of 100 calls a day) at most every `channel_lookup_minutes`; liveChat/messages (5 units) is read
            | at most every `poll_seconds` or the response's pollingIntervalMillis, whichever is longer. 90 s are
            | 960 reads, 4,800 of the daily 10,000 units. No live chat: no read, a new look after `idle_minutes`.
            | Messages are kept `retention_hours`, at most `max_rows`; every `every_minutes` with at least
            | `min_messages` new ones the caster reacts live to the newest (a chat item). Off until a key exists.
            | User 2026-10-13: "auch aus YT wieder mehr lesen und reagieren": at most every two minutes (the
            | stream puts a music track between two), from one new message on, the newest few only.
            */
            'chat' => [
                'enabled' => (bool) env('YOUTUBE_CHAT_ENABLED', false),
                'api_key' => (string) env('YOUTUBE_API_KEY', ''),
                'video_id' => (string) env('YOUTUBE_CHAT_VIDEO_ID', ''),
                'channel_id' => (string) env('YOUTUBE_CHAT_CHANNEL_ID', ''),
                'poll_seconds' => (int) env('YOUTUBE_CHAT_POLL_SECONDS', 90),
                'idle_minutes' => 10,
                'channel_lookup_minutes' => 30,
                'timeout_seconds' => 8,
                'retention_hours' => 24,
                'max_rows' => 500,
                'every_minutes' => (int) env('YOUTUBE_CHAT_SUMMARY_MINUTES', 2),
                'min_messages' => (int) env('YOUTUBE_CHAT_SUMMARY_MIN_MESSAGES', 1),
                // Messages one reaction sees at most (the newest), and the share of reactions Sirius joins as a duet.
                'max_messages' => (int) env('YOUTUBE_CHAT_MAX_MESSAGES', 6),
                'duet_share' => (float) env('YOUTUBE_CHAT_DUET_SHARE', 0.3),
            ],
        ],

        /*
        | Music under the picture in both modes: `<title>__v<n>.m4a` files
        | (AAC-LC 44.1 kHz stereo). The supervisor writes an ffconcat list of
        | about `list_hours`, `list_passes` times over: ffmpeg's -stream_loop
        | cannot loop a list with in/outpoints (MusicPlaylist::ffconcat()), and
        | an encoder whose segments lose the audio track is restarted
        | (TwentyOneStreamCommand, AUDIO_LOSS_SEGMENTS). The order is the artist
        | cycle (ArtistCycle): one track of every artist per round, over the
        | files of all four folders; the folders only hold the files.
        */
        'music' => [
            'dir' => env('TWENTYONE_STREAM_MUSIC_DIR') ?: storage_path('app/stream/music'),
            // The folders below `dir` the stream reads files from as well (TwentyOneStreamCommand::musicSources(),
            // SceneDirector::musicTracksFromConfig() for the music breaks).
            'instrumental_dir' => env('TWENTYONE_STREAM_INSTRUMENTAL_DIR') ?: storage_path('app/stream/music/instrumental'),
            'plebrap_dir' => env('TWENTYONE_STREAM_PLEBRAP_DIR') ?: storage_path('app/stream/music/plebrap'),
            'wavlake_dir' => env('TWENTYONE_STREAM_WAVLAKE_DIR') ?: storage_path('app/stream/music/wavlake'),
            // Artists banned for good, whatever a future import brings back (user 2026-10-15: ICT - DJ Lexo):
            // comma-separated names, matched against the file name.
            'banned_artists' => env('TWENTYONE_STREAM_MUSIC_BANNED_ARTISTS', 'ICT - DJ Lexo'),
            // The music catalog (MusicCatalog): tracks, artists, credits; fingerprints.json sits next to it.
            'catalog' => env('TWENTYONE_STREAM_MUSIC_CATALOG') ?: resource_path('data/music/catalog.json'),
            // The night's additions (plan "Wavlake-Sync"): a catalog of the same shape under storage that only
            // twentyone:music-wavlake-sync writes; read together with the repo catalog. Empty = none.
            'overlay' => env('TWENTYONE_STREAM_MUSIC_OVERLAY', storage_path('app/stream/music-sync/catalog.json')),
            // Tracks at least between two of the same artist in the artist cycle, at least 1 (never twice in a row).
            'artist_gap' => (int) env('TWENTYONE_STREAM_MUSIC_ARTIST_GAP', 1),
            // An artist takes its own turn only with at least this many songs (user 2026-10-16: a single song would
            // play every round); below it, its songs join `various_artists`, one shared turn (user 2026-10-17).
            'min_artist_tracks' => (int) env('TWENTYONE_STREAM_MUSIC_MIN_ARTIST_TRACKS', 6),
            'various_artists' => 'Various Artists',
            // Playlists: artists merged into one artist of the cycle, so artists with few songs still play (user
            // 2026-10-17: "PlebRap als einen Künstler ansehen" and keep that for others "die nicht so viele Tracks
            // haben"). Name => the tracks it takes: by `credit_source` (a whole index) and/or by `artists` (names as the
            // catalog credits them, any credited artist of a track). The card still names the track's own artist.
            'playlists' => [
                'PlebRap' => ['credit_source' => 'plebrap-index', 'artists' => ['PlebRap']],
            ],
            // The nightly Wavlake sync (plan "Wavlake-Sync", user 2026-10-17: "Automatisches Abholen von neuen Tracks
            // von Wavlake von unseren Artists ... jede Nacht"): new tracks of exactly these artists (Wavlake slug =>
            // the name the catalog credits) go into the overlay catalog, newest first, at most `per_run` downloads a
            // night. A new artist joins only when the user names it (user 2026-10-17: "neue Artists über dich").
            'wavlake_sync' => [
                'enabled' => (bool) env('TWENTYONE_STREAM_MUSIC_WAVLAKE_SYNC', true),
                'artists' => [
                    'ainsley-costello' => 'Ainsley Costello',
                    'arthur-yoria' => 'Arthur Yoria',
                    'black-vulcanite' => 'Black Vulcanite',
                    'contra' => 'Contra',
                    'cristo-currensi' => 'Cristo Currensi',
                    'dj-bitcoin' => 'DJ Bitcoin',
                    'greensands' => 'Greensands',
                    'joe-martin' => 'Joe Martin',
                    'numbergoexp' => 'NumberGoExp',
                    'plebrap' => 'PlebRap',
                    'richard-greaser' => 'Richard Greaser',
                    'sabu' => 'sabu',
                    'under-the-mulga-groove' => 'Under The Mulga Groove',
                    'zazawowow' => 'Zazawowow',
                ],
                'per_run' => (int) env('TWENTYONE_STREAM_MUSIC_WAVLAKE_PER_RUN', 25),
                // Downloads come only over https from these hosts (Wavlake's media URLs go through op3.dev to CloudFront).
                'hosts' => ['op3.dev', 'd12wklypp119aj.cloudfront.net'],
                'max_bytes' => 80 * 1024 * 1024,
                // Between Wavlake API requests (a quick series is answered with 429).
                'pause_ms' => 400,
                // A track that fails this many nights in a row is given up (the state keeps why).
                'max_tries' => 3,
                'state' => storage_path('app/stream/music-sync/state.json'),
                // After a night with new tracks the stream daemon restarts to play them (it reads the folders at start).
                'restart_stream' => true,
            ],
            // Fresh rounds for three days: an encoder that runs that long without a restart still hears no repeat.
            'list_hours' => 72,
            // How many times over the list is written (72 h x 2 = 6 days before an encoder would run out of music).
            'list_passes' => 2,
            // Where the music is on the wall clock (MusicTimeline): a new encoder goes on from there.
            'timeline_file' => env('TWENTYONE_STREAM_MUSIC_TIMELINE_FILE') ?: storage_path('app/stream/music-timeline.json'),
        ],

        /*
        | The live-game scene: one still per second while any chess game
        | (blitz or daily) is active, back to the loop `hysteresis_seconds` after the last one ended.
        | CRF 35 (x264 veryfast, no lookahead) gave 58 kbit/s of video on 120
        | rendered scene frames with ticking clocks (P3; CRF 32: 68, CRF 36: 55).
        */
        'scene' => [
            'rsvg_convert' => env('TWENTYONE_STREAM_RSVG_CONVERT') ?: 'rsvg-convert',
            'fonts_dir' => resource_path('fonts/stream'),
            'work_dir' => storage_path('app/stream/scene'),
            'url' => 'esports.einundzwanzig.space',
            'crf' => 35,
            'hysteresis_seconds' => 60,
            // One render may take this long (normally 0.07-0.2 s) before it counts as failed.
            'render_timeout_seconds' => 2,
            // Renders failing for this many wall-clock seconds in a row send the stream back to the loop.
            'render_failure_seconds' => 10,
        ],

        /*
        | Numbers the teaser scenes show (StreamStats): counted at most every
        | `cache_seconds`; "games today" starts at midnight in `timezone` (the
        | app itself runs in UTC); the clan spotlight moves on every
        | `clan_spotlight_seconds`.
        */
        /*
        | The scene rotation (RotationPlanner), in seconds. With games: match
        | (blitz longer), gallery from two games, then teasers from the pool
        | of nine. Without games: teasers, and every `loop_every_rounds`-th
        | round one pass of the promo loop (its length read with ffprobe at
        | start; `loop_fallback_seconds` when that fails). While a tournament
        | is open for sign-up, every round shows its hero and bracket slides
        | (`tournament_seconds` each); a round without games then has one teaser.
        */
        'rotation' => [
            'match_seconds' => 45,
            'blitz_match_seconds' => 60,
            'gallery_seconds' => 20,
            'teaser_seconds' => 12,
            'teasers_per_round' => 3,
            // 6 since 2026-11-13 (user: the stream seemed to loop the video only): at 3 the promo video held about a third of the air time.
            'loop_every_rounds' => 6,
            'loop_fallback_seconds' => 60,
            'tournament_seconds' => 15,
            // While a tournament runs it takes the stream (RotationPlanner): this long per running tournament, then the next one.
            'running_tournament_seconds' => (int) env('TWENTYONE_STREAM_RUNNING_TOURNAMENT_SECONDS', 90),
            // true: a running tournament holds the stream alone until it ends (2026-10-03); false: its rounds and the normal rotation take turns (2026-10-15).
            'running_tournament_exclusive' => (bool) env('TWENTYONE_STREAM_RUNNING_TOURNAMENT_EXCLUSIVE', false),
            // A tournament just decided: its champion slide (tx6) holds the stream alone this long, once (RotationPlanner, 2026-10-03).
            'champion_moment_seconds' => (int) env('TWENTYONE_STREAM_CHAMPION_MOMENT_SECONDS', 120),
            // A running Hyperbitcoinization match (h1, HyperScene): its slide stands this long, and while the match is tense
            // (a close duel, a central bank that just fell) up to the max; one match a round (RotationPlanner, plan P6).
            'hyper_min_seconds' => 60,
            'hyper_max_seconds' => 180,
            // A won Proof of Pong match of the last `pong_recent_hours` (p1, PongScene): its result slide stands this long, one
            // result a round (RotationPlanner, plan "Proof of Pong", P4). No live gameplay: the encoder takes no live frame rate.
            'pong_seconds' => 20,
            'pong_recent_hours' => 24,
            // The radio programme slides (r1..r3, ProgrammeSlides, plan "TWENTY ONE Radio", P19): only while the commentary runs; one
            // slide every `programme_every_rounds`-th round, the programme of the next `programme_hours` this long, the
            // spotlight and the duel card this long; a show starting within two minutes gets its spotlight next, once.
            'programme_slides' => (bool) env('TWENTYONE_STREAM_PROGRAMME_SLIDES', true),
            'programme_every_rounds' => 1,
            // One programme slide after every this many slides of a round (user 2026-10-13: "viel viel öfter"); 0 = one per round.
            'programme_every_slots' => (int) env('TWENTYONE_STREAM_PROGRAMME_EVERY_SLOTS', 2),
            'programme_seconds' => 20,
            'programme_spotlight_seconds' => 15,
            'programme_hours' => 12,
            // A Blockfill run that took first place this many minutes ago still gets its moment slide (f4, BlockfillSlides).
            'blockfill_moment_minutes' => 10,
            // A finished tournament keeps its champion, podium and final bracket on the stream this long after its last result (TournamentLiveSlides): five days (2026-10-03).
            'finished_tournament_hours' => (int) env('TWENTYONE_STREAM_FINISHED_TOURNAMENT_HOURS', 120),
            // The series game on the spotlight teaser (d6, GameSpotlight); unknown or empty: the newest series game.
            'spotlight' => env('TWENTYONE_STREAM_SPOTLIGHT', 'age-of-empires-2'),
        ],

        /*
        | Live viewer count (ViewerCounter, ViewerSocket): nginx logs every
        | request for the playlist to a unix datagram socket the daemon binds,
        | `<dir>/viewers.sock`,
        |
        |   access_log syslog:server=unix:<dir>/viewers.sock,nohostname,tag=hls combined;
        |
        | inside the location that serves only /live/*.m3u8 (prod: the nested
        | `location ~ \.m3u8$` of /live/). The built-in `combined` format is used
        | because Forge edits only the server block, where log_format is not
        | allowed; a custom `$remote_addr|$http_user_agent|$status` format at
        | http level is read as well. `dir` is private: owned by the daemon's user, no
        | access for others, search access for `nginx_user` by ACL (setfacl;
        | '' = none, when nginx runs as the daemon's user). A viewer is a
        | distinct IP + user agent with a 200/206/304 within `window_seconds`;
        | `exclude_agents` never counts. The scenes get it as `viewers`, the
        | 30311 as `current_participants`. The socket path must stay within
        | 107 bytes; a directory that is not private or a socket that cannot
        | be bound or read leaves the count off (null) and the stream running,
        | and the daemon binds it again after `rebind_initial_seconds`,
        | doubling up to `rebind_max_seconds` (ViewerFeed).
        */
        'viewers' => [
            'dir' => env('TWENTYONE_STREAM_VIEWERS_DIR') ?: storage_path('app/stream/viewers'),
            'nginx_user' => (string) env('TWENTYONE_STREAM_VIEWERS_NGINX_USER', 'forge'),
            'window_seconds' => 20,
            'exclude_agents' => '/HeadlessChrome|Playwright|curl|Wget|python-requests|Go-http-client|bot|spider|monitor/i',
            // Memory bound: at most this many IP + agent hashes; a flood pushes out the oldest.
            'max_keys' => 10000,
            // Datagrams read per loop turn (4 per second); the rest waits for the next turn.
            'max_datagrams_per_tick' => 2000,
            'rebind_initial_seconds' => 30,
            'rebind_max_seconds' => 600,
        ],

        /*
        | Pictures on the scenes (StreamImages): `twentyone:stream:images`
        | (scheduled every 10 minutes) redraws every avatar the stream can show
        | as a 128 px JPEG under `<dir>/avatars` and blurs the game covers and the
        | brand cover into `<dir>/backdrops`. The daemon only reads those files,
        | never the network, and keeps at most `memory_entries` data URIs for
        | `memory_seconds`. A remote picture is fetched over https only, from a
        | public address, pinned, following at most 3 redirects (each checked
        | and pinned like the first), within `fetch_seconds` for all hops and
        | `max_bytes`; a failed fetch waits `retry_seconds` before the next try.
        */
        'images' => [
            'dir' => env('TWENTYONE_STREAM_IMAGES_DIR') ?: storage_path('app/stream'),
            'max_users' => 500,
            'refresh_seconds' => 86400,
            'retry_seconds' => 3600,
            'fetch_seconds' => 5,
            'max_bytes' => 8 * 1024 * 1024,
            'max_side' => 4096,
            // The music artists' pictures (StreamImageBuilder::refreshMusicAvatars()): full-size photos are common there.
            'music_max_bytes' => 16 * 1024 * 1024,
            'music_max_side' => 6000,
            'memory_entries' => 500,
            'memory_seconds' => 600,
        ],

        'stats' => [
            'cache_seconds' => 15,
            'timezone' => 'Europe/Berlin',
            'ladder_rows' => 4,
            'clan_spotlight_seconds' => 600,
        ],
    ],

];
