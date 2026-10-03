{{--
    The frame of the running tournament slides (tv1..tv4): the tournament TV's header and footer
    (pages::tournaments.tv) at 1280x720, laid out by TvSlides. Header: the game's cover (the game mark), the name, the
    meta line (game, format, the Live chip, matches played), the QR code to the tournament page with its caption,
    ending at x 1024 so the client's LIVE badge keeps the corner (x >= 1040, y < 112). Footer under a rule at y 643:
    "Latest" with the results that fit, the viewer badge, and the tabs of this hold with the one on show marked.

    Params: $t (the tournament frame), $part (1..4, the slide on show), $viewers (int|null).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\TvSlides', 'Tv')
@php
    $hd = Tv::header($t);
    $ft = Tv::footer($t, $part, $viewers ?? null);
    $coverUri = K::coverUri($t['cover'] ?? null);
    $url = K::tournamentUrl($t);
    $qrCode = Tv::qr(str_starts_with($url, 'http') ? $url : 'https://'.$url);
    [$urlHost, $urlPath] = K::urlLines($t);
    $urlSize = K::monoSize($urlHost.'/', 16, 240);
@endphp
<rect width="1280" height="720" fill="{{ Tv::GROUND }}"/>

<g data-unit="game-mark" data-box="38 44 202 136">
<clipPath id="tv-cover-clip"><rect x="38" y="44" width="164" height="92" rx="5"/></clipPath>
<rect x="38" y="44" width="164" height="92" rx="5" fill="{{ Tv::RAISED }}"/>
@if ($coverUri)<image x="38" y="44" width="164" height="92" preserveAspectRatio="xMidYMid slice" clip-path="url(#tv-cover-clip)" xlink:href="{{ $coverUri }}"/>@endif
</g>
@foreach ($hd['title']['lines'] as $i => $line)
<text data-unit="title-{{ $i }}" x="222" y="{{ $hd['titleY'][$i] }}" font-family="{{ $hd['title']['font'] }}" font-weight="800" font-size="{{ $hd['title']['size'] }}" fill="{{ Tv::INK }}">{{ $line }}</text>
@endforeach
@foreach ($hd['meta'] as $i => $token)
@if ($token['kind'] === 'live')
<g data-unit="status"><rect x="{{ $token['x'] }}" y="{{ $token['y'] - 16 }}" width="{{ $token['w'] }}" height="22" rx="2.5" fill="{{ Tv::BTC }}"/><circle cx="{{ $token['x'] + 13 }}" cy="{{ $token['y'] - 5 }}" r="3.8" fill="{{ Tv::ON_BTC }}"/><text x="{{ $token['x'] + 23 }}" y="{{ $token['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ Tv::ON_BTC }}">Live</text></g>
@else
<text data-unit="{{ $token['kind'] === 'status' ? 'status' : 'meta-'.$i }}" x="{{ $token['x'] }}" y="{{ $token['y'] }}" font-family="JetBrains Mono" font-weight="{{ $token['kind'] === 'status' ? 700 : 400 }}" font-size="16" fill="{{ $token['kind'] === 'status' ? Tv::INK : Tv::INK2 }}">{{ $token['text'] }}</text>
@endif
@endforeach

@if ($qrCode)
<rect x="896" y="26" width="128" height="128" rx="4" fill="#FFFFFF"/>
<svg x="908" y="38" width="104" height="104" viewBox="0 0 {{ $qrCode['n'] }} {{ $qrCode['n'] }}" shape-rendering="crispEdges"><path d="{{ $qrCode['d'] }}" fill="#000000"/></svg>
@endif
<text data-unit="qr-lead" x="640" y="70" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ Tv::INK }}">Follow on your phone</text>
<text data-unit="url" x="640" y="93" font-family="JetBrains Mono" font-weight="400" font-size="{{ $urlSize }}" fill="{{ Tv::INK2 }}">{{ $urlHost }}/</text>
@if ($urlPath !== '')<text data-unit="url-path" x="640" y="114" font-family="JetBrains Mono" font-weight="400" font-size="{{ $urlSize }}" fill="{{ Tv::INK2 }}">{{ K::fit(ltrim($urlPath, '/'), K::MONO, $urlSize, 240) }}</text>@endif

<rect x="38" y="643" width="1204" height="1" fill="{{ Tv::LINE }}"/>
<text data-unit="latest" x="38" y="677" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ Tv::BTC_HI }}">Latest</text>
@foreach ($ft['results'] as $i => $r)
<text data-unit="tick-{{ $i }}" x="{{ $r['x'] }}" y="677" font-family="JetBrains Mono" font-size="16"><tspan font-weight="700" fill="{{ Tv::INK }}">{{ $r['winner'] }}</tspan><tspan x="{{ $r['restX'] }}" font-weight="400" fill="{{ Tv::INK2 }}">{{ $r['rest'] }}</tspan></text>
@endforeach
@if ($ft['empty'] !== null)<text data-unit="tick-empty" x="{{ 38 + K::width('Latest', K::MONO, 16) + 19 }}" y="677" font-family="JetBrains Mono" font-size="16" fill="{{ Tv::INK2 }}">{{ $ft['empty'] }}</text>@endif
@if ($ft['viewers'])@include('stream.rotation.partials.viewers', ['vb' => $ft['viewers'], 'eyeInk' => Tv::BTC, 'countInk' => Tv::INK, 'wordInk' => Tv::INK2])@endif
@foreach ($ft['tabs'] as $i => $tab)
<text data-unit="tab-{{ $i }}" x="{{ $tab['x'] }}" y="677" font-family="JetBrains Mono" font-weight="{{ $tab['active'] ? 700 : 400 }}" font-size="16" fill="{{ $tab['active'] ? Tv::INK : Tv::INK3 }}">{{ $tab['label'] }}</text>
@if ($tab['active'])<rect x="{{ $tab['x'] }}" y="688" width="{{ round($tab['w'], 1) }}" height="3" rx="1.5" fill="{{ Tv::BTC }}"/>@endif
@endforeach
