{{--
    E8 · Arena · pride: on a streak. Full orange frame as a4-join; the longest win streak still running (chess and the
    board games that are on, the last 30 days, three wins or more; a draw or a loss ends one) as the huge number, the
    player's face and name beside it, the games it came from; the next two streaks small below. Without a streak:
    the invitation to start one.

    Data contract:
      $pride     array{streaks: list<array{name: string, avatar: ?string, wins: int, games: list<string>}>, …} (PrideSlides::all())
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $streaks = K::prideRows($pride['streaks'] ?? [], 3);
    $hero = $streaks[0] ?? null;
    if ($hero) {
        $wins = max(0, (int) ($hero['wins'] ?? 0));
        $numSize = $wins >= 100 ? 200 : 280;
        $numW = K::width((string) $wins, K::DISPLAY, $numSize) * 1.04;
        $hx = (int) min(560, 64 + $numW + 40);
        $name = K::name($hero['name'], 'Player', 44, 1240 - $hx);
        $games = K::fit(K::listing($hero['games'] ?? [], 3) === '' ? '' : 'in '.K::listing($hero['games'] ?? [], 3), K::MONO, 22, 1240 - $hx);
    }
    $more = [];
    foreach (array_slice($streaks, 1, 2) as $i => $s) {
        $more[] = ['x' => 40 + $i * 440, 'face' => K::prideFace($s), 'name' => K::name($s['name'], 'Player', 24, 320), 'wins' => max(0, (int) ($s['wins'] ?? 0)).' in a row'];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.2])
<rect width="1280" height="720" fill="#F7931A" fill-opacity="{{ K::backdropUri($backdrop ?? null) ? 0.94 : 1 }}"/>
<use href="#mark-dark" xlink:href="#mark-dark" x="40" y="48" width="64" height="64"/>
<text x="124" y="92" font-family="Unbounded" font-weight="800" font-size="32" fill="#17120A">On a streak</text>
@if ($hero)
<text data-unit="streak-wins" data-box="36 186 {{ $hx - 16 }} 456" x="40" y="440" font-family="Unbounded" font-weight="800" font-size="{{ $numSize }}" fill="#17120A">{{ $wins }}</text>
@include('stream.rotation.partials.face', ['face' => K::prideFace($hero), 'x' => $hx, 'y' => 170, 'd' => 150, 'id' => 'streak', 'fUnit' => 'streak-face', 'fRing' => '#17120A', 'fGround' => '#17120A', 'fCrown' => '#17120A'])
<text data-unit="streak-name" data-box="{{ $hx - 1 }} 344 1240 394" x="{{ $hx }}" y="384" font-family="{{ $name['font'] }}" font-weight="800" font-size="44" fill="#17120A">{{ $name['text'] }}</text>
<text data-unit="streak-row" data-box="{{ $hx - 1 }} 404 1240 434" x="{{ $hx }}" y="428" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A">wins in a row</text>
@if ($games !== '')<text data-unit="streak-games" data-box="{{ $hx - 1 }} 446 1240 474" x="{{ $hx }}" y="468" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ $games }}</text>@endif
@foreach ($more as $mi => $m)
<rect x="{{ $m['x'] }}" y="520" width="416" height="96" fill="#17120A"/>
@include('stream.rotation.partials.face', ['face' => $m['face'], 'x' => $m['x'] + 16, 'y' => 536, 'd' => 64, 'id' => 'streak-more-'.$mi, 'fUnit' => 'streak-more-face-'.$mi, 'fRing' => '#F7931A'])
<text data-unit="streak-more-name-{{ $mi }}" data-box="{{ $m['x'] + 95 }} 542 {{ $m['x'] + 408 }} 572" x="{{ $m['x'] + 96 }}" y="566" font-family="{{ $m['name']['font'] }}" font-weight="800" font-size="24" fill="#FFFFFF">{{ $m['name']['text'] }}</text>
<text x="{{ $m['x'] + 96 }}" y="598" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $m['wins'] }}</text>
@endforeach
<text x="40" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">Three wins in a row put you here.</text>
@else
<text x="40" y="300" font-family="Unbounded" font-weight="800" font-size="60" fill="#17120A">No streak running.</text>
<text x="40" y="370" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#17120A">Win three games in a row and this screen is yours.</text>
@endif
<text x="1240" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 92, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
