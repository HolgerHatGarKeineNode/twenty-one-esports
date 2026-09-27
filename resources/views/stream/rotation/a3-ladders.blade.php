{{--
    A3 · Arena · casual ladders. Split field: Blitz 5+3 on the dark half, Daily on the orange half, the top two of
    each as a big face with the rank on its corner (the leader crowned), then name, Elo and record. A ladder without
    rows shows an empty seat and says so. The scene's backdrop lies full-bleed under both halves.

    Data contract:
      $stats     array{ladders?: array{blitz?: list<Row>, daily?: list<Row>}, …}
                 Row = array{name: string, elo: int, games?: int, w?: int, d?: int, l?: int, avatar?: ?string}
                 A row without a name or an Elo is skipped; missing games/w/d/l shorten the detail line.
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $halves = [];
    foreach ([['blitz', 0, 'Blitz 5+3', '#FFFFFF', '#ADADB0'], ['daily', 640, 'Daily', '#17120A', '#17120A']] as [$key, $ox, $title, $ink, $ink2]) {
        $rows = [];
        $faces = K::ladderAvatars($stats ?? [], $key, 2);
        foreach (K::ladder($stats ?? [], $key, 2) as $i => $row) {
            $y = 268 + $i * 180;
            $detail = array_filter([
                K::plural($row['games'], 'game', 'games'),
                K::record($row) !== null ? 'W/D/L '.K::record($row) : null,
            ]);
            $rows[] = [
                'rank' => $i + 1, 'y' => $y, 'avatar' => $faces[$i] ?? null,
                'name' => K::name($row['name'], 'Player', 34, 408),
                'elo' => $row['elo'].' Elo',
                'detail' => K::fit(implode(', ', $detail), K::MONO, 18, 408),
            ];
        }
        $halves[] = ['ox' => $ox, 'title' => $title, 'ink' => $ink, 'ink2' => $ink2, 'rows' => $rows, 'daily' => $key === 'daily'];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.72])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
@foreach ($halves as $h)
@php($x = $h['ox'] + 40)
<text x="{{ $x }}" y="200" font-family="Unbounded" font-weight="800" font-size="44" fill="{{ $h['ink'] }}">{{ $h['title'] }}</text>
@forelse ($h['rows'] as $r)
@include('stream.rotation.partials.face', ['face' => ['uri' => $r['avatar'], 'tag' => null], 'x' => $x, 'y' => $r['y'], 'd' => 128, 'id' => $h['ox'].'-'.$r['rank'],
    'fUnit' => 'face-'.$h['ox'].'-'.$r['rank'], 'fRing' => $h['daily'] ? '#17120A' : '#F7931A', 'fRank' => $r['rank'],
    'fRankFill' => $h['daily'] ? '#17120A' : ($r['rank'] === 1 ? '#F7931A' : '#ADADB0'), 'fRankInk' => $h['daily'] ? '#F7931A' : '#17120A', 'fRankRim' => $h['daily'] ? '#F7931A' : '#0A0A0B',
    'fCrown' => $r['rank'] === 1 ? ($h['daily'] ? '#17120A' : '#F7931A') : null])
<text data-unit="name-{{ $h['ox'] }}-{{ $r['rank'] }}" data-box="{{ $x + 151 }} {{ $r['y'] + 4 }} {{ $x + 560 }} {{ $r['y'] + 54 }}" x="{{ $x + 152 }}" y="{{ $r['y'] + 44 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="34" fill="{{ $h['ink'] }}">{{ $r['name']['text'] }}</text>
<text data-unit="elo-{{ $h['ox'] }}-{{ $r['rank'] }}" x="{{ $x + 152 }}" y="{{ $r['y'] + 82 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $h['daily'] ? '#17120A' : '#F7931A' }}">{{ $r['elo'] }}</text>
@if ($r['detail'] !== '')<text data-unit="detail-{{ $h['ox'] }}-{{ $r['rank'] }}" data-box="{{ $x + 151 }} {{ $r['y'] + 92 }} {{ $x + 560 }} {{ $r['y'] + 116 }}" x="{{ $x + 152 }}" y="{{ $r['y'] + 110 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $h['ink2'] }}">{{ $r['detail'] }}</text>@endif
@empty
@include('stream.rotation.partials.face', ['face' => null, 'x' => $x, 'y' => 268, 'd' => 128, 'id' => 'empty-'.$h['ox'], 'fUnit' => 'empty-seat-'.$h['ox'], 'fOpen' => 'invite', 'fOpenInk' => $h['daily'] ? '#17120A' : '#ADADB0'])
<text data-unit="empty-{{ $h['ox'] }}" x="{{ $x + 152 }}" y="324" font-family="Unbounded" font-weight="800" font-size="30" fill="{{ $h['ink'] }}">No games yet.</text>
<text x="{{ $x + 152 }}" y="360" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $h['ink2'] }}">Take the top spot.</text>
@endforelse
@endforeach
<text x="680" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
