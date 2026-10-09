{{--
    A game of Proof of Pong against a bot, full-screen (plan "Proof of Pong", P1): its own document without the
    league's header, navigation and footer, opened in its own tab from the lobby (PongController::bot()). The game runs
    in resources/js/pong/game.js on the same deterministic core as the server; resources/js/pong/arena.js draws it
    (three.js when the browser has WebGL, a 2D canvas otherwise). The config (seed, bot level, rules, texts, links) is
    in #pong-config.

    P3: the three.js arena draws on #arena3d, a full-screen canvas behind the page (the 2D fallback on #arena inside
    the field); the figures (resources/js/pong/cast.json) show in the HUD, on the paddles, in the goal celebration
    (#cheer) and on the end card; the start card has the figure picker (pong/partials/figures); the gear opens the
    sound and quality settings (pong/partials/settings).

    Order matters: three.js (Hyperbitcoinization's copy) is a classic script at the end of the body; the Vite entry is
    a module and runs after it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#05070f">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Proof of Pong · vs :name', ['name' => $config['bot']]) }} – TWENTY ONE esports</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/pong.css', 'resources/js/pong/game.js'])
</head>
<body data-test="pong-match" data-level="{{ $config['level'] }}" data-seed="{{ $config['seed'] }}">

<canvas id="arena3d" class="arena3d" aria-hidden="true"></canvas>

<header class="hud">
    <div class="side me">
        <a class="leave" href="{{ $config['urls']['lobby'] }}" aria-label="{{ __('Back to lobby') }}" title="{{ __('Back to lobby') }}" data-test="pong-leave">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </a>
        <img class="face" data-test="pong-face-me" src="/pong/art/por-{{ $config['figure'] }}.webp" alt="" width="44" height="44">
        <span class="score" data-test="pong-score-me" aria-label="{{ __('Your points') }}">0</span>
        <span class="who"><span class="name">{{ __('You') }}</span><span class="fig" data-test="pong-figure-me"></span></span>
    </div>
    <div class="mid">
        <span class="mid-text"><span class="title">PROOF OF <b>PONG</b></span><span class="rally" id="rally" data-test="pong-rally"></span></span>
        @include('pong.partials.settings-button')
    </div>
    <div class="side bot">
        <span class="who"><span class="name" data-test="pong-bot-name">{{ $config['bot'] }}</span><span class="fig">{{ $config['botTagline'] }}</span></span>
        <span class="score" data-test="pong-score-bot" aria-label="{{ __('Points of :name', ['name' => $config['bot']]) }}">0</span>
        <img class="face" data-test="pong-face-opponent" src="/pong/art/por-{{ $config['botFigure'] }}.webp" alt="" width="44" height="44">
    </div>
</header>

<main id="stage">
    <div id="field" data-test="pong-field">
        <canvas id="arena" aria-label="{{ __('The playing field') }}" role="img"></canvas>

        <div class="banner" id="banner" hidden aria-live="polite" data-test="pong-banner"><img alt="" width="160" height="160"><small></small><b></b><span></span></div>
        <div class="ticker" id="ticker" hidden aria-live="polite" data-test="pong-ticker"></div>
        <div class="toast" id="toast" hidden aria-live="polite"></div>
        <div class="queue" id="queue" hidden aria-live="polite" data-test="pong-queue"><b></b><span></span><small hidden></small></div>
        <div class="cheer" id="cheer" hidden data-test="pong-cheer"><img alt="" width="720" height="720"><span class="cheer-text"><b></b><span></span></span></div>

        <div class="overlay" id="start" data-test="pong-start">
            <div class="card wide">
                <h2>{{ __('vs :name', ['name' => $config['bot']]) }}</h2>
                <p>{{ __('First to :points, two points ahead', ['points' => $config['rules']['points_to_win']]) }}</p>
                @include('pong.partials.figures', ['figures' => $figures, 'selected' => $config['figurePicked'], 'compact' => true])
                <p class="hint-desktop">{{ __('Move with the mouse or W/S and the arrow keys.') }}</p>
                <p class="hint-touch">{{ __('Drag your thumb left and right.') }}</p>
                <div class="actions"><button type="button" class="btn primary" id="start-btn" data-test="pong-start-btn">{{ __('Start game') }}</button></div>
            </div>
        </div>

        <div class="overlay" id="end" hidden data-test="pong-end">
            <div class="card end">
                <img class="end-pose" id="end-pose" hidden alt="" width="720" height="720" data-test="pong-end-pose">
                <h2 id="end-title"></h2>
                <div class="big" id="end-score" data-test="pong-end-score"></div>
                <p class="end-ticker" id="end-ticker"></p>
                <div class="actions">
                    <a class="btn primary" href="{{ $config['urls']['again'] }}" data-test="pong-again">{{ __('New game') }}</a>
                    <a class="btn" href="{{ $config['urls']['lobby'] }}">{{ __('Back to lobby') }}</a>
                </div>
            </div>
        </div>
    </div>
    @include('pong.partials.settings')
</main>
@include('pong.partials.now-playing')

<script type="application/json" id="pong-config">@json($config)</script>
<script src="/hyper/vendor/three.min.js"></script>
</body>
</html>
