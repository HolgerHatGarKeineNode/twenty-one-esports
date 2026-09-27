{{--
    B1 · Broadcast desk · single match. Channel frame (bug + stats ticker) over the scene's backdrop, board left, two
    player strips on the right, each led by the player's face with the side's king on its corner (the side to move in
    orange, red below 10 s), the last moves, a call to action.

    Data contract:
      $game   Game as in a1-match; optional moves: list<string> (the last up to 10 SAN moves, oldest first; numbered
              from the FEN's move number) — without it the move block is left out
      $stats     array for the ticker: players?, clans?, gamesPlayed?, gamesToday? (missing ones are left out)
      $backdrop  ?string, optional: as in a1-match
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 48, 104, 65);
    $strips = [];
    foreach ([['black', 'bk', 'Black player', 156], ['white', 'wk', 'White player', 280]] as [$side, $king, $fallback, $y]) {
        $p = $game[$side] ?? [];
        $ms = (int) ($p['clockMs'] ?? 0);
        $active = (bool) ($p['toMove'] ?? false);
        $clock = K::clock($ms, 1220, 48, 220, true);
        $strips[] = [
            'y' => $y, 'king' => $king, 'fill' => $active ? ($ms < 10000 ? '#F87171' : '#F7931A') : '#121215',
            'ink' => $active ? '#17120A' : '#FFFFFF', 'clockInk' => $active ? '#17120A' : ($ms < 10000 ? '#F87171' : '#FFFFFF'),
            'name' => K::headline(K::clean($p['name'] ?? '') === '' ? $fallback : $p['name'], [28, 24, 22], $clock['x0'] - 20 - 740, 2), 'clock' => $clock,
            'avatar' => $p['avatar'] ?? null, 'clanLogo' => $p['clanLogo'] ?? null, 'active' => $active,
        ];
    }
    $rows = K::moveRows($game['moves'] ?? null, $game['fen'] ?? null);
    $cells = [];
    foreach ($rows as $r) {
        $cells[] = ['text' => $r[0].'. '.($r[1] ?? '...').($r[2] !== null ? ' '.$r[2] : '')];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])
@include('stream.rotation.partials.board', ['b' => $b])

<text data-unit="mode" data-box="615 124 1240 146" x="616" y="140" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ K::fit(K::modeLabel($game), K::MONO, 18, 624) }}</text>
@foreach ($strips as $i => $s)
<rect x="616" y="{{ $s['y'] }}" width="624" height="112" fill="{{ $s['fill'] }}"/>
@include('stream.rotation.partials.face', ['face' => ['uri' => $s['avatar'], 'tag' => null], 'x' => 632, 'y' => $s['y'] + 12, 'd' => 88, 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fRing' => $s['active'] ? '#17120A' : '#F7931A', 'fKing' => $s['king'], 'fKingRim' => $s['active'] ? '#17120A' : '#121215', 'fClan' => $s['clanLogo']])
@php($step = round($s['name']['size'] * 1.15))
@php($ny = round($s['y'] + 56 + $s['name']['size'] * 0.36 - (count($s['name']['lines']) - 1) * $step / 2, 1))
@foreach ($s['name']['lines'] as $li => $line)
<text data-unit="name-{{ $i }}-{{ $li }}" data-box="739 {{ $s['y'] + 8 }} {{ $s['clock']['x0'] - 12 }} {{ $s['y'] + 104 }}" x="740" y="{{ $ny + $li * $step }}" font-family="{{ $s['name']['font'] }}" font-weight="800" font-size="{{ $s['name']['size'] }}" fill="{{ $s['ink'] }}">{{ $line }}</text>
@endforeach
@include('stream.rotation.partials.clock', ['c' => $s['clock'], 'y' => $s['y'] + 74, 'fill' => $s['clockInk']])
@endforeach

@if ($cells !== [])
@foreach ($cells as $i => $cell)
<text data-unit="move-{{ $i }}" data-box="{{ 615 + ($i % 3) * 212 }} {{ 418 + intdiv($i, 3) * 34 }} {{ 612 + ($i % 3 + 1) * 212 }} {{ 446 + intdiv($i, 3) * 34 }}" x="{{ 616 + ($i % 3) * 212 }}" y="{{ 440 + intdiv($i, 3) * 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $loop->last ? '#F7931A' : '#FFFFFF' }}">{{ $cell['text'] }}</text>
@endforeach
@endif

<text x="616" y="616" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">Your game could be next.</text>
</svg>
