{{--
    A3 · Arena · casual ladders. Split field: Blitz 5+3 on the dark half, Daily on the orange half, the top two of
    each with a big rank numeral. A ladder without rows says so in plain words instead of showing empty slots.

    Data contract:
      $stats  array{ladders?: array{blitz?: list<Row>, daily?: list<Row>}, …}
              Row = array{name: string, elo: int, games?: int, w?: int, d?: int, l?: int}
              A row without a name or an Elo is skipped; missing games/w/d/l shorten the detail line.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $halves = [];
    foreach ([['blitz', 0, 'Blitz 5+3', 'Casual Elo, top two', '#FFFFFF', '#ADADB0'], ['daily', 640, 'Daily', 'Casual Elo, one move a day', '#17120A', '#17120A']] as [$key, $ox, $title, $sub, $ink, $ink2]) {
        $rows = [];
        foreach (K::ladder($stats ?? [], $key, 2) as $i => $row) {
            $y = 250 + $i * 160;
            $detail = array_filter([
                K::plural($row['games'], 'game', 'games'),
                K::record($row) !== null ? 'W/D/L '.K::record($row) : null,
            ]);
            $rows[] = [
                'rank' => $i + 1, 'y' => $y,
                'name' => K::name($row['name'], 'Player', 34, 424),
                'elo' => $row['elo'].' Elo',
                'detail' => K::fit(implode(', ', $detail), K::MONO, 18, 424),
            ];
        }
        $halves[] = ['ox' => $ox, 'title' => $title, 'sub' => $sub, 'ink' => $ink, 'ink2' => $ink2, 'rows' => $rows, 'daily' => $key === 'daily'];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
@foreach ($halves as $h)
@php($x = $h['ox'] + 40)
<text x="{{ $x }}" y="188" font-family="Unbounded" font-weight="800" font-size="44" fill="{{ $h['ink'] }}">{{ $h['title'] }}</text>
<text x="{{ $x }}" y="220" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $h['ink2'] }}">{{ $h['sub'] }}</text>
@forelse ($h['rows'] as $r)
<text data-unit="rank-{{ $h['ox'] }}-{{ $r['rank'] }}" x="{{ $x }}" y="{{ $r['y'] + 118 }}" font-family="Unbounded" font-weight="800" font-size="128" fill="{{ $h['daily'] ? '#17120A' : ($r['rank'] === 1 ? '#F7931A' : '#ADADB0') }}">{{ $r['rank'] }}</text>
<text data-unit="name-{{ $h['ox'] }}-{{ $r['rank'] }}" data-box="{{ $x + 135 }} {{ $r['y'] }} {{ $x + 560 }} {{ $r['y'] + 50 }}" x="{{ $x + 136 }}" y="{{ $r['y'] + 40 }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="34" fill="{{ $h['ink'] }}">{{ $r['name']['text'] }}</text>
<text data-unit="elo-{{ $h['ox'] }}-{{ $r['rank'] }}" x="{{ $x + 136 }}" y="{{ $r['y'] + 76 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $h['daily'] ? '#17120A' : '#F7931A' }}">{{ $r['elo'] }}</text>
@if ($r['detail'] !== '')<text data-unit="detail-{{ $h['ox'] }}-{{ $r['rank'] }}" data-box="{{ $x + 135 }} {{ $r['y'] + 86 }} {{ $x + 560 }} {{ $r['y'] + 110 }}" x="{{ $x + 136 }}" y="{{ $r['y'] + 104 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $h['ink2'] }}">{{ $r['detail'] }}</text>@endif
@empty
<text data-unit="empty-{{ $h['ox'] }}" x="{{ $x }}" y="300" font-family="Unbounded" font-weight="800" font-size="30" fill="{{ $h['ink'] }}">No games yet.</text>
<text x="{{ $x }}" y="340" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $h['ink2'] }}">Play one and take the top spot.</text>
@endforelse
@endforeach
<text x="40" y="672" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Casual Elo, just for fun. Log in and climb.</text>
<text x="680" y="672" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">esports.einundzwanzig.space</text>
</svg>
