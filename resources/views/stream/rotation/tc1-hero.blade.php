{{--
    TC1 · Terminal ticker · upcoming tournament, hero. Header line and stats bar as every C scene, over the game's
    blurred cover. Top: the sharp cover (or a neutral game panel) and, beside it, the name and a key/value table
    (game, format, starts). Middle: the description. Bottom: the countdown to sign-up close in JetBrains Mono (every
    glyph 0.6 em, so the ticking seconds never shift), the seats (one per place: the faces of who is in, empty seats
    dashed; more than 32 places: the bar) with the places left, and the orange call to sign up. A full tournament
    says "Sign-up is full" and "Watch it live".

    Data contract: $tournament as in docs/plans/2026-09-27T1456-stream-tournament-slides.md (name, description, game,
    mode, format, rated, where, startsAt, countdown, countdownLabel, taken, places, spotsLeft, cover, url, roster)
    plus 'avatar' per roster row ('logo' for a clan) and the backdrop ($backdrop, else $tournament['backdrop']) per
    docs/plans/2026-09-27T1811-stream-avatars-imagery.md; $stats for the stats bar.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $countdown = is_string($t['countdown'] ?? null) ? $t['countdown'] : '';
    $spots = K::spots($t);
    $cover = K::coverUri($t['cover'] ?? null);
    $title = K::headline(K::text($t, 'name', 'Tournament'), [36, 30], 736, 2, K::MONO);
    $titleStep = round($title['size'] * 1.2);
    // First baseline: ascenders (0.8 em) start at y 116, below the LIVE corner (y < 112 for x > 1040).
    $titleY = 116 + round($title['size'] * 0.8);
    $rowY = $titleY + (count($title['lines']) - 1) * $titleStep + 44;
    $game = K::text($t, 'game').(K::text($t, 'mode') === '' ? '' : ', '.K::text($t, 'mode'));
    $where = K::where($t);
    $facts = [
        ['game', $game],
        ['format', K::text($t, 'format').($where === 'Online' ? '' : ', '.$where)],
        ['starts', K::text($t, 'startsAt')],
    ];
    $desc = K::wrap($t['description'] ?? '', K::MONO, 18, 720, 2);
    $cd = K::countdownParts($countdown);
    $cdText = $cd ? ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] : K::fit($countdown, K::MONO, 80, 720);
    $cdSize = K::monoSize($cdText, 80, 720);
    $note = $cd && $cd['days'] === 0 ? K::countdownNote($cd, $spots['full']) : null;
    $seats = K::seats($t, 780, 436, 460, 34, $spots['places'] <= 16 ? 8 : 16, 32, 4);
    $bar = $seats ? [] : K::spotCells($spots['taken'], $spots['places'], 780, 460, 4);
    $url = K::tournamentUrl($t);
    $urlSize = K::monoSize($url, 20, 428);
    $gameLines = K::wrap(K::text($t, 'game', 'Tournament'), K::MONO, 28, 400, 2);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? ($t['backdrop'] ?? null), 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'upcoming tournament'])

<g data-unit="cover" data-box="40 96 472 339">
<rect x="40" y="96" width="432" height="243" fill="#16161A"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg): a second href would double the 43 kB data URI. --}}
<image x="40" y="96" width="432" height="243" preserveAspectRatio="xMidYMid meet" xlink:href="{{ $cover }}"/>
@else
@foreach ($gameLines as $i => $line)
<text x="256" y="{{ 227 - (count($gameLines) - 1) * 17 + $i * 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#A1A1A7" text-anchor="middle">{{ $line }}</text>
@endforeach
@endif
</g>
@foreach ($title['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" data-box="503 {{ $titleY - $title['size'] + $i * $titleStep }} 1241 {{ $titleY + $i * $titleStep + 10 }}" x="504" y="{{ $titleY + $i * $titleStep }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $title['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@foreach ($facts as $i => [$key, $value])
@php($fy = $rowY + $i * 34)
<rect x="504" y="{{ $fy - 24 }}" width="736" height="1" fill="#2A2A30"/>
<text data-unit="fact-key-{{ $i }}" x="504" y="{{ $fy }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">{{ $key }}</text>
<text data-unit="fact-{{ $i }}" data-box="599 {{ $fy - 20 }} 1241 {{ $fy + 6 }}" x="600" y="{{ $fy }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit($value === '' ? '-' : $value, K::MONO, 20, 640) }}</text>
@endforeach

@foreach ($desc as $i => $line)
<text data-unit="desc-{{ $i }}" data-box="39 {{ 364 + $i * 26 }} 761 {{ 386 + $i * 26 }}" x="40" y="{{ 380 + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $line }}</text>
@endforeach

@if ($countdown !== '')<text data-unit="cd-label" x="40" y="464" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ K::fit(K::text($t, 'countdownLabel', 'Sign-up closes in'), K::MONO, 18, 720) }}</text>@endif
<text data-unit="countdown" data-countdown="{{ $countdown }}" data-box="39 470 761 556" x="40" y="548" font-family="JetBrains Mono" font-weight="700" font-size="{{ $cdSize }}" fill="#F7931A">{{ $cdText }}</text>
@if ($note)<text data-unit="cd-note" x="40" y="590" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $note }}</text>@endif

<text data-unit="spots-left" x="780" y="422" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $spots['leftText'] }}</text>
@if ($seats)
@foreach ($seats['seats'] as $si => $seat)
@include('stream.rotation.partials.face', ['face' => $seat['face'], 'x' => $seat['x'], 'y' => $seat['y'], 'd' => $seats['d'], 'id' => 'seat-'.$si, 'fUnit' => 'seat-'.$si,
    'fShape' => 'square', 'fOpen' => $seat['filled'] ? null : 'open', 'fRing' => '#F7931A'])
@endforeach
@else
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="446" width="{{ $cell['w'] }}" height="28" fill="#F7931A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="447" width="{{ $cell['w'] - 2 }}" height="26" fill="none" stroke="#6B6B72" stroke-width="2"/>@endif
@endforeach
</g>
@endif
<rect x="780" y="522" width="460" height="80" fill="#F7931A"/>
<text data-unit="cta" data-box="795 528 1225 564" x="796" y="556" font-family="JetBrains Mono" font-weight="700" font-size="30" fill="#17120A">{{ $spots['full'] ? 'Watch it live' : 'Sign up' }}</text>
<text data-unit="url" data-box="795 570 1225 596" x="796" y="588" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
</svg>
