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
        'about' => "The esports arm of the German-speaking Bitcoin community EINUNDZWANZIG. A 1v1/2v2 ladder platform for Bitcoiners is in development at esports.einundzwanzig.space. This channel streams 24/7. Login via Nostr.\n\nDer Esports-Zweig der deutschsprachigen Bitcoin-Community EINUNDZWANZIG.",
        'picture' => 'https://blossom.einundzwanzig.space/c6f8d996841c1a1b81102ff268a9f4408536a17fb35dfb87eb71b407bad41d8f.png',
        'banner' => 'https://blossom.einundzwanzig.space/3651c44d9e469ec1ceb7cde8581694c86fce248fb5d5eb16ec2cc008b1a3545e.png',
        'website' => 'https://esports.einundzwanzig.space',
        'nip05' => 'esports@esports.einundzwanzig.space',
        'lud16' => 'theben@getalby.com',
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
        'public' => [
            'wss://relay.damus.io',
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
            'summary' => '24/7 stream from TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Currently looping our promo video while our Bitcoiner ladder platform is in development. Login via Nostr.',
            'image' => 'https://blossom.einundzwanzig.space/0ae840119d4dc63522b76e596642a79cd92c4ed9378742b008a33228691dd88c.png',
            't' => ['bitcoin', 'esports', 'nostr', 'einundzwanzig', 'gaming'],
        ],

        // NIP-53 lets clients treat a `live` event without update for 1 h as ended.
        'republish_minutes' => 20,

        // A changed title/summary (a new game) is republished at most this often.
        'text_change_seconds' => 60,

        // Longest wait of the once-a-second database poll (lock or lost server).
        'poll_timeout_ms' => 2000,

        // An encoder that wrote no segment for this long (3 x 6 s) is restarted.
        'watchdog_seconds' => 18,

        // Total budget for the `ended` publish on SIGTERM, all relays in
        // parallel. Supervisors kill after ~10 s (supervisord stopwaitsecs).
        'shutdown_publish_seconds' => 8,

        'backoff' => [
            'initial_seconds' => 5,
            'max_seconds' => 300,
        ],

        /*
        | Music under the picture in both modes: `<title>__v<n>.m4a` files
        | (AAC-LC 44.1 kHz stereo). The supervisor writes a shuffled ffconcat
        | list of about `list_hours`; ffmpeg loops it.
        */
        'music' => [
            'dir' => env('TWENTYONE_STREAM_MUSIC_DIR') ?: storage_path('app/stream/music'),
            'list_hours' => 12,
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
    ],

];
