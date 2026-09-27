{{--
    B2 · Broadcast desk · gallery. Channel frame, the games as cards (board left, the two players stacked right, the
    side to move in orange). Up to 4 games in a 2x2 grid, 5-6 in 3x2; empty slots become one "This board is free"
    card (2 games: a wide one across the second row).

    Data contract:
      $games  list<Game> (2..6 shown), Game as in a1-match
      $more   int, optional: live games not in $games
      $stats  array for the ticker (see b1-match)
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $list = array_slice(array_values($games ?? []), 0, 6);
    $total = count($games ?? []) + max(0, (int) ($more ?? 0));
    $n = count($list);
    $cols = $n <= 4 ? 2 : 3;
    $cw = (1200 - ($cols - 1) * 24) / $cols;
    $ch = 264;
    $wide = $cols === 3;
    $bs = $wide ? 176 : 224;
    $nameSize = $wide ? 16 : 18;
    $cards = [];
    foreach ($list as $i => $g) {
        $x = 40 + ($i % $cols) * ($cw + 24);
        $y = 96 + intdiv($i, $cols) * ($ch + 16);
        $ix = $x + 16 + $bs + 8 + 16;
        $iw = $x + $cw - 16 - $ix;
        $players = [];
        foreach ([['black', 'Black player', $y + 48], ['white', 'White player', $y + 48 + 100]] as [$side, $fallback, $py]) {
            $p = $g[$side] ?? [];
            $ms = (int) ($p['clockMs'] ?? 0);
            $active = (bool) ($p['toMove'] ?? false);
            $players[] = [
                'y' => $py, 'fill' => $active ? ($ms < 10000 ? '#F87171' : '#F7931A') : null,
                'ink' => $active ? '#17120A' : '#FFFFFF', 'clockInk' => $active ? '#17120A' : ($ms < 10000 ? '#F87171' : '#FFFFFF'),
                'name' => K::name($p['name'] ?? '', $fallback, $nameSize, $iw - 24),
                'clock' => K::clock($ms, $ix + 12, 34, $iw - 24),
            ];
        }
        $cards[] = [
            'x' => $x, 'y' => $y, 'ix' => $ix, 'iw' => $iw,
            'b' => K::board($g['fen'] ?? '', $g['lastMove'] ?? null, $x + 20, $y + 20, $bs / 8),
            'label' => K::fit(K::modeLabel($g), K::MONO, 15, $iw), 'players' => $players,
        ];
    }
    // One free card: the next empty slot (for 2 games the whole second row).
    $free = null;
    if ($n < $cols * 2) {
        $fx = 40 + ($n % $cols) * ($cw + 24);
        $fy = 96 + intdiv($n, $cols) * ($ch + 16);
        $fw = $n === 2 ? 1200 : $cw;
        $free = ['x' => $fx, 'y' => $fy, 'w' => $fw, 'big' => $fw > 500];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => K::plural($total, 'game live', 'games live')])

@foreach ($cards as $ci => $c)
<rect x="{{ $c['x'] }}" y="{{ $c['y'] }}" width="{{ $cw }}" height="{{ $ch }}" fill="#121215"/>
@include('stream.rotation.partials.board', ['b' => $c['b'], 'frame' => 4])
<text data-unit="label-{{ $ci }}" data-box="{{ $c['ix'] - 1 }} {{ $c['y'] + 20 }} {{ $c['ix'] + $c['iw'] + 1 }} {{ $c['y'] + 42 }}" x="{{ $c['ix'] }}" y="{{ $c['y'] + 36 }}" font-family="JetBrains Mono" font-weight="700" font-size="15" fill="#ADADB0">{{ $c['label'] }}</text>
@foreach ($c['players'] as $pi => $p)
@if ($p['fill'])<rect x="{{ $c['ix'] }}" y="{{ $p['y'] }}" width="{{ $c['iw'] }}" height="92" fill="{{ $p['fill'] }}"/>@endif
<text data-unit="name-{{ $ci }}-{{ $pi }}" data-box="{{ $c['ix'] + 11 }} {{ $p['y'] + 8 }} {{ $c['ix'] + $c['iw'] - 11 }} {{ $p['y'] + 36 }}" x="{{ $c['ix'] + 12 }}" y="{{ $p['y'] + 30 }}" font-family="{{ $p['name']['font'] }}" font-weight="800" font-size="{{ $nameSize }}" fill="{{ $p['ink'] }}">{{ $p['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $p['clock'], 'y' => $p['y'] + 78, 'fill' => $p['clockInk']])
@endforeach
@endforeach

@if ($free)
<rect x="{{ $free['x'] + 1 }}" y="{{ $free['y'] + 1 }}" width="{{ $free['w'] - 2 }}" height="{{ $ch - 2 }}" fill="#121215" stroke="#63636A" stroke-width="2" stroke-dasharray="8 6"/>
@if ($free['big'])
<text x="{{ $free['x'] + 32 }}" y="{{ $free['y'] + 96 }}" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">This board is free.</text>
<text x="{{ $free['x'] + 32 }}" y="{{ $free['y'] + 140 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">Log in and press Find opponent.</text>
<text x="{{ $free['x'] + 32 }}" y="{{ $free['y'] + 170 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#ADADB0">The next game starts when you do.</text>
<text x="{{ $free['x'] + 32 }}" y="{{ $free['y'] + 214 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">esports.einundzwanzig.space</text>
@else
<text x="{{ $free['x'] + 24 }}" y="{{ $free['y'] + 90 }}" font-family="Unbounded" font-weight="800" font-size="22" fill="#FFFFFF">This board is free.</text>
<text x="{{ $free['x'] + 24 }}" y="{{ $free['y'] + 128 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">Log in and press</text>
<text x="{{ $free['x'] + 24 }}" y="{{ $free['y'] + 152 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">Find opponent.</text>
<text x="{{ $free['x'] + 24 }}" y="{{ $free['y'] + 200 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">esports.einundzwanzig.space</text>
@endif
@endif
</svg>
