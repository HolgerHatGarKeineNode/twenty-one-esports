{{--
    An OBS overlay (plan "OBS-Broadcast-Overlays", P2; BroadcastOverlayController): the preset's variant on the broadcast
    engine, alone on a transparent page, as an OBS browser source shows it. No session, no cookie: the token in the URL
    is the key. resources/js/broadcast/overlay.js reads the config below (the first snapshot included), listens on the
    public `league.feed` channel and polls the snapshot every 20 s. P3-P6 fill the variants; the free centre stays free.

    Order matters: three.js (Hyperbitcoinization's copy) is a classic script at the end of the body; the Vite entry is
    a module and runs after it.
--}}
<!DOCTYPE html>
<html lang="{{ $preset->locale }}" class="dark stage-only">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    @php($reverb = config('broadcasting.connections.reverb'))
    @if (filled($reverb['key'] ?? null))
        <meta name="reverb" content="{{ json_encode(['key' => $reverb['key'], 'host' => $reverb['options']['host'] ?? request()->getHost(), 'port' => (int) ($reverb['options']['port'] ?? 443), 'scheme' => $reverb['options']['scheme'] ?? 'https']) }}">
    @endif
    <title>{{ $preset->name }} – {{ $preset->variant->label() }} – TWENTY ONE esports</title>
    @fonts
    @vite(['resources/css/broadcast.css', 'resources/js/broadcast/overlay.js'])
</head>
<body data-test="broadcast-overlay" data-variant="{{ $preset->variant->value }}" data-broadcast="loading">
<div class="page">
    <div class="stage" data-backdrop="transparent" data-test="broadcast-stage">
        <canvas id="broadcast-canvas" aria-label="{{ $preset->name }}" role="img"></canvas>
    </div>
</div>

<script type="application/json" id="broadcast-config">@json($config)</script>
<script src="/hyper/vendor/three.min.js"></script>
</body>
</html>
