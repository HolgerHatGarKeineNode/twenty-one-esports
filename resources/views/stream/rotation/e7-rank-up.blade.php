{{--
    E7 · Broadcast desk · pride: rank up. Channel frame over the brand backdrop; the newest rank-up of the week big: the
    player's face ringed in the new tier's colour, the name, the new tier in its colour (RankTiers::colour, the site's
    rank colours), where they came from, on which ladder and at what Elo; the week's other rank-ups (up to two) small
    below. Without a rank-up this week: the ladder of tiers as the goal.

    Data contract:
      $pride     array{rankUps: list<array{name: string, avatar: ?string, tier: string, colour: string, previous: ?string,
                 ladder: string, rating: int}>, …} (PrideSlides::all())
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $ups = K::prideRows($pride['rankUps'] ?? [], 3);
    $hero = $ups[0] ?? null;
    if ($hero) {
        $ink = K::colour($hero['colour'] ?? null, '#F7931A');
        $name = K::name($hero['name'], 'Player', 60, 740);
        $tier = K::fit(K::clean($hero['tier'] ?? ''), K::DISPLAY, 60, 740);
        $from = K::clean(is_string($hero['previous'] ?? null) ? $hero['previous'] : '');
        $detail = K::fit(implode(', ', array_filter([$from !== '' ? 'up from '.$from : 'first rank revealed', K::clean($hero['ladder'] ?? ''), is_int($hero['rating'] ?? null) ? $hero['rating'].' Elo' : null])), K::MONO, 24, 740);
    }
    $more = [];
    foreach (array_slice($ups, 1, 2) as $i => $up) {
        $more[] = ['x' => 460 + $i * 400, 'face' => K::prideFace($up), 'ink' => K::colour($up['colour'] ?? null, '#F7931A'),
            'name' => K::name($up['name'], 'Player', 22, 300), 'tier' => K::fit(K::clean($up['tier'] ?? ''), K::MONO, 18, 300)];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => 'rank up'])
@if ($hero)
<radialGradient id="rank-glow"><stop offset="0.5" stop-color="{{ $ink }}" stop-opacity="0.45"/><stop offset="1" stop-color="{{ $ink }}" stop-opacity="0"/></radialGradient>
<circle cx="230" cy="360" r="230" fill="url(#rank-glow)"/>
@include('stream.rotation.partials.face', ['face' => K::prideFace($hero), 'x' => 80, 'y' => 210, 'd' => 300, 'id' => 'rank-up', 'fUnit' => 'rank-face', 'fRing' => $ink])
{{-- Three rising chevrons in the tier's colour: the step up. --}}
@foreach ([0, 1, 2] as $ci)<path d="M196 {{ 596 - $ci * 22 }} L230 {{ 574 - $ci * 22 }} L264 {{ 596 - $ci * 22 }}" fill="none" stroke="{{ $ink }}" stroke-width="8" stroke-linecap="round" stroke-linejoin="round" stroke-opacity="{{ 0.45 + $ci * 0.27 }}"/>@endforeach
<text x="460" y="200" font-family="JetBrains Mono" font-weight="700" font-size="26" fill="#F7931A">Rank up</text>
<text data-unit="rank-name" data-box="459 226 1240 292" x="460" y="280" font-family="{{ $name['font'] }}" font-weight="800" font-size="60" fill="#FFFFFF">{{ $name['text'] }}</text>
<text data-unit="rank-tier" data-box="459 312 1240 378" x="460" y="366" font-family="Unbounded" font-weight="800" font-size="60" fill="{{ $ink }}">{{ $tier }}</text>
<text data-unit="rank-detail" data-box="459 394 1240 424" x="460" y="418" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">{{ $detail }}</text>
@foreach ($more as $mi => $m)
@include('stream.rotation.partials.face', ['face' => $m['face'], 'x' => $m['x'], 'y' => 480, 'd' => 64, 'id' => 'rank-more-'.$mi, 'fUnit' => 'rank-more-face-'.$mi, 'fRing' => $m['ink']])
<text data-unit="rank-more-name-{{ $mi }}" data-box="{{ $m['x'] + 79 }} 486 {{ $m['x'] + 384 }} 514" x="{{ $m['x'] + 80 }}" y="508" font-family="{{ $m['name']['font'] }}" font-weight="800" font-size="22" fill="#FFFFFF">{{ $m['name']['text'] }}</text>
<text data-unit="rank-more-tier-{{ $mi }}" data-box="{{ $m['x'] + 79 }} 520 {{ $m['x'] + 384 }} 542" x="{{ $m['x'] + 80 }}" y="536" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $m['ink'] }}">{{ $m['tier'] }}</text>
@endforeach
<text x="460" y="610" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Win rated games and your rank climbs too.</text>
@else
<text x="40" y="250" font-family="Unbounded" font-weight="800" font-size="52" fill="#FFFFFF">No rank-up this week.</text>
<text x="40" y="310" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">Win rated games and climb from Bronze to Grand Champion.</text>
@foreach (['bronze-1', 'silver-1', 'gold-1', 'platinum-1', 'diamond-1', 'champion-1', 'grand-champion-1'] as $ti => $token)
@php($tx = 40 + $ti * 172)
<rect x="{{ $tx }}" y="{{ 520 - $ti * 24 }}" width="156" height="{{ 64 + $ti * 24 }}" fill="{{ \App\Support\Rating\RankTiers::colour($token) }}"/>
<text x="{{ $tx + 12 }}" y="572" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#17120A">{{ ['Bronze', 'Silver', 'Gold', 'Platinum', 'Diamond', 'Champion', 'Grand Champion'][$ti] }}</text>
@endforeach
@endif
</svg>
