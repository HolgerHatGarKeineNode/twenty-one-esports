{{--
    E6 · Arena · pride: the strongest across every game (the cross-game Strongest list, P40). Split field: on the
    dark half the number one, big and crowned, with the Global Rating and the games it came from; on the orange half
    places two to five, each with face, name, Global Rating and games. Without a live season, or before anybody has
    a Global Rating, the halves invite instead.

    Data contract:
      $pride     array{strongest: array{season: string, ranked: int, rows: list<array{place: int, name: string,
                 avatar: ?string, rating: int, games: list<string>, clan: ?string}>}|null, …} (PrideSlides::all())
      $backdrop  ?string, optional: the brand backdrop
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $list = is_array($pride['strongest'] ?? null) ? $pride['strongest'] : null;
    $rows = K::prideRows($list['rows'] ?? [], 5);
    $top = $rows[0] ?? null;
    if ($top) {
        $topName = K::name($top['name'], 'Player', 44, 560);
        $topRating = is_int($top['rating'] ?? null) ? (string) $top['rating'] : '';
        $topGames = K::fit(K::listing($top['games'] ?? [], 3), K::MONO, 20, 560);
    }
    $rest = [];
    foreach (array_slice($rows, 1, 4) as $i => $row) {
        $rest[] = [
            'y' => 150 + $i * 116, 'place' => $i + 2, 'face' => K::prideFace($row),
            'name' => K::name($row['name'], 'Player', 28, 440),
            'line' => K::fit(implode(', ', array_filter([is_int($row['rating'] ?? null) ? $row['rating'].' Global Rating' : null, K::listing($row['games'] ?? [], 2)])), K::MONO, 18, 440),
        ];
    }
    $season = K::clean($list['season'] ?? '');
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
<text x="40" y="120" font-family="Unbounded" font-weight="800" font-size="44" fill="#FFFFFF">Strongest</text>
<text data-unit="season" data-box="39 136 620 162" x="40" y="156" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ K::fit($season !== '' ? 'Across every game, '.$season : 'Across every game', K::MONO, 22, 580) }}</text>
@if ($top)
<radialGradient id="strong-glow"><stop offset="0.5" stop-color="#F7931A" stop-opacity="0.45"/><stop offset="1" stop-color="#F7931A" stop-opacity="0"/></radialGradient>
<circle cx="130" cy="316" r="140" fill="url(#strong-glow)"/>
@include('stream.rotation.partials.face', ['face' => K::prideFace($top), 'x' => 40, 'y' => 226, 'd' => 180, 'id' => 'strongest', 'fUnit' => 'strongest-face', 'fRing' => '#F7931A', 'fCrown' => '#F7931A',
    'fRank' => 1, 'fRankFill' => '#F7931A', 'fRankInk' => '#17120A', 'fRankRim' => '#0A0A0B'])
<text data-unit="strongest-name" data-box="39 432 620 486" x="40" y="474" font-family="{{ $topName['font'] }}" font-weight="800" font-size="44" fill="#FFFFFF">{{ $topName['text'] }}</text>
@if ($topRating !== '')
<text data-unit="strongest-rating" data-box="38 496 620 574" x="40" y="564" font-family="Unbounded" font-weight="800" font-size="72" fill="#F7931A">{{ $topRating }}</text>
<text x="{{ 64 + K::width($topRating, K::DISPLAY, 72) * 1.04 }}" y="564" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">Global Rating</text>
@endif
@if ($topGames !== '')<text data-unit="strongest-games" data-box="39 592 620 618" x="40" y="612" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $topGames }}</text>@endif
@foreach ($rest as $r)
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => 680, 'y' => $r['y'], 'd' => 88, 'id' => 'place-'.$r['place'], 'fUnit' => 'place-face-'.$r['place'], 'fRing' => '#17120A',
    'fRank' => $r['place'], 'fRankFill' => '#17120A', 'fRankInk' => '#F7931A', 'fRankRim' => '#F7931A'])
<text data-unit="place-name-{{ $r['place'] }}" data-box="791 {{ $r['y'] + 14 }} 1240 {{ $r['y'] + 50 }}" x="792" y="{{ $r['y'] + 44 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="28" fill="#17120A">{{ $r['name']['text'] }}</text>
<text data-unit="place-line-{{ $r['place'] }}" data-box="791 {{ $r['y'] + 58 }} 1240 {{ $r['y'] + 82 }}" x="792" y="{{ $r['y'] + 76 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">{{ $r['line'] }}</text>
@endforeach
@if ($rest === [])
<text x="680" y="200" font-family="Unbounded" font-weight="800" font-size="30" fill="#17120A">Place two is open.</text>
<text x="680" y="240" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">Play rated in any game to get on the list.</text>
@endif
@else
<text x="40" y="300" font-family="Unbounded" font-weight="800" font-size="36" fill="#FFFFFF">{{ $list === null ? 'Opens with the season.' : 'Nobody ranked yet.' }}</text>
<text x="40" y="350" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">One list for every game the league plays.</text>
<text x="680" y="300" font-family="Unbounded" font-weight="800" font-size="36" fill="#17120A">Be the first.</text>
<text x="680" y="350" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">Play rated games in any game</text>
<text x="680" y="382" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">to earn your Global Rating.</text>
@endif
<text x="680" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
