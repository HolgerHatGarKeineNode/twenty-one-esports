{{--
    C2 · Terminal ticker · gallery. The live games as a table over the scene's backdrop: mini board, both players with
    their faces (the side to move in orange, red below 10 s), mode, the clock of the side to move, move number. Rows
    shrink from 2 to 6 games; below the table one line invites the next player, or counts the games not shown.

    Data contract:
      $games  list<Game> (2..6 shown), Game as in a1-match
      $more   int, optional: live games not in $games
      $stats     array for the stats bar (see c1-match)
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $list = array_slice(array_values($games ?? []), 0, 6);
    $hidden = max(0, count($games ?? []) - 6) + max(0, (int) ($more ?? 0));
    $total = count($list) + $hidden;
    $n = max(1, count($list));
    $rowH = min(124, floor(420 / $n));
    $bs = floor(($rowH - 12) / 8) * 8;
    $fs = $n >= 5 ? 18 : 22;
    $fd = $n >= 5 ? 24 : ($n === 4 ? 30 : 40);
    $nx = 168 + $fd + 10;
    $rows = [];
    foreach ($list as $i => $g) {
        $y = 146 + $i * $rowH;
        $whiteMoves = (bool) ($g['white']['toMove'] ?? false);
        $blackMoves = (bool) ($g['black']['toMove'] ?? false);
        $mover = $whiteMoves ? ($g['white'] ?? []) : ($blackMoves ? ($g['black'] ?? []) : null);
        $ms = (int) ($mover['clockMs'] ?? 0);
        $ink = $ms < 10000 ? '#F87171' : '#F7931A';
        $mid = $y + $rowH / 2;
        $rows[] = [
            'y' => $y, 'mid' => $mid,
            'b' => K::board($g['fen'] ?? '', $g['lastMove'] ?? null, 40, $y + ($rowH - $bs) / 2, $bs / 8),
            'white' => K::fit($g['white']['name'] ?? '', K::MONO, $fs, 446 - $fd - 10) ?: 'White player',
            'black' => K::fit($g['black']['name'] ?? '', K::MONO, $fs, 446 - $fd - 10) ?: 'Black player',
            'whiteFace' => $g['white']['avatar'] ?? null, 'blackFace' => $g['black']['avatar'] ?? null,
            'whiteInk' => $whiteMoves ? $ink : '#FFFFFF', 'blackInk' => $blackMoves ? $ink : '#FFFFFF',
            'mode' => K::fit(K::modeLabel($g), K::MONO, $fs, 304),
            'modeInk' => ($g['result'] ?? null) ? '#F7931A' : '#ADADB0',
            'clock' => $mover ? K::clockText($ms) : '-', 'clockInk' => $mover ? $ink : '#ADADB0',
            'move' => (string) max(1, (int) (explode(' ', trim((string) ($g['fen'] ?? '')))[5] ?? 1)),
        ];
    }
    $endY = 146 + $n * $rowH;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.76])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => K::plural($total, 'live game', 'live games')])
<text x="638" y="132" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">mode</text>
<text x="966" y="132" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">clock</text>
<text x="1160" y="132" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#A1A1A7">move</text>
<rect x="40" y="142" width="1200" height="1" fill="#2A2A30"/>
@foreach ($rows as $i => $r)
@include('stream.rotation.partials.board', ['b' => $r['b'], 'frame' => 0])
@include('stream.rotation.partials.face', ['face' => ['uri' => $r['whiteFace'], 'tag' => null], 'x' => 168, 'y' => $r['mid'] - 3 - $fd, 'd' => $fd, 'id' => 'w'.$i, 'fUnit' => 'white-face-'.$i, 'fShape' => 'square', 'fRing' => $r['whiteInk'] === '#FFFFFF' ? '#6B6B72' : $r['whiteInk']])
@include('stream.rotation.partials.face', ['face' => ['uri' => $r['blackFace'], 'tag' => null], 'x' => 168, 'y' => $r['mid'] + 3, 'd' => $fd, 'id' => 'b'.$i, 'fUnit' => 'black-face-'.$i, 'fShape' => 'square', 'fRing' => $r['blackInk'] === '#FFFFFF' ? '#6B6B72' : $r['blackInk']])
<text data-unit="white-{{ $i }}" data-box="{{ $nx - 1 }} {{ $r['mid'] - 5 - $fd }} 614 {{ $r['mid'] - 1 }}" x="{{ $nx }}" y="{{ round($r['mid'] - 3 - $fd / 2 + $fs * 0.36, 1) }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $fs }}" fill="{{ $r['whiteInk'] }}">{{ $r['white'] }}</text>
<text data-unit="black-{{ $i }}" data-box="{{ $nx - 1 }} {{ $r['mid'] + 1 }} 614 {{ $r['mid'] + 5 + $fd }}" x="{{ $nx }}" y="{{ round($r['mid'] + 3 + $fd / 2 + $fs * 0.36, 1) }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $fs }}" fill="{{ $r['blackInk'] }}">{{ $r['black'] }}</text>
<text data-unit="mode-{{ $i }}" data-box="637 {{ $r['mid'] - $fs }} 942 {{ $r['mid'] + $fs * 0.35 + 8 }}" x="638" y="{{ $r['mid'] + $fs * 0.35 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $fs }}" fill="{{ $r['modeInk'] }}">{{ $r['mode'] }}</text>
<text data-unit="clock-{{ $i }}" x="966" y="{{ $r['mid'] + 10 }}" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="{{ $r['clockInk'] }}">{{ $r['clock'] }}</text>
<text data-unit="move-{{ $i }}" x="1160" y="{{ $r['mid'] + 8 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $r['move'] }}</text>
<rect x="40" y="{{ $r['y'] + $rowH - 1 }}" width="1200" height="1" fill="#2A2A30"/>
@endforeach
@if ($endY <= 572)
@if ($hidden > 0)
<text data-unit="more" x="168" y="{{ $endY + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">+{{ $hidden }} more live on esports.einundzwanzig.space</text>
@else
<text x="168" y="{{ $endY + 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">Your game next: log in and press Find opponent.</text>
@endif
@endif
</svg>
