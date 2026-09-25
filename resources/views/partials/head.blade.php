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
    @vite('resources/js/echo.js')
@endif
@livewireStyles
