<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="dark" />
<meta name="theme-color" content="#111114" />

{{--
    Search and link previews (P6b, P14, App\Support\PageMeta): plain tags in the first response, no JavaScript needed.
    Indexing is opt-in: only a page that described itself gets indexed, with canonical and hreflang alternates.
    The layout's `title` wins over the page meta title, which may be in another language (an invite's preview
    speaks the inviter's language, its <title> the visitor's).
--}}
@php
    $pageMeta = app(App\Support\PageMeta::class);
    $pageTitle = filled($title ?? null) ? $title : $pageMeta->title;
    $shareTitle = $pageMeta->title ?? (filled($title ?? null) ? $title : 'TWENTY ONE esports');
    $indexable = $pageMeta->isIndexable();
    $canonical = $indexable ? App\Support\Seo\LocalizedUrls::for(app()->getLocale(), $pageMeta->url) : $pageMeta->url;
    $shareImages = $pageMeta->images !== [] || ! $indexable ? $pageMeta->images : [[asset('images/twentyone/cover.png'), 1280, 720, 'TWENTY ONE esports']];
    // JSON_HEX_TAG keeps a `</script>` in a player's name from closing the element.
    $jsonLd = json_encode(['@context' => 'https://schema.org', '@graph' => [App\Support\Seo\StructuredData::organization(), ...$pageMeta->structuredData]],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE);
@endphp
<title>{{ filled($pageTitle) ? $pageTitle.' – TWENTY ONE esports' : 'TWENTY ONE esports' }}</title>
@unless ($indexable)
    <meta name="robots" content="noindex, nofollow">
@endunless
@if ($pageMeta->description !== null)
    <meta name="description" content="{{ $pageMeta->description }}">
@endif
@if ($indexable)
    <link rel="canonical" href="{{ $canonical }}">
    @foreach (App\Support\Seo\LocalizedUrls::alternates($pageMeta->url) as $hreflang => $alternate)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $alternate }}">
    @endforeach
@endif
@if ($pageMeta->description !== null)
    <meta property="og:site_name" content="TWENTY ONE esports">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="{{ app()->getLocale() === 'de' ? 'de_DE' : 'en_US' }}">
    <meta property="og:title" content="{{ $shareTitle }}">
    <meta property="og:description" content="{{ $pageMeta->description }}">
    @if ($canonical !== null)
        <meta property="og:url" content="{{ $canonical }}">
    @endif
    @foreach ($shareImages as [$imageUrl, $imageWidth, $imageHeight, $imageAlt])
        <meta property="og:image" content="{{ $imageUrl }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="{{ $imageWidth }}">
        <meta property="og:image:height" content="{{ $imageHeight }}">
        <meta property="og:image:alt" content="{{ $imageAlt }}">
    @endforeach
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $shareTitle }}">
    <meta name="twitter:description" content="{{ $pageMeta->description }}">
    @if ($shareImages !== [])
        <meta name="twitter:image" content="{{ $shareImages[0][0] }}">
    @endif
@endif
@if ($jsonLd !== false)
    <script type="application/ld+json">{!! $jsonLd !!}</script>
@endif

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
{{-- Profiles of the players on the page and the player card (P10a, resources/js/profiles.js). --}}
<meta name="profile-loader" content="{{ json_encode([
    'relays' => array_values(config('esports.profile_relays', [])),
    'url' => route('profiles.store', absolute: false),
    'waitMs' => (int) config('esports.profiles.wait_ms'),
    'labels' => ['profile' => __(':name profile')],
]) }}">
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
