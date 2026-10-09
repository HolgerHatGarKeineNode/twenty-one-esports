{{--
    A live Proof of Pong match between two players, full-screen (plan "Proof of Pong", P2): the bot game's page
    (pong/match.blade.php) with both players' names, the referee's snapshot in #pong-config (PongController::match())
    and resources/js/pong/live.js on the match's presence channel. Its overlays: waiting for the opponent to open the
    match, a player gone (the match pauses, 30 s to come back), and the end with the score, the rating change and
    the rematch. Anybody who does not play it sees its score.

    P3: the three.js arena (#arena3d), both players' figures in the HUD, on the paddles and in the goal celebration,
    the figure picker on the waiting card (a pick goes to the referee, PongController::figure(), and both pages show
    it), the settings behind the gear.

    Order matters: three.js (Hyperbitcoinization's copy) is a classic script at the end of the body; the Vite entry is
    a module and runs after it.
--}}
@php
    $opponent = $me === null ? 1 : 1 - $me;
    $mine = $me ?? 0;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#05070f">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php($reverb = config('broadcasting.connections.reverb'))
    @if (filled($reverb['key'] ?? null))
        <meta name="reverb" content="{{ json_encode(['key' => $reverb['key'], 'host' => $reverb['options']['host'] ?? request()->getHost(), 'port' => (int) ($reverb['options']['port'] ?? 443), 'scheme' => $reverb['options']['scheme'] ?? 'https']) }}">
    @endif
    <title>{{ __('Proof of Pong · :left vs :right', ['left' => $names[0], 'right' => $names[1]]) }} – TWENTY ONE esports</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/pong.css', 'resources/js/pong/live.js'])
</head>
<body data-test="pong-live" data-match="{{ $match->ulid }}" data-me="{{ $me ?? '' }}">

<canvas id="arena3d" class="arena3d" aria-hidden="true"></canvas>

<header class="hud">
    <div class="side me">
        <a class="leave" href="{{ $config['urls']['lobby'] }}" aria-label="{{ __('Back to lobby') }}" title="{{ __('Back to lobby') }}" data-test="pong-leave">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </a>
        <img class="face" data-test="pong-face-me" src="/pong/art/por-turm.webp" alt="" width="44" height="44">
        <span class="score" data-test="pong-score-me" aria-label="{{ $me === null ? __('Points of :name', ['name' => $names[0]]) : __('Your points') }}">{{ $match->score()[$mine] }}</span>
        <span class="who"><span class="name" data-test="pong-name-me">{{ $me === null ? $names[0] : __('You') }}</span><span class="fig" data-test="pong-figure-me"></span></span>
    </div>
    <div class="mid">
        <span class="mid-text"><span class="title">PROOF OF <b>PONG</b></span><span class="rally" id="rally" data-test="pong-rally"></span></span>
        @include('pong.partials.settings-button')
    </div>
    <div class="side bot">
        <span class="who"><span class="name" data-test="pong-name-opponent">{{ $names[$opponent] }}</span><span class="fig" data-test="pong-figure-opponent"></span></span>
        <span class="score" data-test="pong-score-opponent" aria-label="{{ __('Points of :name', ['name' => $names[$opponent]]) }}">{{ $match->score()[$opponent] }}</span>
        <img class="face" data-test="pong-face-opponent" src="/pong/art/por-saylor.webp" alt="" width="44" height="44">
        <button type="button" class="leave resign" id="resign" @if ($me === null || $match->isOver()) hidden @endif aria-label="{{ __('Resign') }}" title="{{ __('Resign') }}" data-test="pong-resign">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 21V4M5 4h11l-2 4 2 4H5"/></svg>
        </button>
    </div>
</header>

<main id="stage">
    <div id="field" data-test="pong-field">
        <canvas id="arena" aria-label="{{ __('The playing field') }}" role="img"></canvas>

        <div class="banner" id="banner" hidden aria-live="polite" data-test="pong-banner"><img alt="" width="160" height="160"><small></small><b></b><span></span></div>
        <div class="ticker" id="ticker" hidden aria-live="polite" data-test="pong-ticker"></div>
        <div class="toast" id="toast" hidden aria-live="polite"></div>
        <div class="cheer" id="cheer" hidden data-test="pong-cheer"><img alt="" width="720" height="720"><span class="cheer-text"><b></b><span></span></span></div>

        <div class="overlay" id="waiting" hidden data-test="pong-waiting">
            <div class="card wide">
                <h2>{{ __('Waiting for :name', ['name' => $names[$opponent]]) }}</h2>
                <p>{{ __('The match starts as soon as both of you are here.') }}</p>
                @if ($me !== null)
                    @include('pong.partials.figures', ['figures' => $figures, 'selected' => null, 'compact' => true])
                @endif
                <p class="hint-desktop">{{ __('Move with the mouse or W/S and the arrow keys.') }}</p>
                <p class="hint-touch">{{ __('Drag your thumb left and right.') }}</p>
            </div>
        </div>

        <div class="overlay" id="away" hidden data-test="pong-away">
            <div class="card">
                <h2 id="away-title"></h2>
                <p>{{ __('The match is paused. Back within 30 seconds, it goes on; otherwise the absent player loses.') }}</p>
            </div>
        </div>

        <div class="overlay" id="end" hidden data-test="pong-end">
            <div class="card end">
                <img class="end-pose" id="end-pose" hidden alt="" width="720" height="720" data-test="pong-end-pose">
                <h2 id="end-title"></h2>
                <div class="big" id="end-score" data-test="pong-end-score"></div>
                <p id="end-reason" data-test="pong-end-reason"></p>
                <p id="end-rating" hidden data-test="pong-end-rating"></p>
                <p class="end-ticker" id="end-ticker"></p>
                <div class="actions">
                    <button type="button" class="btn primary" id="rematch" hidden data-test="pong-rematch">{{ __('Rematch') }}</button>
                    <a class="btn" href="{{ $config['urls']['lobby'] }}">{{ __('Back to lobby') }}</a>
                </div>
            </div>
        </div>
    </div>
    @include('pong.partials.settings')
</main>

<script type="application/json" id="pong-config">@json($config)</script>
<script src="/hyper/vendor/three.min.js"></script>
</body>
</html>
