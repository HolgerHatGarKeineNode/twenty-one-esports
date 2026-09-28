{{--
    E3 · Broadcast desk · pride: just signed up. Channel frame; the six newest sign-ups of tournaments open for sign-up
    in two columns: face, name, the tournament, and its pot as an orange chip when it has one. Without a sign-up: the
    first spot is open.

    Data contract:
      $pride     array{signups: list<array{name: string, avatar: ?string, tournament: string, pot: ?int}>, …} (PrideSlides::all())
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $rows = [];
    foreach (array_slice(array_values(array_filter($pride['signups'] ?? [], 'is_array')), 0, 6) as $i => $s) {
        $x = 40 + intdiv($i, 3) * 608;
        $y = 240 + ($i % 3) * 132;
        $pot = is_int($s['pot'] ?? null) && $s['pot'] > 0 ? K::sats($s['pot']).' sats' : null;
        $potW = $pot ? K::width($pot, K::MONO, 20) + 24 : 0;
        $rows[] = [
            'x' => $x, 'y' => $y, 'i' => $i, 'avatar' => $s['avatar'] ?? null, 'pot' => $pot, 'potW' => $potW,
            'name' => K::name($s['name'] ?? '', 'Player', 30, 460),
            'tournament' => K::fit($s['tournament'] ?? '', K::MONO, 20, 460 - ($pot ? $potW + 12 : 0)),
        ];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'just signed up'])
<text x="40" y="160" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Just signed up</text>
@if ($rows !== [])
<text x="40" y="206" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">They are in. Who's next?</text>
@foreach ($rows as $r)
<rect x="{{ $r['x'] }}" y="{{ $r['y'] }}" width="592" height="116" fill="#121215" fill-opacity="0.94"/>
@include('stream.rotation.partials.face', ['face' => ['uri' => $r['avatar'], 'tag' => null], 'x' => $r['x'] + 20, 'y' => $r['y'] + 18, 'd' => 80, 'id' => 'signup-'.$r['i'], 'fUnit' => 'signup-face-'.$r['i'], 'fRing' => '#F7931A'])
<text data-unit="signup-name-{{ $r['i'] }}" data-box="{{ $r['x'] + 119 }} {{ $r['y'] + 20 }} {{ $r['x'] + 580 }} {{ $r['y'] + 58 }}" x="{{ $r['x'] + 120 }}" y="{{ $r['y'] + 52 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="30" fill="#FFFFFF">{{ $r['name']['text'] }}</text>
<text data-unit="signup-tournament-{{ $r['i'] }}" x="{{ $r['x'] + 120 }}" y="{{ $r['y'] + 92 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">{{ $r['tournament'] }}</text>
@if ($r['pot'])
<rect x="{{ $r['x'] + 580 - $r['potW'] }}" y="{{ $r['y'] + 70 }}" width="{{ $r['potW'] }}" height="32" fill="#F7931A"/>
<text x="{{ $r['x'] + 580 - $r['potW'] + 12 }}" y="{{ $r['y'] + 93 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">{{ $r['pot'] }}</text>
@endif
@endforeach
@else
<text x="40" y="300" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#FFFFFF">No sign-ups yet.</text>
<text x="40" y="346" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">Sign up for a tournament and your name is on this screen.</text>
@endif
</svg>
