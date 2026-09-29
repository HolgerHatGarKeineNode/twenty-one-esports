{{--
    TC7 · Terminal ticker · the call to sign up for the next tournament. Header line ("next up") and stats bar over the
    next one's blurred cover. Left: what the tournament on show is doing, the headline "Don't watch the next one. Play
    it." in mono, then the next tournament: cover stage, name, game line, how it runs in one line. Right of the rule:
    the countdown to sign-up close, the seats with the faces already in, the spots left. Above the stats bar an orange
    line: the call to sign up and the address. Without a next tournament (the scene test's sign-up frame) it says
    where every tournament is.

    Data contract: $tournament (TournamentLiveSlides: name, phase) and $next (a TournamentSlides frame), per
    catalog-tournaments.md; $stats for the stats bar. Every key may be missing.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $t = is_array($tournament ?? null) ? $tournament : [];
    $n = is_array($next ?? null) ? $next : null;
    $nt = $n ?? [];
    $context = K::nextContext($t, K::MONO, 18, 720);
    $cover = K::coverUri($nt['cover'] ?? null);
    $name = K::headline(K::text($nt, 'name', 'Tournament'), [24, 20], 440, 2, K::MONO);
    $nameStep = round($name['size'] * 1.2);
    $meta = K::fit(K::tournamentMeta($nt), K::MONO, 16, 440);
    $metaY = 306 + (count($name['lines']) - 1) * $nameStep + 32;
    $how = is_array($nt['howItRuns'] ?? null) ? $nt['howItRuns'] : [];
    $howLine = K::wrap(trim(K::text($how, 'short').(K::text($how, 'matches') !== '' ? '. '.K::text($how, 'matches').'.' : '')), K::MONO, 18, 720, 2);
    $spots = K::spots($nt);
    $cd = K::countdownParts($nt['countdown'] ?? null);
    $cdText = $cd ? ($cd['days'] > 0 ? $cd['days'].'d ' : '').$cd['hms'] : '';
    $seats = $n ? K::seats($nt, 832, 250, 408, 36, $spots['places'] <= 16 ? 8 : 16, 32, 6) : null;
    $bar = $n && ! $seats ? K::spotCells($spots['taken'], $spots['places'], 832, 408, 4) : [];
    $leftY = $seats ? 250 + $seats['h'] + 32 : 314;
    $cta = $n ? 'Sign up' : 'Find yours';
    $urlX = 56 + mb_strlen($cta) * 14.4 + 24;
    $url = $n ? K::tournamentUrl($nt) : 'esports.einundzwanzig.space/tournaments';
    $urlSize = K::monoSize($url, 20, 1224 - $urlX);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $nt['backdrop'] ?? $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'next up'])

<text data-unit="context" data-box="39 {{ 108 - 18 }} 761 {{ 108 + 6 }}" x="40" y="108" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#A1A1A7">{{ $context }}</text>
<text data-unit="headline-0" data-box="39 {{ 160 - 38 }} 761 {{ 160 + 10 }}" x="40" y="160" font-family="JetBrains Mono" font-weight="700" font-size="38" fill="#FFFFFF">Don't watch the next one.</text>
<text data-unit="headline-1" data-box="39 {{ 210 - 38 }} 761 {{ 210 + 10 }}" x="40" y="210" font-family="JetBrains Mono" font-weight="700" font-size="38" fill="#F7931A">Play it.</text>
@if ($n)
<g data-unit="cover" data-box="38 250 258 376">
<rect x="39" y="251" width="218" height="124" fill="#16161A" stroke="#F7931A" stroke-width="2"/>
@if ($cover)
{{-- The cover once, as xlink:href (SVG 1.1, read by every librsvg). --}}
<image x="40" y="252" width="216" height="122" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $cover }}"/>
@endif
</g>
<text data-unit="next-label" x="280" y="272" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">up next</text>
@foreach ($name['lines'] as $i => $line)
<text data-unit="next-name-{{ $i }}" data-box="279 {{ 306 + $i * $nameStep - $name['size'] }} 761 {{ 306 + $i * $nameStep + 8 }}" x="280" y="{{ 306 + $i * $nameStep }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $name['size'] }}" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@if ($meta !== '')<text data-unit="next-meta" data-box="279 {{ $metaY - 16 }} 761 {{ $metaY + 5 }}" x="280" y="{{ $metaY }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">{{ $meta }}</text>@endif
@foreach ($howLine as $i => $line)
<text data-unit="how-{{ $i }}" data-box="39 {{ 440 + $i * 26 - 18 }} 761 {{ 440 + $i * 26 + 6 }}" x="40" y="{{ 440 + $i * 26 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $line }}</text>
@endforeach
@else
<text data-unit="no-next" x="40" y="300" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">Every tournament and cup is listed on the site.</text>
@endif

<rect x="800" y="88" width="1" height="456" fill="#2A2A30"/>
@if ($cdText !== '')
<text data-unit="cd-label" x="832" y="130" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7">{{ mb_strtolower(K::fit(K::text($nt, 'countdownLabel', 'Sign-up closes in'), K::MONO, 16, 408)) }}</text>
<g data-countdown="{{ $nt['countdown'] }}"><text data-unit="countdown" data-box="831 150 1241 200" x="832" y="190" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize($cdText, 48, 408) }}" fill="#F7931A">{{ $cdText }}</text></g>
@endif
@if ($seats)
@foreach ($seats['seats'] as $si => $seat)
@include('stream.rotation.partials.face', ['face' => $seat['face'], 'x' => $seat['x'], 'y' => $seat['y'], 'd' => $seats['d'], 'id' => 'tc7-seat-'.$si, 'fUnit' => 'seat-'.$si, 'fShape' => 'square', 'fOpen' => $seat['filled'] ? null : 'open', 'fRing' => '#F7931A'])
@endforeach
@elseif ($bar !== [])
<g data-unit="spots-bar">
@foreach ($bar as $cell)
@if ($cell['filled'])<rect x="{{ $cell['x'] }}" y="250" width="{{ $cell['w'] }}" height="28" fill="#F7931A"/>@else<rect x="{{ $cell['x'] + 1 }}" y="251" width="{{ $cell['w'] - 2 }}" height="26" fill="none" stroke="#6B6B72" stroke-width="2"/>@endif
@endforeach
</g>
@endif
@if ($n)<text data-unit="spots-left" x="832" y="{{ $leftY }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ mb_strtolower($spots['leftText']) }}</text>@endif

<rect x="40" y="560" width="1200" height="48" fill="#F7931A"/>
<text data-unit="cta" x="56" y="593" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#17120A">{{ $cta }}</text>
<text data-unit="url" data-box="{{ $urlX - 1 }} 572 1225 598" x="{{ $urlX }}" y="592" font-family="JetBrains Mono" font-weight="700" font-size="{{ $urlSize }}" fill="#17120A">{{ $url }}</text>
</svg>
