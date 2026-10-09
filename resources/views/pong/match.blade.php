{{--
    A game of Proof of Pong against a bot, full-screen (plan "Proof of Pong", P1): its own document without the
    league's header, navigation and footer, opened in its own tab from the lobby (PongController::bot()). The game runs
    in resources/js/pong/game.js on the same deterministic core as the server; resources/js/pong/arena.js draws it
    (three.js when the browser has WebGL, a 2D canvas otherwise). The config (seed, bot level, rules, texts, links) is
    in #pong-config.

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

<header class="hud">
    <div class="side me">
        <a class="leave" href="{{ $config['urls']['lobby'] }}" aria-label="{{ __('Back to lobby') }}" title="{{ __('Back to lobby') }}" data-test="pong-leave">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </a>
        <span class="score" data-test="pong-score-me" aria-label="{{ __('Your points') }}">0</span>
        <span class="name">{{ __('You') }}</span>
    </div>
    <div class="mid">
        <span class="title">PROOF OF <b>PONG</b></span>
        <span class="rally" id="rally" data-test="pong-rally"></span>
    </div>
    <div class="side bot">
        <span class="name" data-test="pong-bot-name">{{ $config['bot'] }}</span>
        <span class="score" data-test="pong-score-bot" aria-label="{{ __('Points of :name', ['name' => $config['bot']]) }}">0</span>
    </div>
</header>

<main id="stage">
    <div id="field" data-test="pong-field">
        <canvas id="arena" aria-label="{{ __('The playing field') }}" role="img"></canvas>

        <div class="banner" id="banner" hidden aria-live="polite" data-test="pong-banner"><small></small><b></b><span></span></div>
        <div class="toast" id="toast" hidden aria-live="polite"></div>

        <div class="overlay" id="start" data-test="pong-start">
            <div class="card">
                <h2>{{ __('vs :name', ['name' => $config['bot']]) }}</h2>
                <p>{{ __('First to :points, two points ahead', ['points' => $config['rules']['points_to_win']]) }}</p>
                <p class="hint-desktop">{{ __('Move with the mouse or W/S and the arrow keys.') }}</p>
                <p class="hint-touch">{{ __('Drag your thumb left and right.') }}</p>
                <div class="actions"><button type="button" class="btn primary" id="start-btn" data-test="pong-start-btn">{{ __('Start game') }}</button></div>
            </div>
        </div>

        <div class="overlay" id="end" hidden data-test="pong-end">
            <div class="card">
                <h2 id="end-title"></h2>
                <div class="big" id="end-score" data-test="pong-end-score"></div>
                <div class="actions">
                    <a class="btn primary" href="{{ $config['urls']['again'] }}" data-test="pong-again">{{ __('New game') }}</a>
                    <a class="btn" href="{{ $config['urls']['lobby'] }}">{{ __('Back to lobby') }}</a>
                </div>
            </div>
        </div>
    </div>
</main>

<script type="application/json" id="pong-config">@json($config)</script>
<script src="/hyper/vendor/three.min.js"></script>
</body>
</html>
