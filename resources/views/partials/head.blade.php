<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="theme-color" content="#111114" />

<title>{{ filled($title ?? null) ? $title.' – TWENTY ONE esports' : 'TWENTY ONE esports' }}</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($realtime ?? false)
    {{--
        Reverb client settings are read at runtime, not baked into the build, so
        one build serves every environment. Without an app key there is no
        websocket and realtime pages fall back to polling the server.
    --}}
    @php($reverb = config('broadcasting.connections.reverb'))
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if (filled($reverb['key'] ?? null))
        <meta name="reverb" content="{{ json_encode(['key' => $reverb['key'], 'host' => $reverb['options']['host'] ?? request()->getHost(), 'port' => (int) ($reverb['options']['port'] ?? 443), 'scheme' => $reverb['options']['scheme'] ?? 'https']) }}">
    @endif
    @vite('resources/js/echo.js')
@endif
@foreach ($scripts ?? [] as $entry)
    @vite($entry)
@endforeach
@livewireStyles
