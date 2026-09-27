{{--
    A2 · Arena · gallery. The live games as one line-up of boards on the dark floor (2 to 6 in a row), names and
    clocks under each board, the side to move in orange (red below 10 s). Orange call-to-action band at the bottom.
    Top right stays empty for the client's LIVE badge.

    Data contract:
      $games  list<Game> (2..6 shown; more are cut and counted), Game as in a1-match
      $more   int, optional: live games not in $games
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $list = array_slice(array_values($games ?? []), 0, 6);
    $total = count($games ?? []) + max(0, (int) ($more ?? 0));
    $n = max(1, count($list));
    $gap = $n >= 4 ? 32 : 48;
    $cw = floor((1200 - ($n - 1) * $gap) / $n);
    $stacked = $cw < 340;
    $nameSize = match (true) { $n <= 2 => 22, $n === 3 => 20, $n === 4 => 18, default => 16 };
    $clockMax = $stacked ? ($n >= 5 ? 22 : 26) : $nameSize;
    $labelSize = $cw >= 300 ? 16 : 14;
    $rowH = $stacked ? $nameSize * 1.25 + 4 + $clockMax * 1.1 : $nameSize * 1.4;
    $textH = $labelSize + 14 + 2 * ($rowH + 10);
    $bs = floor(min($cw, 496 - $textH - 16) / 8) * 8;
    $blockH = $bs + 16 + $textH;
    $top = 104 + (496 - $blockH) / 2;
    $x0 = 40 + (1200 - ($n * $cw + ($n - 1) * $gap)) / 2;
    $cards = [];
    foreach ($list as $i => $g) {
        $x = $x0 + $i * ($cw + $gap);
        $bx = $x + ($stacked ? ($cw - $bs) / 2 : 0);
        $ty = $top + $bs + 16;
        $rows = [];
        $ry = $ty + $labelSize + 14;
        foreach ([['white', 'White player'], ['black', 'Black player']] as [$side, $fallback]) {
            $p = $g[$side] ?? [];
            $ms = (int) ($p['clockMs'] ?? 0);
            $active = (bool) ($p['toMove'] ?? false);
            $ink = $active ? ($ms < 10000 ? '#F87171' : '#F7931A') : '#FFFFFF';
            if ($stacked) {
                $clock = K::clock($ms, $x, $clockMax, $cw);
                $name = K::name($p['name'] ?? '', $fallback, $nameSize, $cw);
                $nameY = $ry + $nameSize;
                $clockY = $nameY + 4 + $clockMax * 1.05;
            } else {
                $clock = K::clock($ms, $x + $cw, $clockMax, $cw / 2, true);
                $name = K::name($p['name'] ?? '', $fallback, $nameSize, $cw - ($clock['x1'] - $clock['x0']) - 16);
                $nameY = $ry + $nameSize;
                $clockY = $nameY;
            }
            $rows[] = ['name' => $name, 'nameY' => $nameY, 'clock' => $clock, 'clockY' => $clockY, 'ink' => $ink, 'nameMax' => $stacked ? $x + $cw : $clock['x0'] - 8];
            $ry += $rowH + 10;
        }
        $cards[] = [
            'x' => $x, 'b' => K::board($g['fen'] ?? '', $g['lastMove'] ?? null, $bx, $top, $bs / 8),
            'label' => K::fit(K::modeLabel($g), K::MONO, $labelSize, $cw), 'labelY' => $ty + $labelSize, 'rows' => $rows,
        ];
    }
    $count = K::plural($total, 'game', 'games').($total > count($list) ? ', '.count($list).' shown' : '');
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
<text x="40" y="72" font-family="Unbounded" font-weight="800" font-size="36" fill="#FFFFFF">Live now</text>
<text data-unit="count" data-box="266 50 1000 80" x="267" y="72" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#F7931A">{{ $count }}</text>

@foreach ($cards as $ci => $card)
@include('stream.rotation.partials.board', ['b' => $card['b'], 'frame' => 6])
<text data-unit="label-{{ $ci }}" data-box="{{ $card['x'] - 1 }} {{ $card['labelY'] - 16 }} {{ $card['x'] + $cw + 1 }} {{ $card['labelY'] + 4 }}" x="{{ $card['x'] }}" y="{{ $card['labelY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $labelSize }}" fill="#ADADB0">{{ $card['label'] }}</text>
@foreach ($card['rows'] as $ri => $r)
<text data-unit="name-{{ $ci }}-{{ $ri }}" data-box="{{ $card['x'] - 1 }} {{ $r['nameY'] - $nameSize }} {{ $r['nameMax'] }} {{ $r['nameY'] + $nameSize * 0.3 }}" x="{{ $card['x'] }}" y="{{ $r['nameY'] }}" font-family="{{ $r['name']['font'] }}" font-weight="800" font-size="{{ $nameSize }}" fill="{{ $r['ink'] }}">{{ $r['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $r['clock'], 'y' => $r['clockY'], 'fill' => $r['ink']])
@endforeach
@endforeach

<rect x="0" y="616" width="1280" height="104" fill="#F7931A"/>
<text x="40" y="678" font-family="Unbounded" font-weight="800" font-size="28" fill="#17120A">The next game starts when you do.</text>
<text x="1240" y="676" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A" text-anchor="end">esports.einundzwanzig.space</text>
</svg>
