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
        'lud16' => null,
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

        'event' => [
            'd' => 'twentyone-247',
            'title' => 'TWENTY ONE Esports — 24/7 Stream',
            'summary' => '24/7 stream from TWENTY ONE Esports, the esports arm of EINUNDZWANZIG. Currently looping our promo video while our Bitcoiner ladder platform is in development. Login via Nostr.',
            'image' => 'https://blossom.einundzwanzig.space/3651c44d9e469ec1ceb7cde8581694c86fce248fb5d5eb16ec2cc008b1a3545e.png',
            't' => ['bitcoin', 'esports', 'nostr', 'einundzwanzig', 'gaming'],
        ],

        // NIP-53 lets clients treat a `live` event without update for 1 h as ended.
        'republish_minutes' => 20,

        'backoff' => [
            'initial_seconds' => 5,
            'max_seconds' => 300,
        ],
    ],

];
