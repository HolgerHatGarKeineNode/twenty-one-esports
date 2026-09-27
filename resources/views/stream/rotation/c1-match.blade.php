{{--
    C1 · Terminal ticker · single match. Mono only: board left, a two-row player table (the side to move in orange,
    red below 10 s), the last moves as a numbered table, the stats bar at the bottom.

    Data contract:
      $game   Game as in a1-match; optional moves: list<string> (last up to 10 SAN, oldest first) — without it the
              move table is left out
      $stats  array for the stats bar: players?, clans?, gamesPlayed?, liveNow?, gamesToday? (missing cells drop out)
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 46, 94, 58);
    $players = [];
    foreach ([['black', 'Black player', 197], ['white', 'White player', 247]] as [$side, $fallback, $y]) {
        $p = $game[$side] ?? [];
        $ms = (int) ($p['clockMs'] ?? 0);
        $active = (bool) ($p['toMove'] ?? false);
        $players[] = [
            'side' => $side, 'y' => $y,
            'name' => K::fit($p['name'] ?? '', K::MONO, 22, 300) ?: $fallback,
            'clock' => K::clockText($ms),
            'status' => $active ? 'to move' : ($game['result'] ?? null ? 'done' : 'waits'),
            'ink' => $active ? ($ms < 10000 ? '#F87171' : '#F7931A') : '#FFFFFF',
            'active' => $active,
        ];
    }
    $rows = K::moveRows($game['moves'] ?? null, $game['fen'] ?? null);
    $lastKey = null;
    foreach ($rows as $ri => $r) {
        $lastKey = $r[2] !== null ? [$ri, 2] : [$ri, 1];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'live game'])
@include('stream.rotation.partials.board', ['b' => $b, 'frame' => 6])

<text data-unit="mode" data-box="555 128 1240 152" x="556" y="146" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ K::fit(K::modeLabel($game), K::MONO, 18, 684) }}</text>
<rect x="556" y="164" width="684" height="1" fill="#2A2A30"/>
@foreach ($players as $i => $p)
<text x="556" y="{{ $p['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $p['active'] ? $p['ink'] : '#ADADB0' }}">{{ $p['side'] }}</text>
<text data-unit="name-{{ $i }}" data-box="651 {{ $p['y'] - 22 }} 958 {{ $p['y'] + 6 }}" x="652" y="{{ $p['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $p['ink'] }}">{{ $p['name'] }}</text>
<text data-unit="clock-{{ $i }}" data-box="973 {{ $p['y'] - 26 }} 1124 {{ $p['y'] + 6 }}" x="974" y="{{ $p['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="{{ $p['active'] ? $p['ink'] : '#ADADB0' }}">{{ $p['clock'] }}</text>
<text data-unit="status-{{ $i }}" x="1140" y="{{ $p['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $p['active'] ? $p['ink'] : '#ADADB0' }}">{{ $p['status'] }}</text>
<rect x="556" y="{{ $p['y'] + 17 }}" width="684" height="1" fill="#2A2A30"/>
@endforeach

@foreach ($rows as $ri => $r)
@php($col = $ri < 3 ? 0 : 1)
@php($mx = 556 + $col * 342)
@php($my = 316 + ($ri % 3) * 34)
<text x="{{ $mx }}" y="{{ $my }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#8B8B90">{{ $r[0] }}</text>
@foreach ([1, 2] as $k)
@if ($r[$k] !== null)<text data-unit="san-{{ $ri }}-{{ $k }}" data-box="{{ $mx + 59 + ($k - 1) * 144 }} {{ $my - 22 }} {{ $mx + 60 + $k * 144 - 12 }} {{ $my + 6 }}" x="{{ $mx + 60 + ($k - 1) * 144 }}" y="{{ $my }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $lastKey === [$ri, $k] ? '#F7931A' : '#FFFFFF' }}">{{ $r[$k] }}</text>@endif
@endforeach
@endforeach

<text x="556" y="560" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#8B8B90">next:</text>
<text x="628" y="560" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">your game. Log in at</text>
<text x="556" y="590" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">esports.einundzwanzig.space</text>
</svg>
