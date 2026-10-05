@props(['title' => null, 'section' => null, 'realtime' => false, 'scripts' => [], 'flush' => false])

{{--
    The TWENTY ONE shell: header, content, footer, toast stack.
    `section` marks the active main-navigation item; `realtime` loads Echo
    (Reverb websocket) for pages that listen to broadcasts, and only for those;
    `scripts` lists extra Vite entries a page needs (e.g. the chess board);
    `flush` drops the page-top spacing under the header, for the few designs
    that start full-bleed (the pre-launch home and its Block 0 bar).
    `data-navigate-page` marks a page wire:navigate may swap into and out of;
    a page without it loads in full both ways (App\Support\Navigation\Navigate,
    resources/js/navigateGuard.js).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" @if (App\Support\Navigation\Navigate::here()) data-navigate-page @endif>
    <head>
        @include('partials.head', ['title' => $title, 'realtime' => $realtime, 'scripts' => $scripts])
        {{--
            Whose remote-signer session this browser may bring back (resources/js/millAuth.js):
            the logged-in pubkey, or empty for a guest. Only on pages with a session, so an
            error page that has none decides nothing.
        --}}
        @if (request()->hasSession())
            <meta name="nostr-session" content="{{ auth()->user()?->pubkey ?? '' }}">
        @endif
    </head>
    <body class="flex min-h-svh flex-col bg-ground font-mono text-ink antialiased">
        <a href="#content" class="sr-only z-50 rounded-lg bg-btc px-4 py-3 text-sm font-bold text-on-btc focus:not-sr-only focus:fixed focus:top-2 focus:left-2">{{ __('Skip to content') }}</a>

        <x-shell.header :section="$section" />

        <main id="content" @class(['flex min-w-0 grow flex-col', 'pt-page-top lg:pt-page-top-lg' => ! $flush])>
            {{ $slot }}
        </main>

        <x-shell.footer />

        {{-- The live stream (P20): a floating player at the bottom left while it is on air. --}}
        <x-live-player />

        {{-- The match dock (P5f): open matches at the bottom of every page of a logged-in player. --}}
        @auth
            <livewire:match-dock />

            {{-- Casual 1v1 (P23 S3): the ready check of a pairing, on whatever page the player is. --}}
            <livewire:casual-ready />

            {{-- Placement reveal (P10): once, after the fifth rated result in a ladder. --}}
            <x-placement-reveal />
        @endauth

        <x-toast-stack />

        <x-profile-card-host />

        @livewireScripts
    </body>
</html>
