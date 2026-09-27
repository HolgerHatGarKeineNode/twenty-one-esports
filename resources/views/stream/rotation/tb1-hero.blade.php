{{--
    TB1 · Broadcast desk · upcoming tournament, hero. Channel frame (bug with the status as note, orange ticker).
    Left: the cover (or a neutral game panel), the name, the game line, the description as far as room is left.
    Right: a desk panel with the countdown to sign-up close in fixed digit cells (it re-renders every second), the
    start, the spots bar and the orange call to sign up. A full tournament says "Sign-up is full" and "Watch it live".

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, description, status,
    game, mode, format, rated, where, startsAt, countdown, countdownLabel, taken, places, spotsLeft, cover, url);
    $stats for the ticker.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [40, 34, 28], 576, 2);
    $titleY = 468 + $title['size'];
    $titleStep = round($title['size'] * 1.1);
    $metaY = $titleY + (count($title['lines']) - 1) * $titleStep + 34;
    $meta = K::fit(K::tournamentMeta($t), K::MONO, 20, 576);
    $where = K::fit(K::where($t).', '.mb_strtolower(K::ratedLabel($t)), K::MONO, 20, 576);
    $descY = $metaY + 58;
    $desc = K::wrap($t['description'] ?? '', K::MONO, 18, 576, max(0, min(2, intdiv(644 - $descY, 26) + 1)));
    $game = K::headline(K::text($t, 'game', 'Tournament'), [40, 32, 26], 496, 2);
    $cd = K::countdownParts($t['countdown'] ?? null);
    $note = K::countdownNote($cd, $spots['full']);
    $cells = $cd ? K::digitCells($cd['hms'], 696, 76) : null;
    $starts = K::text($t, 'startsAt');
    $bar = K::spotCells($spots['taken'], $spots['places'], 696, 512, 4);
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, 464);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::fit(K::text($t, 'status', 'Sign-up open'), K::MONO, 18, 520)])

<g data-unit="cover" data-box="40 112 616 436">
<rect x="40" y="112" width="576" height="324" fill="#121215"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="112" width="576" height="324" preserveAspectRatio="xMidYMid meet" xlink:href="{{ $cover }}"/>
@else
@foreach ($game['lines'] as $i => $line)
<text x="328" y="{{ 274 - (count($game['lines']) - 1) * $game['size'] * 0.55 + $i * round($game['size'] * 1.1) + $game['size'] * 0.36 }}" font-family="{{ $game['font'] }}" font-weight="800" font-size="{{ $game['size'] }}" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="39 {{ $titleY + $i * $titleStep - $title['size'] }} 617 {{ $titleY + $i * $titleStep + $title['size'] * 0.25 }}" x="40" y="{{ $titleY + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="meta" data-box="39 {{ $metaY - 20 }} 617 {{ $metaY + 6 }}" x="40" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $meta }}</text>@endif
<text data-unit="where" data-box="39 {{ $metaY + 6 }} 617 {{ $metaY + 32 }}" x="40" y="{{ $metaY + 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $where }}</text>
@foreach ($desc as $i => $line)
<text data-unit="desc-{{ $i }}" data-box="39 {{ $descY + $i * 26 - 16 }} 617 {{ $descY + $i * 26 + 6 }}" x="40" y="{{ $descY + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach

<rect x="664" y="112" width="576" height="536" fill="#121215"/>
@if ($countdown !== '')<text data-unit="cd-label" x="696" y="156" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 20, 512) }}</text>@endif
<g data-countdown="{{ $countdown }}">
@if ($cells)
@if ($note)<text data-unit="cd-note" data-box="695 176 1209 216" x="696" y="208" font-family="Unbounded" font-weight="800" font-size="32" fill="#FFFFFF">{{ $note }}</text>@endif
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 296, 'fill' => '#F7931A'])
@else
<text data-unit="cd-raw" data-box="695 250 1209 300" x="696" y="290" font-family="Unbounded" font-weight="800" font-size="44" fill="#F7931A">{{ K::fit($countdown, K::DISPLAY, 44, 512) }}</text>
@endif
</g>
@if ($starts !== '')<text data-unit="starts" data-box="695 326 1209 350" x="696" y="344" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit('Starts '.$starts, K::MONO, 20, 512) }}</text>@endif

<text data-unit="spots-taken" x="696" y="392" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $spots['takenText'] }}</text>
<text data-unit="spots-left" x="1208" y="392" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A" text-anchor="end">{{ $spots['leftText'] }}</text>
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="406" width="{{ $cell['w'] }}" height="32" fill="#F7931A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="407" width="{{ $cell['w'] - 2 }}" height="30" fill="none" stroke="#6B6B72" stroke-width="2"/>@endif
@endforeach
</g>

<rect x="696" y="470" width="512" height="112" fill="#F7931A"/>
<text data-unit="cta" data-box="719 480 1185 530" x="720" y="520" font-family="Unbounded" font-weight="800" font-size="40" fill="#17120A">{{ $spots['full'] ? 'Watch it live' : 'Sign up' }}</text>
<text data-unit="url" data-box="719 542 1185 568" x="720" y="562" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
@unless ($spots['full'])<text data-unit="pull-out" x="696" y="618" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">You can pull out until sign-up closes.</text>@endunless
</svg>
