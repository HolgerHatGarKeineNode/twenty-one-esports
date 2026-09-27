{{--
    B1 · Broadcast desk · single match. Channel frame (bug + stats ticker), board left, two player strips on the
    right (the side to move in orange, red below 10 s), the last moves, a call to action.

    Data contract:
      $game   Game as in a1-match; optional moves: list<string> (the last up to 10 SAN moves, oldest first; numbered
              from the FEN's move number) — without it the move block is left out
      $stats  array for the ticker: players?, clans?, gamesPlayed?, gamesToday? (missing ones are left out)
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 48, 104, 65);
    $strips = [];
    foreach ([['black', 'bk', 'Black player', 156], ['white', 'wk', 'White player', 248]] as [$side, $king, $fallback, $y]) {
        $p = $game[$side] ?? [];
        $ms = (int) ($p['clockMs'] ?? 0);
        $active = (bool) ($p['toMove'] ?? false);
        $clock = K::clock($ms, 1220, 36, 200, true);
        $strips[] = [
            'y' => $y, 'king' => $king, 'fill' => $active ? ($ms < 10000 ? '#F87171' : '#F7931A') : '#121215',
            'ink' => $active ? '#17120A' : '#FFFFFF', 'clockInk' => $active ? '#17120A' : ($ms < 10000 ? '#F87171' : '#FFFFFF'),
            'name' => K::name($p['name'] ?? '', $fallback, 26, $clock['x0'] - 16 - 692), 'clock' => $clock,
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
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? []])
@include('stream.rotation.partials.board', ['b' => $b])

<text data-unit="mode" data-box="615 124 1240 146" x="616" y="140" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ K::fit(K::modeLabel($game), K::MONO, 18, 624) }}</text>
@foreach ($strips as $i => $s)
<rect x="616" y="{{ $s['y'] }}" width="624" height="80" fill="{{ $s['fill'] }}"/>
<rect x="636" y="{{ $s['y'] + 20 }}" width="40" height="40" rx="4" fill="#CFCFD4"/>
<use href="#p-{{ $s['king'] }}" xlink:href="#p-{{ $s['king'] }}" x="636" y="{{ $s['y'] + 20 }}" width="40" height="40"/>
<text data-unit="name-{{ $i }}" data-box="691 {{ $s['y'] + 10 }} {{ $s['clock']['x0'] - 12 }} {{ $s['y'] + 70 }}" x="692" y="{{ $s['y'] + 50 }}" font-family="{{ $s['name']['font'] }}" font-weight="800" font-size="26" fill="{{ $s['ink'] }}">{{ $s['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $s['clock'], 'y' => $s['y'] + 54, 'fill' => $s['clockInk']])
@endforeach

@if ($cells !== [])
<text x="616" y="372" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">Last moves</text>
@foreach ($cells as $i => $cell)
<text data-unit="move-{{ $i }}" data-box="{{ 615 + ($i % 3) * 212 }} {{ 384 + intdiv($i, 3) * 34 }} {{ 612 + ($i % 3 + 1) * 212 }} {{ 412 + intdiv($i, 3) * 34 }}" x="{{ 616 + ($i % 3) * 212 }}" y="{{ 406 + intdiv($i, 3) * 34 }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="{{ $loop->last ? '#F7931A' : '#FFFFFF' }}">{{ $cell['text'] }}</text>
@endforeach
@endif

<text x="616" y="600" font-family="Unbounded" font-weight="800" font-size="24" fill="#FFFFFF">Your game could be next.</text>
<text x="616" y="628" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">Log in and press Find opponent.</text>
</svg>
