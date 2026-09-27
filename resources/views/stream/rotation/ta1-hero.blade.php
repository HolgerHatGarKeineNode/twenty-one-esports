{{--
    TA1 · Arena · upcoming tournament, hero. Dark left half: status, name, game line, the cover, the description.
    Orange right half: the countdown to sign-up close in fixed digit cells (it re-renders every second and must not
    jitter), the start, the spots bar, the call to sign up. A full tournament says "Sign-up is full" and "Watch it
    live". Top right (x > 1040, y < 112) stays plain orange for the client's LIVE badge.

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, description, status,
    game, mode, format, rated, where, startsAt, countdown, countdownLabel, taken, places, spotsLeft, cover, url).
    $stats is not used here.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [48, 40, 34], 560, 2);
    $titleY = 84 + $title['size'];
    $titleStep = round($title['size'] * 1.08);
    $metaY = $titleY + (count($title['lines']) - 1) * $titleStep + 38;
    $meta = K::fit(K::tournamentMeta($t), K::MONO, 20, 560);
    $where = K::fit(K::where($t).', '.mb_strtolower(K::ratedLabel($t)), K::MONO, 20, 560);
    $desc = K::wrap($t['description'] ?? '', K::MONO, 18, 560, 3);
    $game = K::headline(K::text($t, 'game', 'Tournament'), [40, 32, 26], 480, 2);
    $cd = K::countdownParts($t['countdown'] ?? null);
    $note = K::countdownNote($cd, $spots['full']);
    $cells = $cd ? K::digitCells($cd['hms'], 680, 84) : null;
    $cdLabel = K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 22, 552);
    $starts = K::text($t, 'startsAt');
    $bar = K::spotCells($spots['taken'], $spots['places'], 680, 552, 4);
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, 504);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>

<use href="#mark" xlink:href="#mark" x="40" y="40" width="32" height="32"/>
<text data-unit="status" data-box="83 44 600 72" x="84" y="64" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ K::fit(K::text($t, 'status', 'Sign-up open'), K::MONO, 20, 516) }}</text>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="39 {{ $titleY + $i * $titleStep - $title['size'] }} 601 {{ $titleY + $i * $titleStep + $title['size'] * 0.25 }}" x="40" y="{{ $titleY + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="meta" data-box="39 {{ $metaY - 20 }} 601 {{ $metaY + 6 }}" x="40" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $meta }}</text>@endif
<text data-unit="where" data-box="39 {{ $metaY + 8 }} 601 {{ $metaY + 34 }}" x="40" y="{{ $metaY + 28 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $where }}</text>

<g data-unit="cover" data-box="40 276 584 582">
<rect x="40" y="276" width="544" height="306" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="276" width="544" height="306" preserveAspectRatio="xMidYMid meet" xlink:href="{{ $cover }}"/>
@else
@foreach ($game['lines'] as $i => $line)
<text x="312" y="{{ 429 - (count($game['lines']) - 1) * $game['size'] * 0.55 + $i * round($game['size'] * 1.1) + $game['size'] * 0.36 }}" font-family="{{ $game['font'] }}" font-weight="800" font-size="{{ $game['size'] }}" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($desc as $i => $line)
<text data-unit="desc-{{ $i }}" data-box="39 {{ 604 + $i * 26 }} 601 {{ 626 + $i * 26 }}" x="40" y="{{ 620 + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach

@if ($countdown !== '')<text data-unit="cd-label" x="680" y="160" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $cdLabel }}</text>@endif
<g data-countdown="{{ $countdown }}">
@if ($cells)
@if ($note)<text data-unit="cd-note" data-box="679 200 1233 244" x="680" y="236" font-family="Unbounded" font-weight="800" font-size="36" fill="#17120A">{{ $note }}</text>@endif
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 336, 'fill' => '#17120A'])
@else
<text data-unit="cd-raw" data-box="679 280 1233 340" x="680" y="330" font-family="Unbounded" font-weight="800" font-size="48" fill="#17120A">{{ K::fit($countdown, K::DISPLAY, 48, 552) }}</text>
@endif
</g>
@if ($starts !== '')<text data-unit="starts" data-box="679 370 1233 396" x="680" y="390" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ K::fit('Starts '.$starts, K::MONO, 22, 552) }}</text>@endif

<text data-unit="spots-taken" x="680" y="438" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">{{ $spots['takenText'] }}</text>
<text data-unit="spots-left" x="1232" y="438" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A" text-anchor="end">{{ $spots['leftText'] }}</text>
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="452" width="{{ $cell['w'] }}" height="32" fill="#17120A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="453" width="{{ $cell['w'] - 2 }}" height="30" fill="none" stroke="#17120A" stroke-width="2"/>@endif
@endforeach
</g>

<rect x="680" y="512" width="552" height="104" fill="#17120A"/>
<text data-unit="cta" data-box="703 522 1209 574" x="704" y="560" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ $spots['full'] ? 'Watch it live' : 'Sign up' }}</text>
<text data-unit="url" data-box="703 578 1209 604" x="704" y="598" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ $url }}</text>
@unless ($spots['full'])<text data-unit="pull-out" x="680" y="652" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">You can pull out until sign-up closes.</text>@endunless
</svg>
