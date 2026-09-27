{{--
    C1 · Terminal ticker · single match. Mono only, over the scene's backdrop: board left, the two players face to
    face (white left, black right: a big square face with the side's king, the name, the clock; the side to move in
    orange, red below 10 s), the last moves as a numbered table, the stats bar at the bottom.

    Data contract:
      $game   Game as in a1-match; optional moves: list<string> (last up to 10 SAN, oldest first) — without it the
              move table is left out
      $stats     array for the stats bar: players?, clans?, gamesPlayed?, liveNow?, gamesToday? (missing cells drop out)
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 46, 94, 58);
    $players = [];
    foreach ([['white', 'wk', 'White player', 556], ['black', 'bk', 'Black player', 922]] as [$side, $king, $fallback, $x]) {
        $p = $game[$side] ?? [];
        $ms = (int) ($p['clockMs'] ?? 0);
        $active = (bool) ($p['toMove'] ?? false);
        $players[] = [
            'x' => $x, 'king' => $king, 'avatar' => $p['avatar'] ?? null, 'clanLogo' => $p['clanLogo'] ?? null,
            'name' => K::fit($p['name'] ?? '', K::MONO, 22, 318) ?: $fallback,
            'clock' => K::clockText($ms),
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
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.78])
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'live game'])
@include('stream.rotation.partials.board', ['b' => $b, 'frame' => 6])

<text data-unit="mode" data-box="555 128 1240 152" x="556" y="146" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ K::fit(K::modeLabel($game), K::MONO, 18, 684) }}</text>
<rect x="556" y="164" width="684" height="1" fill="#2A2A30"/>
@foreach ($players as $i => $p)
@include('stream.rotation.partials.face', ['face' => ['uri' => $p['avatar'], 'tag' => null], 'x' => $p['x'], 'y' => 168, 'd' => 136, 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fShape' => 'square', 'fRing' => $p['active'] ? $p['ink'] : '#6B6B72', 'fKing' => $p['king'], 'fKingRim' => '#0A0A0B', 'fClan' => $p['clanLogo']])
<text data-unit="name-{{ $i }}" data-box="{{ $p['x'] - 1 }} 314 {{ $p['x'] + 319 }} 342" x="{{ $p['x'] }}" y="336" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $p['ink'] }}">{{ $p['name'] }}</text>
<text data-unit="clock-{{ $i }}" data-box="{{ $p['x'] - 1 }} 348 {{ $p['x'] + 319 }} 384" x="{{ $p['x'] }}" y="378" font-family="JetBrains Mono" font-weight="700" font-size="36" fill="{{ $p['active'] ? $p['ink'] : '#ADADB0' }}">{{ $p['clock'] }}</text>
@endforeach
<text data-unit="vs" x="898" y="244" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7" text-anchor="middle">vs</text>
<rect x="556" y="400" width="684" height="1" fill="#2A2A30"/>

@foreach ($rows as $ri => $r)
@php($col = $ri < 3 ? 0 : 1)
@php($mx = 556 + $col * 342)
@php($my = 440 + ($ri % 3) * 34)
<text x="{{ $mx }}" y="{{ $my }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#A1A1A7">{{ $r[0] }}</text>
@foreach ([1, 2] as $k)
@if ($r[$k] !== null)<text data-unit="san-{{ $ri }}-{{ $k }}" data-box="{{ $mx + 59 + ($k - 1) * 144 }} {{ $my - 22 }} {{ $mx + 60 + $k * 144 - 12 }} {{ $my + 6 }}" x="{{ $mx + 60 + ($k - 1) * 144 }}" y="{{ $my }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $lastKey === [$ri, $k] ? '#F7931A' : '#FFFFFF' }}">{{ $r[$k] }}</text>@endif
@endforeach
@endforeach

<text x="556" y="584" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#A1A1A7">next:</text>
<text x="628" y="584" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">your game</text>
</svg>
