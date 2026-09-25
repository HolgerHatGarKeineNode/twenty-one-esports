@props(['title' => null, 'section' => null, 'realtime' => false])

{{--
    The TWENTY ONE shell: header, content, footer, toast stack.
    `section` marks the active main-navigation item; `realtime` loads Echo
    (Reverb websocket) for pages that listen to broadcasts, and only for those.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title, 'realtime' => $realtime])
    </head>
    <body class="flex min-h-svh flex-col bg-ground font-mono text-ink antialiased">
        <a href="#content" class="sr-only z-50 rounded-lg bg-btc px-4 py-3 text-sm font-bold text-on-btc focus:not-sr-only focus:fixed focus:top-2 focus:left-2">{{ __('Skip to content') }}</a>

        <x-shell.header :section="$section" />

        <main id="content" class="flex min-w-0 grow flex-col">
            {{ $slot }}
        </main>

        <x-shell.footer />

        <x-toast-stack />

        @livewireScripts
        @fluxScripts
    </body>
</html>
