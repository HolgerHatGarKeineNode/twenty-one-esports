<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="theme-color" content="#111114" />

<title>{{ filled($title ?? null) ? $title.' – TWENTY ONE esports' : 'TWENTY ONE esports' }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
{{--
    Realtime: pages that listen to broadcasts, and every page of a logged-in
    player, who is on the global `online` presence channel wherever they are
    (P5b). Guests get no websocket except on the pages they can watch.
--}}
@if (($realtime ?? false) || auth()->check())
    {{--
        Reverb client settings are read at runtime, not baked into the build, so
        one build serves every environment. Without an app key there is no
        websocket and realtime pages fall back to polling the server.
    --}}
    @php($reverb = config('broadcasting.connections.reverb'))
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @auth
        <meta name="presence-user" content="{{ auth()->id() }}">
        <meta name="board-theme" content="{{ auth()->user()->chessSettings()->board }}" data-coordinates="{{ auth()->user()->chessSettings()->coordinates ? '1' : '0' }}">
    @endauth
    {{-- Notifications and sounds (P5c, resources/js/alerts.js and sounds.js). --}}
    @php($chess = auth()->user()?->chessSettings() ?? new App\Support\Chess\ChessSettings)
    <meta name="alert-settings" content="{{ json_encode([
        'userId' => auth()->id(),
        'sound' => $chess->sound,
        'volume' => $chess->volume,
        'countdown' => (int) config('esports.notifications.countdown_seconds'),
        'labels' => ['justNow' => __('just now'), 'opening' => __('Opening the game in :s s'), 'stay' => __('Stay here')],
    ]) }}">
    @if (filled($reverb['key'] ?? null))
        <meta name="reverb" content="{{ json_encode(['key' => $reverb['key'], 'host' => $reverb['options']['host'] ?? request()->getHost(), 'port' => (int) ($reverb['options']['port'] ?? 443), 'scheme' => $reverb['options']['scheme'] ?? 'https']) }}">
    @endif
    @vite('resources/js/echo.js')
@endif
@foreach ($scripts ?? [] as $entry)
    @vite($entry)
@endforeach
@livewireStyles
