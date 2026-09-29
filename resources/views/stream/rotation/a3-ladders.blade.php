{{--
    A3 · Arena · ladders of every game. Split field: one ladder on the dark half, the next on the orange half, taking
    turns every five minutes through every game and mode with a result (RotationKit::ladderPair over StreamStats'
    `boards`: chess, the board games that are on, Rocket League, EA Sports FC; the season ladder once it has rows,
    else the casual one). The top two of each as a big face with the rank on its corner (the leader crowned; a clan
    lineup shows its logo), then name, Elo and record. Without any ladder the halves are chess blitz and daily with an
    empty seat; a lone ladder gets an invitation beside it. The scene's backdrop lies full-bleed under both halves.

    Data contract:
      $stats     array{boards?: list<Board>, …} (StreamStats::all())
                 Board = array{gameName: string, modeName?: string, pool?: 'rated'|'casual', rows: list<Row>}
                 Row = array{name: string, elo: int, games?: int, wins?: int, draws?: int, losses?: int, avatar?: ?string, tag?: ?string}
                 A row without a name or an Elo is skipped; missing games/w/d/l shorten the detail line.
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $pair = K::ladderPair($stats ?? [], intdiv(now()->getTimestamp(), 300));
    $halves = [];
    foreach ([[0, '#FFFFFF', '#ADADB0'], [640, '#17120A', '#17120A']] as $hi => [$ox, $ink, $ink2]) {
        $board = $pair[$hi] ?? null;
        $rows = [];
        foreach (array_slice($board['rows'] ?? [], 0, 2) as $i => $row) {
            $y = 268 + $i * 180;
            $detail = array_filter([
                K::plural($row['games'], 'game', 'games'),
                K::record($row) !== null ? 'W/D/L '.K::record($row) : null,
            ]);
            $rows[] = [
                'rank' => $i + 1, 'y' => $y, 'face' => ['uri' => $row['avatar'], 'tag' => $row['tag'] === null ? null : K::clanTag($row['tag'], $row['name']), 'fit' => $row['tag'] === null ? 'slice' : 'meet'],
                'name' => K::name($row['name'], 'Player', 34, 408),
                'elo' => $row['elo'].' Elo',
                'detail' => K::fit(implode(', ', $detail), K::MONO, 18, 408),
            ];
        }
        $title = $board === null ? ($pair === [] ? 'Chess' : 'Every game') : $board['gameName'];
        $sub = $board === null ? ($pair === [] ? ($hi === 0 ? 'Blitz 5+3' : 'Daily') : 'has a ladder') : trim($board['modeName'].($board['rated'] ? ', season ladder' : ', casual ladder'), ', ');
        $halves[] = ['ox' => $ox, 'title' => K::fit($title, K::DISPLAY, 44, 560), 'sub' => K::fit($sub, K::MONO, 22, 560), 'ink' => $ink, 'ink2' => $ink2, 'rows' => $rows, 'daily' => $hi === 1, 'lone' => $board === null && $pair !== []];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.72])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
@foreach ($halves as $h)
@php($x = $h['ox'] + 40)
<text data-unit="title-{{ $h['ox'] }}" data-box="{{ $x - 1 }} 136 {{ $x + 562 }} 190" x="{{ $x }}" y="180" font-family="Unbounded" font-weight="800" font-size="44" fill="{{ $h['ink'] }}">{{ $h['title'] }}</text>
<text data-unit="sub-{{ $h['ox'] }}" data-box="{{ $x - 1 }} 198 {{ $x + 562 }} 224" x="{{ $x }}" y="218" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $h['ink2'] }}">{{ $h['sub'] }}</text>
@forelse ($h['rows'] as $r)
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => $x, 'y' => $r['y'], 'd' => 128, 'id' => $h['ox'].'-'.$r['rank'],
    'fUnit' => 'face-'.$h['ox'].'-'.$r['rank'], 'fRing' => $h['daily'] ? '#17120A' : '#F7931A', 'fRank' => $r['rank'], 'fGround' => '#17120A',
    'fRankFill' => $h['daily'] ? '#17120A' : ($r['rank'] === 1 ? '#F7931A' : '#ADADB0'), 'fRankInk' => $h['daily'] ? '#F7931A' : '#17120A', 'fRankRim' => $h['daily'] ? '#F7931A' : '#0A0A0B',
    'fCrown' => $r['rank'] === 1 ? ($h['daily'] ? '#17120A' : '#F7931A') : null])
<text data-unit="name-{{ $h['ox'] }}-{{ $r['rank'] }}" data-box="{{ $x + 151 }} {{ $r['y'] + 4 }} {{ $x + 560 }} {{ $r['y'] + 54 }}" x="{{ $x + 152 }}" y="{{ $r['y'] + 44 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="34" fill="{{ $h['ink'] }}">{{ $r['name']['text'] }}</text>
<text data-unit="elo-{{ $h['ox'] }}-{{ $r['rank'] }}" x="{{ $x + 152 }}" y="{{ $r['y'] + 82 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $h['daily'] ? '#17120A' : '#F7931A' }}">{{ $r['elo'] }}</text>
@if ($r['detail'] !== '')<text data-unit="detail-{{ $h['ox'] }}-{{ $r['rank'] }}" data-box="{{ $x + 151 }} {{ $r['y'] + 92 }} {{ $x + 560 }} {{ $r['y'] + 116 }}" x="{{ $x + 152 }}" y="{{ $r['y'] + 110 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $h['ink2'] }}">{{ $r['detail'] }}</text>@endif
@empty
@include('stream.rotation.partials.face', ['face' => null, 'x' => $x, 'y' => 268, 'd' => 128, 'id' => 'empty-'.$h['ox'], 'fUnit' => 'empty-seat-'.$h['ox'], 'fOpen' => 'invite', 'fOpenInk' => $h['daily'] ? '#17120A' : '#ADADB0'])
<text data-unit="empty-{{ $h['ox'] }}" x="{{ $x + 152 }}" y="324" font-family="Unbounded" font-weight="800" font-size="30" fill="{{ $h['ink'] }}">{{ $h['lone'] ? 'Pick yours.' : 'No games yet.' }}</text>
<text x="{{ $x + 152 }}" y="360" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $h['ink2'] }}">{{ $h['lone'] ? 'Play one and take the top spot.' : 'Take the top spot.' }}</text>
@endforelse
@endforeach
<text x="680" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
