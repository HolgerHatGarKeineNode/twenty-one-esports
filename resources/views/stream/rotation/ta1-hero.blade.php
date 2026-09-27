{{--
    TA1 · Arena · upcoming tournament, hero, as an event poster. Dark left half over the game's blurred cover: status,
    the sharp cover on an orange offset, the name, the game line, the description as far as room is left. Orange right
    half: the countdown to sign-up close in fixed digit cells (it re-renders every second and must not jitter), the
    start, the seats (one per place: the faces of who is in, empty seats dashed; more than 32 places: the bar), the
    places left and the call to sign up. A full tournament says "Sign-up is full" and "Watch it live". Top right
    (x > 1040, y < 112) stays plain orange for the client's LIVE badge.

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, description, status,
    game, mode, format, rated, where, startsAt, countdown, countdownLabel, taken, places, spotsLeft, cover, url,
    roster) plus 'avatar' per roster row ('logo' for a clan) and the backdrop ($backdrop, else
    $tournament['backdrop']) per docs/plans/2026-09-27T1811-stream-avatars-imagery.md. $stats is not used here.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [44, 38, 32], 560, 2);
    $titleStep = round($title['size'] * 1.08);
    $titleY = 436 + round($title['size'] * 0.78);
    $metaY = $titleY + (count($title['lines']) - 1) * $titleStep + 36;
    $where = K::where($t);
    $meta = K::fit(K::tournamentMeta($t).($where === 'Online' ? '' : ', '.$where), K::MONO, 20, 560);
    $descY = $metaY + 32;
    $desc = K::wrap($t['description'] ?? '', K::MONO, 18, 560, max(0, min(2, intdiv(690 - $descY, 26) + 1)));
    $game = K::headline(K::text($t, 'game', 'Tournament'), [40, 32, 26], 480, 2);
    $cd = K::countdownParts($t['countdown'] ?? null);
    $note = K::countdownNote($cd, $spots['full']);
    $cells = $cd ? K::digitCells($cd['hms'], 680, 84) : null;
    $cdLabel = K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 22, 552);
    $starts = K::text($t, 'startsAt');
    // Up to 16 places two rows of eight big faces, up to 32 two rows of sixteen.
    $seats = K::seats($t, 680, 380, 552, 44, $spots['places'] <= 16 ? 8 : 16);
    $bar = $seats ? [] : K::spotCells($spots['taken'], $spots['places'], 680, 552, 4);
    $leftY = $seats ? 380 + $seats['h'] + 32 : 448;
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, 504);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.55])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>

<use href="#mark" xlink:href="#mark" x="40" y="36" width="32" height="32"/>
<text data-unit="status" data-box="83 40 600 68" x="84" y="60" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ K::fit(K::text($t, 'status', 'Sign-up open'), K::MONO, 20, 516) }}</text>

<g data-unit="cover" data-box="40 92 602 409">
<rect x="50" y="102" width="552" height="306" fill="#F7931A"/>
<rect x="40" y="92" width="552" height="306" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="92" width="552" height="306" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@else
@foreach ($game['lines'] as $i => $line)
<text x="316" y="{{ 245 - (count($game['lines']) - 1) * $game['size'] * 0.55 + $i * round($game['size'] * 1.1) + $game['size'] * 0.36 }}" font-family="{{ $game['font'] }}" font-weight="800" font-size="{{ $game['size'] }}" fill="#ADADB0" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="39 {{ $titleY + $i * $titleStep - $title['size'] }} 601 {{ $titleY + $i * $titleStep + $title['size'] * 0.25 }}" x="40" y="{{ $titleY + $i * $titleStep }}" font-family="{{ $title['font'] }}" font-weight="800" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="meta" data-box="39 {{ $metaY - 20 }} 601 {{ $metaY + 6 }}" x="40" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $meta }}</text>@endif
@foreach ($desc as $i => $line)
<text data-unit="desc-{{ $i }}" data-box="39 {{ $descY + $i * 26 - 16 }} 601 {{ $descY + $i * 26 + 6 }}" x="40" y="{{ $descY + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach

@if ($countdown !== '')<text data-unit="cd-label" x="680" y="150" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $cdLabel }}</text>@endif
<g data-countdown="{{ $countdown }}">
@if ($cells)
@if ($note)<text data-unit="cd-note" data-box="679 180 1233 224" x="680" y="214" font-family="Unbounded" font-weight="800" font-size="36" fill="#17120A">{{ $note }}</text>@endif
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 310, 'fill' => '#17120A'])
@else
<text data-unit="cd-raw" data-box="679 256 1233 316" x="680" y="306" font-family="Unbounded" font-weight="800" font-size="48" fill="#17120A">{{ K::fit($countdown, K::DISPLAY, 48, 552) }}</text>
@endif
</g>
@if ($starts !== '')<text data-unit="starts" data-box="679 332 1233 358" x="680" y="352" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ K::fit('Starts '.$starts, K::MONO, 22, 552) }}</text>@endif

@if ($seats)
@foreach ($seats['seats'] as $si => $seat)
@include('stream.rotation.partials.face', ['face' => $seat['face'], 'x' => $seat['x'], 'y' => $seat['y'], 'd' => $seats['d'], 'id' => 'seat-'.$si, 'fUnit' => 'seat-'.$si,
    'fOpen' => $seat['filled'] ? null : 'open', 'fOpenInk' => '#17120A', 'fRing' => '#17120A', 'fGround' => '#17120A', 'fGlyph' => '#6B4A1A', 'fTagFill' => '#17120A', 'fTagInk' => '#F7931A'])
@endforeach
@else
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="380" width="{{ $cell['w'] }}" height="32" fill="#17120A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="381" width="{{ $cell['w'] - 2 }}" height="30" fill="none" stroke="#17120A" stroke-width="2"/>@endif
@endforeach
</g>
@endif
<text data-unit="spots-left" x="680" y="{{ $leftY }}" font-family="Unbounded" font-weight="800" font-size="24" fill="#17120A">{{ $spots['leftText'] }}</text>

<rect x="680" y="540" width="552" height="112" fill="#17120A"/>
<text data-unit="cta" data-box="703 550 1209 606" x="704" y="592" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ $spots['full'] ? 'Watch it live' : 'Sign up' }}</text>
<text data-unit="url" data-box="703 610 1209 636" x="704" y="630" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#FFFFFF">{{ $url }}</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
