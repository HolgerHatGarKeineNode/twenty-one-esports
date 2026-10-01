@props(['name', 'size' => 20])

{{-- Stroke icons used across the TWENTY ONE screens, paths copied from the designs. --}}
@php
    $paths = match ($name) {
        'home' => '<rect x="3" y="3" width="7" height="9" rx="1"></rect><rect x="14" y="3" width="7" height="5" rx="1"></rect><rect x="14" y="12" width="7" height="9" rx="1"></rect><rect x="3" y="16" width="7" height="5" rx="1"></rect>',
        'chess' => '<path d="M7 21h10M8 17h8l-1-5 2-2-3-5-4-1-3 3 2 2-2 2z"></path><circle cx="11" cy="7" r="0.6"></circle>',
        'pawn' => '<path d="M7 21h10M8 17h8l-1-5 2-2-3-5-4-1-3 3 2 2-2 2z"></path>',
        'matches' => '<path d="M12 2 21 7v10l-9 5-9-5V7z"></path><path d="M12 12 21 7M12 12v10M12 12 3 7"></path>',
        'tournaments' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"></path><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"></path>',
        'ladder' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"></path>',
        'clans' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"></path>',
        // Three blocks in a row: the season chain on the mining page.
        'mining' => '<rect x="2" y="8" width="6" height="8" rx="1"></rect><rect x="9" y="8" width="6" height="8" rx="1"></rect><rect x="16" y="8" width="6" height="8" rx="1"></rect>',
        'admin' => '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0"></path><circle cx="16" cy="6" r="2"></circle><circle cx="10" cy="12" r="2"></circle><circle cx="18" cy="18" r="2"></circle>',
        'search' => '<circle cx="11" cy="11" r="7"></circle><path d="m20 20-3.5-3.5"></path>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"></path>',
        // Shell navigation (header concept B): the game hub, daily games, settings, rules.
        'grid' => '<rect x="3" y="3" width="8" height="8" rx="1"></rect><rect x="13" y="3" width="8" height="8" rx="1"></rect><rect x="3" y="13" width="8" height="8" rx="1"></rect><rect x="13" y="13" width="8" height="8" rx="1"></rect>',
        'calendar' => '<rect x="3" y="4" width="18" height="17" rx="2"></rect><path d="M3 9h18M8 2v4M16 2v4"></path>',
        'settings' => '<circle cx="12" cy="12" r="3"></circle><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"></path>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"></path>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"></path>',
        'google' => '<path d="M21 12.2c0-.7-.1-1.4-.2-2H12v3.9h5.1a4.4 4.4 0 0 1-1.9 2.9v2.4h3.1c1.8-1.7 2.7-4.1 2.7-7.2z"></path><path d="M12 21.5c2.6 0 4.7-.9 6.3-2.3l-3.1-2.4c-.9.6-2 .9-3.2.9-2.5 0-4.6-1.7-5.3-3.9H3.5v2.5A9.5 9.5 0 0 0 12 21.5z"></path><path d="M6.7 13.8a5.7 5.7 0 0 1 0-3.6V7.7H3.5a9.5 9.5 0 0 0 0 8.6z"></path><path d="M12 6.3c1.4 0 2.6.5 3.6 1.4l2.7-2.7A9.5 9.5 0 0 0 3.5 7.7l3.2 2.5C7.4 8 9.5 6.3 12 6.3z"></path>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"></path>',
        'bolt-toast' => '<path d="M14.5 3 5 13.5h6.5L9.5 21 19 10.5h-6.5z"></path>',
        'lock' => '<rect x="3" y="11" width="18" height="10" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>',
        'key' => '<circle cx="8" cy="15" r="4"></circle><path d="m10.8 12.2 8.2-8.2M17 6l2 2M15 8l2 2"></path>',
        'shield-check' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><path d="m9 12 2 2 4-4"></path>',
        'check' => '<path d="M5 12.5 10 17 19 7"></path>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"></path>',
        'clock' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M3 10h18M8 3v4M16 3v4"></path>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"></path>',
        'chevron-up' => '<path d="m18 15-6-6-6 6"></path>',
        'soccer' => '<circle cx="12" cy="12" r="9"></circle><path d="m12 7 4 3-1.5 4.5h-5L8 10z"></path><path d="M12 3v4M16 10l4.5-1.5M14.5 14.5l2.5 4M9.5 14.5 7 18.5M8 10 3.5 8.5"></path>',
        'rocket-league' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 3v4l-3.5 2.5M12 7l3.5 2.5M8.5 9.5 7 14l5 3 5-3-1.5-4.5M3.5 10.5 7 14M20.5 10.5 17 14M12 17v4"></path>',
        'award' => '<circle cx="12" cy="9" r="6"></circle><path d="m8.5 14-1.5 8 5-3 5 3-1.5-8"></path>',
        'brush' => '<path d="M3 21c3 0 6-1 6-4a3 3 0 0 0-3-3c-2 0-3 2-3 7z"></path><path d="M20.5 3.5 10 14l-1-1L19.5 2.5z"></path>',
        'vote' => '<path d="m9 12 2 2 4-4"></path><rect x="3" y="3" width="18" height="18" rx="2"></rect>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"></path>',
        'link' => '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"></path><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"></path>',
        'user' => '<circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path>',
        'alert' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v6M12 16.5v.5"></path>',
        // Chess screens (ChessGame, ChessOverlays, ChessStates, ChessGameDone)
        'draw' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 3v18"></path><path d="M12 3a9 9 0 0 1 0 18z" fill="currentColor"></path>',
        'flag' => '<path d="M5 21V4M5 4h11l-2 4 2 4H5"></path>',
        'flip' => '<path d="M7 4v16M7 20l-3-3M7 20l3-3"></path><path d="M17 20V4M17 4l-3 3M17 4l3 3"></path>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"></rect><path d="M16 8V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h3"></path>',
        'send' => '<path d="M22 2 11 13M22 2l-7 20-4-9-9-4z"></path>',
        'mute' => '<path d="M11 5 6 9H2v6h4l5 4z"></path><path d="m22 9-6 6M16 9l6 6"></path>',
        'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"></path><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"></path>',
        'retry' => '<path d="M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5"></path>',
        'wifi' => '<path d="M2 8.5a15 15 0 0 1 20 0M5 12a10 10 0 0 1 14 0M8.5 15.5a5 5 0 0 1 7 0"></path><circle cx="12" cy="19" r="1"></circle>',
        'warn' => '<path d="M12 3 2 21h20z"></path><path d="M12 10v5M12 18v.5"></path>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"></path><circle cx="12" cy="12" r="3"></circle>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"></path>',
        'first' => '<path d="M6 5v14M18 6l-7 6 7 6"></path>',
        'prev' => '<path d="m15 6-6 6 6 6"></path>',
        'next' => '<path d="m9 6 6 6-6 6"></path>',
        'last' => '<path d="M18 5v14M6 6l7 6-7 6"></path>',
        'chat' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"></path>',
        'chat-sheet' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"></path>',
        'clock' => '<circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path>',
        // The live stream's player (P20): sound on, full page, fold to the tab, play.
        'volume' => '<path d="M11 5 6 9H2v6h4l5 4z"></path><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"></path>',
        'music' => '<path d="M9 18V5l12-2v13"></path><circle cx="6" cy="18" r="3"></circle><circle cx="18" cy="16" r="3"></circle>',
        'music-off' => '<path d="M9 18V9M21 13v3M9 5l12-2v5"></path><circle cx="6" cy="18" r="3"></circle><path d="m3 3 18 18"></path>',
        'expand' => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"></path>',
        'minimize' => '<path d="M5 19h14"></path>',
        'play' => '<path d="M7 4v16l13-8z"></path>',
        // The board games next to chess (plan "Mühle und Dame", P5): the morris board, a checkers man.
        'morris' => '<rect x="3" y="3" width="18" height="18"></rect><rect x="7.5" y="7.5" width="9" height="9"></rect><path d="M12 3v4.5M12 16.5V21M3 12h4.5M16.5 12H21"></path>',
        'checkers' => '<ellipse cx="12" cy="9" rx="8" ry="3.5"></ellipse><path d="M4 9v5c0 1.9 3.6 3.5 8 3.5s8-1.6 8-3.5V9"></path><ellipse cx="12" cy="9" rx="4" ry="1.6"></ellipse>',
        // Age of Empires II: a castle keep with its gate.
        'castle' => '<path d="M4 21V8h3v3h2.5V8h5v3H17V8h3v13zM10 21v-4a2 2 0 0 1 4 0v4"></path>',
        // A like on Nostr (P48, NIP-25).
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21.2l8.8-8.8a5.5 5.5 0 0 0 0-7.8z"></path>',
        default => throw new InvalidArgumentException("Unknown icon [{$name}]."),
    };
@endphp

<svg {{ $attributes->merge(['width' => $size, 'height' => $size, 'class' => 'shrink-0']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $paths !!}</svg>
