{{--
    A1 · Arena · single match. Board on the dark left half, the two players on the orange right half; the side to
    move sits in a near-black block. Top right (x > 1040, y < 160) stays plain orange for the client's LIVE badge.

    Data contract:
      $game   array{
                white: array{name: string, clockMs: int, toMove: bool},
                black: array{name: string, clockMs: int, toMove: bool},
                fen: string, lastMove: array{from: string, to: string}|null,
                mode: string,            e.g. "LIVE · CHESS BLITZ 5+3 · CASUAL"
                result?: string|null,    after the game; replaces the mode line
                moves?: list<string>,    unused here
              }
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 48, 88, 68);
    $daily = K::isDaily($game);
    $blocks = [];
    $y = 206;
    foreach ([['black', 'Black', 'Black player'], ['white', 'White', 'White player']] as [$side, $label, $fallback]) {
        $p = $game[$side] ?? [];
        $active = (bool) ($p['toMove'] ?? false);
        $h = $active ? 208 : 130;
        $nameSize = $active ? 36 : 44;
        $blocks[] = [
            'y' => $y, 'h' => $h, 'active' => $active, 'low' => (int) ($p['clockMs'] ?? 0) < 10000,
            'label' => $label.', '.($active ? 'to move' : ($game['result'] ?? null ? 'done' : 'waiting')),
            'name' => K::name($p['name'] ?? '', $fallback, $nameSize, 504),
            'nameSize' => $nameSize, 'nameY' => $y + ($active ? 86 : 70),
            'clock' => K::clock((int) ($p['clockMs'] ?? 0), 704, $active ? 56 : 40, 504),
            'clockY' => $y + ($active ? 152 : 118),
            'labelY' => $y + ($active ? 38 : 18),
        ];
        $y += $h + 16;
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
@include('stream.rotation.partials.board', ['b' => $b])

<text data-unit="mode" data-box="679 160 1232 190" x="680" y="182" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ K::fit(K::modeLabel($game), K::MONO, 22, 552) }}</text>
@foreach ($blocks as $i => $blk)
@if ($blk['active'])<rect x="680" y="{{ $blk['y'] }}" width="552" height="{{ $blk['h'] }}" fill="#17120A"/>@endif
<text data-unit="label-{{ $i }}" x="704" y="{{ $blk['labelY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $blk['active'] ? '#F7931A' : '#17120A' }}">{{ $blk['label'] }}</text>
<text data-unit="name-{{ $i }}" data-box="703 {{ $blk['y'] }} 1208 {{ $blk['nameY'] + 12 }}" x="704" y="{{ $blk['nameY'] }}" font-family="{{ $blk['name']['font'] }}" font-weight="800" font-size="{{ $blk['nameSize'] }}" fill="{{ $blk['active'] ? '#FFFFFF' : '#17120A' }}">{{ $blk['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $blk['clock'], 'y' => $blk['clockY'], 'fill' => $blk['active'] ? ($blk['low'] ? '#F87171' : '#F7931A') : '#17120A'])
@if ($blk['active'] && $daily)<text data-unit="note-{{ $i }}" x="704" y="{{ $blk['y'] + 188 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">left for this move</text>@endif
@endforeach

<use href="#mark-dark" xlink:href="#mark-dark" x="680" y="624" width="48" height="48"/>
<text x="744" y="642" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#17120A">Watch it, then play your own:</text>
<text x="744" y="670" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#17120A">esports.einundzwanzig.space</text>
</svg>
