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
    | of the JSON. The texts are placeholders until the final copy lands.
    |
    */

    'profile' => [
        'name' => 'TWENTY ONE Esports',
        'display_name' => 'TWENTY ONE Esports',
        'about' => 'Esports by the EINUNDZWANZIG community.',
        'picture' => 'https://esports.einundzwanzig.space/images/twentyone/avatar.png',
        'banner' => 'https://esports.einundzwanzig.space/images/twentyone/banner.png',
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

];
