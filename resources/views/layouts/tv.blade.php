@props(['title' => null])

{{--
    The tournament TV (P19): no header, footer, dock or toasts, one 16:9
    stage on black for a big screen or a stream. It listens on Reverb like
    the other realtime pages guests may watch (`realtime`), and loads its own
    stylesheet (resources/css/tv.css) on top of the site's tokens.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title, 'realtime' => true, 'scripts' => ['resources/css/tv.css']])
    </head>
    <body class="tv-body bg-black font-mono text-ink antialiased">
        {{ $slot }}

        @livewireScripts
    </body>
</html>
