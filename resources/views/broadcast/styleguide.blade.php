{{--
    The broadcast design system (plan "OBS-Broadcast-Overlays", P1; BroadcastStyleguideController): its own document
    without the league's shell. The stage on top runs the demo program on the engine (resources/js/broadcast/
    styleguide.js); the sections below are written by the same script from the overlay modules' tokens, timing and
    curves. `?stage=1` leaves only the transparent stage, as an OBS browser source shows it.

    Order matters: three.js (Hyperbitcoinization's copy) is a classic script at the end of the body; the Vite entry is
    a module and runs after it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark{{ $stageOnly ? ' stage-only' : '' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('Broadcast design system') }} – TWENTY ONE esports</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    @fonts
    @vite(['resources/css/broadcast.css', 'resources/js/broadcast/styleguide.js'])
</head>
<body data-test="broadcast-styleguide" data-broadcast="loading">
<div class="page">
    <header class="head">
        <div>
            <h1>{{ __('Broadcast design system') }}</h1>
            <p>{{ __('The overlays for OBS: frame, type, colour, motion and tempo, with the demo program running on the engine.') }}</p>
        </div>
        <div class="actions">
            <button type="button" class="btn primary" data-action="play" hidden>{{ __('Play the demo') }}</button>
            <button type="button" class="btn" data-action="stinger" data-test="broadcast-fire-stinger">{{ __('Play stinger') }}</button>
            <button type="button" class="btn" data-action="sound" aria-pressed="false">{{ __('Sound') }}</button>
        </div>
    </header>

    <div class="stage" data-backdrop="transparent" data-test="broadcast-stage">
        <canvas id="broadcast-canvas" aria-label="{{ __('Overlay preview') }}" role="img"></canvas>
        <p class="stage-status" data-broadcast-status hidden>{{ __('This browser has no WebGL, so the overlay preview cannot run here. OBS and current browsers with graphics acceleration show it.') }}</p>
    </div>

    <div class="under">
        <div class="actions backdrops" role="group" aria-label="{{ __('Backdrop') }}">
            <button type="button" class="btn" data-backdrop="transparent" aria-pressed="true">{{ __('Transparent') }}</button>
            <button type="button" class="btn" data-backdrop="dark" aria-pressed="false">{{ __('Dark scene') }}</button>
            <button type="button" class="btn" data-backdrop="bright" aria-pressed="false">{{ __('Bright scene') }}</button>
        </div>
        <div class="live-wrap">
            <table class="live">
                <thead><tr><th>{{ __('Element') }}</th><th>{{ __('Phase') }}</th><th>{{ __('Build-in') }}</th><th>{{ __('Hold') }}</th><th>{{ __('Build-out') }}</th></tr></thead>
                <tbody data-doc="live"></tbody>
            </table>
        </div>
    </div>

    <section>
        <h2>{{ __('Tempo') }}</h2>
        <p>{{ __('Nobody can read text that moves or leaves too early. These bounds are checked against the running timeline by a test.') }}</p>
        <div class="cols">
            <div><h3>{{ __('Rules') }}</h3><dl data-doc="timing"></dl></div>
            <div><h3>{{ __('Values in use') }}</h3><dl data-doc="timing-used"></dl></div>
        </div>
    </section>

    <section>
        <h2>{{ __('Type') }}</h2>
        <p>{{ __('Unbounded carries names and what happened, JetBrains Mono carries numbers and context. Shown at their size in a 1080p frame, scaled to this window.') }}</p>
        <div data-doc="type"></div>
    </section>

    <section>
        <h2>{{ __('Colour') }}</h2>
        <p>{{ __('The site\'s ground and ink. Orange is light and material only: a hot edge, the live chip, glow. It never marks a word.') }}</p>
        <ul class="swatches" data-doc="colors"></ul>
    </section>

    <section>
        <h2>{{ __('Materials') }}</h2>
        <div class="materials">
            <article><h3>{{ __('Glass') }}</h3><p>{{ __('Smoked glass plates at 90 % cover, a lit top lip and one light sweep per build-in.') }}</p></article>
            <article><h3>{{ __('Metal') }}</h3><p>{{ __('The sides of every plate: each one is a block with depth that swings into place.') }}</p></article>
            <article><h3>{{ __('Hot edge') }}</h3><p>{{ __('An orange edge that leads every build-in and glows. Text never glows.') }}</p></article>
        </div>
    </section>

    <section>
        <h2>{{ __('Motion curves') }}</h2>
        <p>{{ __('Each kind of movement has its own curve; nothing runs on a default ease.') }}</p>
        <div class="curves" data-doc="curves"></div>
    </section>

    <section>
        <h2>{{ __('Frame and slots') }}</h2>
        <p>{{ __('Designed at 1920 by 1080 and scaled to 1440p and 4K. The centre stays free for the stream.') }}</p>
        <div class="slots" data-doc="slots"></div>
    </section>
</div>

<script type="application/json" id="broadcast-config">@json($config)</script>
<script src="/hyper/vendor/three.min.js"></script>
</body>
</html>
