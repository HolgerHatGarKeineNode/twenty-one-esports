{{--
    C3 · Terminal ticker · casual ladders. Two tables side by side (Blitz 5+3, Daily) over the backdrop: rank, face
    and player, Elo, games, W/D/L, up to four rows each; the leader in orange and crowned. A missing value leaves its
    cell empty; an empty ladder says so.

    Data contract:
      $stats     array{ladders?: array{blitz?: list<Row>, daily?: list<Row>}, …} (Row as in a3-ladders) + the stats bar counts
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $tables = [];
    foreach ([['blitz', 40, 'Blitz 5+3'], ['daily', 660, 'Daily']] as [$key, $x, $title]) {
        $rows = [];
        $faces = K::ladderAvatars($stats ?? [], $key, 4);
        foreach (K::ladder($stats ?? [], $key, 4) as $i => $r) {
            $rec = K::record($r) ?? '';
            $rows[] = [
                'y' => 208 + $i * 58, 'lead' => $i === 0,
                'name' => K::fit($r['name'], K::MONO, 22, 190), 'avatar' => $faces[$i] ?? null,
                'elo' => (string) $r['elo'], 'games' => $r['games'] === null ? '' : (string) $r['games'],
                'rec' => $rec, 'recSize' => mb_strlen($rec) <= 10 ? 22 : 16,
            ];
        }
        $tables[] = ['x' => $x, 'title' => $title, 'rows' => $rows];
    }
    // Column offsets inside a 580 px table: # 0, player 40, Elo 284, games 364, W/D/L 446.
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'casual ladders'])
@foreach ($tables as $t)
@php($x = $t['x'])
<text x="{{ $x }}" y="132" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#FFFFFF">{{ $t['title'] }}</text>
<text x="{{ $x }}" y="164" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">#</text>
<text x="{{ $x + 40 }}" y="164" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">player</text>
<text x="{{ $x + 284 }}" y="164" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">Elo</text>
<text x="{{ $x + 364 }}" y="164" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">games</text>
<text x="{{ $x + 446 }}" y="164" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">W/D/L</text>
<rect x="{{ $x }}" y="172" width="580" height="1" fill="#2A2A30"/>
@forelse ($t['rows'] as $ri => $r)
@php($ink = $r['lead'] ? '#F7931A' : '#FFFFFF')
<text x="{{ $x }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}">{{ $ri + 1 }}</text>
@include('stream.rotation.partials.face', ['face' => ['uri' => $r['avatar'], 'tag' => null], 'x' => $x + 36, 'y' => $r['y'] - 26, 'd' => 34, 'id' => $x.'-'.$ri,
    'fUnit' => 'face-'.$x.'-'.$ri, 'fShape' => 'square', 'fRing' => $r['lead'] ? '#F7931A' : '#6B6B72', 'fCrown' => $r['lead'] ? '#F7931A' : null])
<text data-unit="name-{{ $x }}-{{ $ri }}" data-box="{{ $x + 81 }} {{ $r['y'] - 22 }} {{ $x + 274 }} {{ $r['y'] + 6 }}" x="{{ $x + 82 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}">{{ $r['name'] }}</text>
<text data-unit="elo-{{ $x }}-{{ $ri }}" data-box="{{ $x + 283 }} {{ $r['y'] - 22 }} {{ $x + 356 }} {{ $r['y'] + 6 }}" x="{{ $x + 284 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $ink }}">{{ $r['elo'] }}</text>
@if ($r['games'] !== '')<text data-unit="games-{{ $x }}-{{ $ri }}" data-box="{{ $x + 363 }} {{ $r['y'] - 22 }} {{ $x + 438 }} {{ $r['y'] + 6 }}" x="{{ $x + 364 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ strlen($r['games']) > 5 ? 16 : 22 }}" fill="{{ $ink }}">{{ $r['games'] }}</text>@endif
@if ($r['rec'] !== '')<text data-unit="rec-{{ $x }}-{{ $ri }}" data-box="{{ $x + 445 }} {{ $r['y'] - 22 }} {{ $x + 580 }} {{ $r['y'] + 6 }}" x="{{ $x + 446 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $r['recSize'] }}" fill="{{ $ink }}">{{ $r['rec'] }}</text>@endif
<rect x="{{ $x }}" y="{{ $r['y'] + 22 }}" width="580" height="1" fill="#2A2A30"/>
@empty
<text x="{{ $x }}" y="208" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">No games yet. Be the first.</text>
@endforelse
@endforeach
<text x="40" y="580" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">Your name here next.</text>
</svg>
