{{--
    E2 · Broadcast desk · pride: the climbers of the week. Channel frame; up to three players with the biggest Elo
    gains of the last seven days over every ladder of every game, each a card with face (the first crowned), name,
    the gain, the results it took and the games it came from. Without a gain this week: the spot is open.

    Data contract:
      $pride     array{climbers: list<array{name: string, avatar: ?string, gain: int, games: int, from?: list<string>}>, …} (PrideSlides::all())
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $climbers = array_slice(array_values(array_filter($pride['climbers'] ?? [], 'is_array')), 0, 3);
    $cards = [];
    foreach ($climbers as $i => $c) {
        $x = 40 + $i * 408;
        $cards[] = [
            'x' => $x, 'rank' => $i + 1, 'avatar' => $c['avatar'] ?? null,
            'name' => K::name($c['name'] ?? '', 'Player', 32, 336),
            'gain' => '+'.(int) ($c['gain'] ?? 0).' Elo',
            // Two games by name, more as a count: a list is never cut off.
            'games' => K::fit(implode(' in ', array_filter([K::plural((int) ($c['games'] ?? 0), 'result', 'results'),
                count(array_unique(array_filter(is_array($c['from'] ?? null) ? $c['from'] : [], 'is_string'))) > 2 ? count(array_unique(array_filter($c['from'], 'is_string'))).' games' : K::listing($c['from'] ?? [], 2)])), K::MONO, 18, 354),
        ];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'climbers of the week'])
<text x="40" y="160" font-family="Unbounded" font-weight="800" font-size="48" fill="#FFFFFF">Climbers of the week</text>
@if ($cards !== [])
<text x="40" y="206" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Most Elo won in the last 7 days, every game counts.</text>
@foreach ($cards as $c)
<rect x="{{ $c['x'] }}" y="240" width="384" height="384" fill="#121215" fill-opacity="0.94"/>
@if ($c['rank'] === 1)<rect x="{{ $c['x'] }}" y="240" width="384" height="6" fill="#F7931A"/>@endif
@include('stream.rotation.partials.face', ['face' => ['uri' => $c['avatar'], 'tag' => null], 'x' => $c['x'] + 112, 'y' => 290, 'd' => 160, 'id' => 'climber-'.$c['rank'], 'fUnit' => 'climber-face-'.$c['rank'],
    'fRing' => '#F7931A', 'fRank' => $c['rank'], 'fRankFill' => $c['rank'] === 1 ? '#F7931A' : '#ADADB0', 'fRankInk' => '#17120A', 'fRankRim' => '#121215', 'fCrown' => $c['rank'] === 1 ? '#F7931A' : null])
<text data-unit="climber-name-{{ $c['rank'] }}" data-box="{{ $c['x'] + 23 }} 486 {{ $c['x'] + 362 }} 526" x="{{ $c['x'] + 192 }}" y="516" font-family="{{ $c['name']['font'] }}" font-weight="800" font-size="32" fill="#FFFFFF" text-anchor="middle">{{ $c['name']['text'] }}</text>
<text data-unit="climber-gain-{{ $c['rank'] }}" x="{{ $c['x'] + 192 }}" y="570" font-family="Unbounded" font-weight="800" font-size="36" fill="#F7931A" text-anchor="middle">{{ $c['gain'] }}</text>
@if ($c['games'])<text data-unit="climber-from-{{ $c['rank'] }}" data-box="{{ $c['x'] + 15 }} 588 {{ $c['x'] + 370 }} 610" x="{{ $c['x'] + 192 }}" y="604" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0" text-anchor="middle">{{ $c['games'] }}</text>@endif
@endforeach
@else
<text x="40" y="300" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#FFFFFF">Nobody has climbed this week yet.</text>
<text x="40" y="346" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">Win this week in any game and this spot is yours.</text>
@endif
</svg>
