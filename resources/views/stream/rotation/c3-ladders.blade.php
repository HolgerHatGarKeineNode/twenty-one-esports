{{--
    C3 · Terminal ticker · every ladder at a glance. Up to four tables in a 2 x 2 grid over the backdrop, one per game
    and mode with a result (RotationKit::ladderBoards over StreamStats' `boards`, taking turns every five minutes when
    there are more): its title and pool, then rank, face and player, Elo, games and W/D/L of its top three; each
    leader in orange and crowned, a clan lineup with its logo. A missing value leaves its cell empty. Without any
    ladder: one invitation instead of the grid.

    Data contract:
      $stats     array{boards?: list<Board>, …} (Board as in a3-ladders) + the stats bar counts
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $pages = array_chunk(K::ladderBoards($stats ?? []), 4);
    $page = $pages === [] ? [] : $pages[intdiv(now()->getTimestamp(), 300) % count($pages)];
    $tables = [];
    foreach ($page as $ti => $board) {
        $x = 40 + ($ti % 2) * 620;
        $y = 100 + intdiv($ti, 2) * 256;
        $rows = [];
        foreach (array_slice($board['rows'], 0, 3) as $i => $r) {
            $rec = K::record($r) ?? '';
            $rows[] = [
                'y' => $y + 104 + $i * 48, 'lead' => $i === 0,
                'name' => K::fit($r['name'], K::MONO, 20, 200),
                'face' => ['uri' => $r['avatar'], 'tag' => $r['tag'] === null ? null : K::clanTag($r['tag'], $r['name']), 'fit' => $r['tag'] === null ? 'slice' : 'meet'],
                'elo' => (string) $r['elo'], 'games' => $r['games'] === null ? '' : (string) $r['games'],
                'rec' => $rec, 'recSize' => mb_strlen($rec) <= 10 ? 20 : 14,
            ];
        }
        $pool = $board['rated'] ? 'season' : 'casual';
        $tables[] = ['x' => $x, 'y' => $y, 'i' => $ti, 'title' => K::fit($board['title'], K::MONO, 24, 560 - K::width($pool, K::MONO, 16)), 'pool' => $pool, 'rows' => $rows];
    }
    // Column offsets inside a 580 px table: # 0, face 30, player 74, Elo 290, games 368, W/D/L 450.
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.8])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'every ladder'])
@forelse ($tables as $t)
@php($x = $t['x'])
<text data-unit="title-{{ $t['i'] }}" data-box="{{ $x - 1 }} {{ $t['y'] + 12 }} {{ $x + 560 }} {{ $t['y'] + 40 }}" x="{{ $x }}" y="{{ $t['y'] + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#FFFFFF">{{ $t['title'] }}</text>
<text x="{{ $x + 580 }}" y="{{ $t['y'] + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#A1A1A7" text-anchor="end">{{ $t['pool'] }}</text>
<text x="{{ $x }}" y="{{ $t['y'] + 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">#</text>
<text x="{{ $x + 74 }}" y="{{ $t['y'] + 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">player</text>
<text x="{{ $x + 290 }}" y="{{ $t['y'] + 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">Elo</text>
<text x="{{ $x + 368 }}" y="{{ $t['y'] + 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">games</text>
<text x="{{ $x + 450 }}" y="{{ $t['y'] + 64 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">W/D/L</text>
<rect x="{{ $x }}" y="{{ $t['y'] + 72 }}" width="580" height="1" fill="#2A2A30"/>
@foreach ($t['rows'] as $ri => $r)
@php($ink = $r['lead'] ? '#F7931A' : '#FFFFFF')
<text x="{{ $x }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $ink }}">{{ $ri + 1 }}</text>
@include('stream.rotation.partials.face', ['face' => $r['face'], 'x' => $x + 30, 'y' => $r['y'] - 24, 'd' => 32, 'id' => $t['i'].'-'.$ri,
    'fUnit' => 'face-'.$t['i'].'-'.$ri, 'fShape' => 'square', 'fGround' => '#17120A', 'fRing' => $r['lead'] ? '#F7931A' : '#6B6B72', 'fCrown' => $r['lead'] ? '#F7931A' : null])
<text data-unit="name-{{ $t['i'] }}-{{ $ri }}" data-box="{{ $x + 73 }} {{ $r['y'] - 20 }} {{ $x + 282 }} {{ $r['y'] + 6 }}" x="{{ $x + 74 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $ink }}">{{ $r['name'] }}</text>
<text data-unit="elo-{{ $t['i'] }}-{{ $ri }}" data-box="{{ $x + 289 }} {{ $r['y'] - 20 }} {{ $x + 360 }} {{ $r['y'] + 6 }}" x="{{ $x + 290 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="{{ $ink }}">{{ $r['elo'] }}</text>
@if ($r['games'] !== '')<text data-unit="games-{{ $t['i'] }}-{{ $ri }}" data-box="{{ $x + 367 }} {{ $r['y'] - 20 }} {{ $x + 442 }} {{ $r['y'] + 6 }}" x="{{ $x + 368 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ strlen($r['games']) > 5 ? 14 : 20 }}" fill="{{ $ink }}">{{ $r['games'] }}</text>@endif
@if ($r['rec'] !== '')<text data-unit="rec-{{ $t['i'] }}-{{ $ri }}" data-box="{{ $x + 449 }} {{ $r['y'] - 20 }} {{ $x + 580 }} {{ $r['y'] + 6 }}" x="{{ $x + 450 }}" y="{{ $r['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $r['recSize'] }}" fill="{{ $ink }}">{{ $r['rec'] }}</text>@endif
<rect x="{{ $x }}" y="{{ $r['y'] + 16 }}" width="580" height="1" fill="#2A2A30"/>
@endforeach
@empty
<text x="40" y="220" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Every game has a ladder.</text>
<text x="40" y="276" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#ADADB0">No results yet. Play the first game and lead one.</text>
@endforelse
</svg>
