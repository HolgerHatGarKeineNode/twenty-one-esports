{{--
    TB7 · Broadcast desk · the call to sign up for the next tournament. Channel frame (bug: what the tournament on show
    is doing) over the next one's blurred cover. Left: the headline "Don't watch the next one. Play it.", then the next
    tournament on a desk panel: sharp cover, name, game line, how it runs in one line. Right desk panel as the sign-up
    hero (tb1): countdown to sign-up close in fixed digit cells, seats with the faces already in, the spots left, the
    call to sign up. Without a next tournament (the scene test's sign-up frame) it says where every tournament is.

    Data contract: $tournament (TournamentLiveSlides: name, phase) and $next (a TournamentSlides frame), per
    catalog-tournaments.md; $stats for the ticker. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $n = is_array($next ?? null) ? $next : null;
    $nt = $n ?? [];
    // The context sits where b-chrome puts its note, next to the viewer badge. It is drawn here, fitted once to the room
    // the badge leaves (b-chrome would clean and fit it a second time and cut the sentence): only the name is shortened.
    $badge = K::viewerBadge($viewers ?? null, 1024, 59, K::DISPLAY, 18, 18);
    $context = K::nextContext($t, K::MONO, 18, $badge ? $badge['x0'] - 404 : 520);
    $cover = K::coverUri($nt['cover'] ?? null);
    $name = K::headline(K::text($nt, 'name', 'Tournament'), [26, 22], 360, 2);
    $nameStep = round($name['size'] * 1.12);
    $meta = K::fit(K::tournamentMeta($nt), K::MONO, 16, 360);
    $metaY = 418 + (count($name['lines']) - 1) * $nameStep + 28;
    $how = is_array($nt['howItRuns'] ?? null) ? $nt['howItRuns'] : [];
    $howLine = K::wrap(trim(K::text($how, 'short').(K::text($how, 'matches') !== '' ? '. '.K::text($how, 'matches').'.' : '')), K::MONO, 18, 528, 2);
    $spots = K::spots($nt);
    $cd = K::countdownParts($nt['countdown'] ?? null);
    $note = K::countdownNote($cd, $spots['full']);
    $cells = $cd ? K::digitCells($cd['hms'], 696, 76) : null;
    $seats = $n ? K::seats($nt, 696, 368, 512, 40, $spots['places'] <= 16 ? 8 : 16) : null;
    $bar = $n && ! $seats ? K::spotCells($spots['taken'], $spots['places'], 696, 512, 4) : [];
    $leftY = $seats ? 368 + $seats['h'] + 30 : 430;
    $url = $n ? K::tournamentUrl($nt) : 'esports.einundzwanzig.space/tournaments';
    $urlSize = K::monoSize($url, 20, 464);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $nt['backdrop'] ?? $backdrop ?? null])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])
<text data-unit="bug-note" data-box="380 36 {{ $badge ? $badge['x0'] - 24 : 900 }} 66" x="380" y="59" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $context }}</text>

<text data-unit="headline-0" data-box="39 {{ 160 - 48 }} 617 {{ 160 + 12 }}" x="40" y="160" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">Don't watch</text>
<text data-unit="headline-1" data-box="39 {{ 216 - 48 }} 617 {{ 216 + 12 }}" x="40" y="216" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">the next one.</text>
<text data-unit="headline-2" data-box="39 {{ 272 - 48 }} 617 {{ 272 + 12 }}" x="40" y="272" font-family="Unbounded" font-weight="800" font-size="44" fill="#F7931A">Play it.</text>

<rect x="40" y="320" width="576" height="316" fill="#121215" fill-opacity="0.92"/>
@if ($n)
<g data-unit="cover" data-box="64 344 222 434">
<rect x="69" y="349" width="152" height="86" fill="#F7931A"/>
<rect x="64" y="344" width="152" height="86" fill="#1A1A1D"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg). --}}
<image x="64" y="344" width="152" height="86" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@endif
</g>
<text data-unit="next-label" x="236" y="362" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">Up next</text>
@foreach ($name['lines'] as $i => $line)
<text data-unit="next-name-{{ $i }}" data-box="235 {{ 394 + $i * $nameStep - $name['size'] }} 601 {{ 394 + $i * $nameStep + $name['size'] * 0.25 }}" x="236" y="{{ 394 + $i * $nameStep }}" font-family="{{ $name['font'] }}" font-weight="800" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="next-meta" data-box="235 {{ $metaY - 16 }} 601 {{ $metaY + 5 }}" x="236" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $meta }}</text>@endif
@foreach ($howLine as $i => $line)
<text data-unit="how-{{ $i }}" data-box="63 {{ 520 + $i * 26 - 18 }} 601 {{ 520 + $i * 26 + 6 }}" x="64" y="{{ 520 + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@else
<text data-unit="no-next" x="64" y="380" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">Every tournament and cup is listed on the site.</text>
@endif

<rect x="664" y="112" width="576" height="524" fill="#121215" fill-opacity="0.9"/>
@if ($cells)
<text data-unit="cd-label" x="696" y="156" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ K::fit(K::text($nt, 'countdownLabel', 'Sign-up closes in'), K::MONO, 20, 512) }}</text>
<g data-countdown="{{ $nt['countdown'] }}">
@if ($note)<text data-unit="cd-note" data-box="695 176 1209 216" x="696" y="208" font-family="Unbounded" font-weight="800" font-size="32" fill="#FFFFFF">{{ $note }}</text>@endif
@include('stream.rotation.partials.clock', ['c' => $cells, 'y' => 296, 'fill' => '#F7931A'])
</g>
@endif
@if ($seats)
@foreach ($seats['seats'] as $si => $seat)
@include('stream.rotation.partials.face', ['face' => $seat['face'], 'x' => $seat['x'], 'y' => $seat['y'], 'd' => $seats['d'], 'id' => 'tb7-seat-'.$si, 'fUnit' => 'seat-'.$si, 'fOpen' => $seat['filled'] ? null : 'open', 'fRing' => '#F7931A'])
@endforeach
@elseif ($bar !== [])
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="368" width="{{ $cell['w'] }}" height="32" fill="#F7931A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="369" width="{{ $cell['w'] - 2 }}" height="30" fill="none" stroke="#6B6B72" stroke-width="2"/>@endif
@endforeach
</g>
@endif
@if ($n)<text data-unit="spots-left" x="696" y="{{ $leftY }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#F7931A">{{ $spots['leftText'] }}</text>@endif

<rect x="696" y="504" width="512" height="112" fill="#F7931A"/>
<text data-unit="cta" data-box="719 514 1185 564" x="720" y="554" font-family="Unbounded" font-weight="800" font-size="40" fill="#17120A">{{ $n ? 'Sign up' : 'Find yours' }}</text>
<text data-unit="url" data-box="719 576 1185 602" x="720" y="596" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
</svg>
