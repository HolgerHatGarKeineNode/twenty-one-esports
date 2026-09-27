{{--
    A1 · Arena · single match. Board on the dark left half, the two players as fighter cards on the orange right half:
    a big face (the side's king on its corner), the name, the clock; the side to move sits in a near-black block. The
    scene's backdrop lies full-bleed under both halves. Top right (x > 1040, y < 112) stays plain orange for the
    client's LIVE badge.

    Data contract:
      $game      array{
                   white: array{name: string, clockMs: int, toMove: bool, avatar?: ?string, clanLogo?: ?string},
                   black: array{name: string, clockMs: int, toMove: bool, avatar?: ?string, clanLogo?: ?string},
                   fen: string, lastMove: array{from: string, to: string}|null,
                   mode: string,            e.g. "LIVE · CHESS BLITZ 5+3 · CASUAL"
                   result?: string|null,    after the game; replaces the mode line
                   moves?: list<string>,    unused here
                 }
                 avatar: an inline data URI (RotationKit::avatarUri), anything else draws a neutral face;
                 clanLogo?: the player's clan logo (data URI), a badge on the face when present
      $backdrop  ?string, optional: the scene's blurred backdrop (RotationKit::backdropUri)
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $b = K::board($game['fen'] ?? '', $game['lastMove'] ?? null, 48, 88, 68);
    $blocks = [];
    $y = 176;
    foreach ([['black', 'bk', 'Black player'], ['white', 'wk', 'White player']] as [$side, $king, $fallback]) {
        $p = $game[$side] ?? [];
        $active = (bool) ($p['toMove'] ?? false);
        // Fighter card: the side to move gets the bigger face and clock.
        $h = $active ? 224 : 150;
        $d = $active ? 152 : 110;
        $pad = $active ? 36 : 20;
        $nx = 704 + $d + 24;
        // One line, the largest size at which the whole name fits; a name too long even at 20 px is cut with "…".
        $sizes = $active ? [34, 28, 24, 20] : [30, 26, 22, 20];
        $size = end($sizes);
        $clean = K::clean($p['name'] ?? '');
        foreach ($sizes as $try) {
            if ($clean !== '' && K::width($clean, K::nameFont($clean), $try) * 1.04 <= 1208 - $nx) {
                $size = $try;
                break;
            }
        }
        $name = K::name($p['name'] ?? '', $fallback, $size, 1208 - $nx);
        $blocks[] = [
            'y' => $y, 'h' => $h, 'd' => $d, 'faceY' => $y + $pad, 'active' => $active, 'low' => (int) ($p['clockMs'] ?? 0) < 10000,
            'king' => $king, 'avatar' => $p['avatar'] ?? null, 'clanLogo' => $p['clanLogo'] ?? null, 'nx' => $nx,
            'name' => $name, 'nameSize' => $size, 'nameY' => round($y + $pad + 10 + $size * 0.8, 1),
            'clock' => K::clock((int) ($p['clockMs'] ?? 0), $nx, $active ? 64 : 44, 1208 - $nx),
            'clockY' => $y + $pad + $d - 2,
        ];
        $y += $h + 16;
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null])
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
@include('stream.rotation.partials.board', ['b' => $b])

<text data-unit="mode" data-box="679 124 1232 156" x="680" y="148" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ K::fit(K::modeLabel($game), K::MONO, 22, 552) }}</text>
@foreach ($blocks as $i => $blk)
@if ($blk['active'])<rect x="680" y="{{ $blk['y'] }}" width="552" height="{{ $blk['h'] }}" fill="#17120A"/>@endif
@include('stream.rotation.partials.face', ['face' => ['uri' => $blk['avatar'], 'tag' => null], 'x' => 704, 'y' => $blk['faceY'], 'd' => $blk['d'], 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fRing' => $blk['active'] ? '#F7931A' : '#17120A', 'fKing' => $blk['king'], 'fKingRim' => '#17120A', 'fClan' => $blk['clanLogo']])
<text data-unit="name-{{ $i }}" data-box="{{ $blk['nx'] - 1 }} {{ $blk['y'] }} 1209 {{ $blk['clockY'] - $blk['clock']['size'] * 0.75 - 4 }}" x="{{ $blk['nx'] }}" y="{{ $blk['nameY'] }}" font-family="{{ $blk['name']['font'] }}" font-weight="800" font-size="{{ $blk['nameSize'] }}" fill="{{ $blk['active'] ? '#FFFFFF' : '#17120A' }}">{{ $blk['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $blk['clock'], 'y' => $blk['clockY'], 'fill' => $blk['active'] ? ($blk['low'] ? '#F87171' : '#F7931A') : '#17120A'])
@endforeach

<use href="#mark-dark" xlink:href="#mark-dark" x="680" y="624" width="48" height="48"/>
<text x="744" y="657" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#17120A">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
</svg>
