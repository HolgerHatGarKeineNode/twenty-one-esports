{{--
    D5 · Board live (plan "Mühle und Dame", P7). With a live board game: the arena of a1-match, the board on the dark
    left half, the two players as fighter cards on the orange right half (face, a disc of their colour, name, clock;
    the side to move in a near-black block). Without one: the terminal ticker of d4, the board games switched on as
    their start positions on the right, the claim and three facts on the left. The board is the rules' own view
    (BoardRules::view()) scaled by BoardScene::drawing(), so the view knows no game.

    Data contract (BoardScene::data()):
      $board     array{game: string, mode: string, e.g. "Checkers · Daily, casual", daily?: bool (correspondence:
                   the side to move shows the h:mm left for its move, the other side "1 move a day" in place of a clock),
                   white: array{name: string, clockMs: int, toMove: bool, avatar: ?string},
                   black: array{name: string, clockMs: int, toMove: bool, avatar: ?string},
                   view: array (BoardRules::view()), last: list<string> (point ids of the last move)}|null
      $boards    list<array{name: string, view: array, last: list<string>}>: the teaser's start positions (without $board)
      $stats     array: the stats bar counts (c-chrome, teaser only)
      $backdrop  ?string, optional: the live game's cover as backdrop, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\BoardScene', 'BS')
@use('App\Support\Board\BlockspaceDrawing', 'BSD')
@php
    $live = is_array($board ?? null) ? $board : null;
    $drawings = [];
    $blocks = [];
    $labels = [];
    $split = 576;
    $cardX = $split + 24;
    $cardW = K::CONTENT_RIGHT - $cardX;
    if ($live) {
        // As a1: the board on the dark left (x 0..576), the fighter cards on the orange right, text within x 600..1016
        // (the now-playing column covers x 1040..1280); the name across the card on top, face and clock below.
        // Blockspace draws its own way (BlockspaceDrawing): square stones in orange and blue, and the mempool ladder beside them.
        $liveBlockspace = ($live['slug'] ?? null) === 'blockspace';
        $drawings[] = $liveBlockspace
            ? ['x' => 48, 'y' => 120, 'size' => 480, 'bs' => BSD::layout($live['view'], $live['last'] ?? [], 64, 136, 448)]
            : BS::drawing($live['view'], $live['last'] ?? [], 48, 120, 480);
        $sideFill = $liveBlockspace ? BSD::FILL : BS::PIECE_FILL;
        $y = 160;
        foreach ([['black', 'b', 'Black player'], ['white', 'w', 'White player']] as [$side, $colour, $fallback]) {
            $p = $live[$side] ?? [];
            $active = (bool) ($p['toMove'] ?? false);
            $h = $active ? 212 : 168;
            $d = $active ? 120 : 84;
            $pad = $active ? 24 : 12;
            $nx = $cardX + $pad;
            $nameMax = K::CONTENT_RIGHT - $pad - $nx;
            $sizes = $active ? [32, 28, 24, 20, 18] : [28, 24, 20, 18];
            $size = end($sizes);
            $clean = K::clean($p['name'] ?? '');
            foreach ($sizes as $try) {
                if ($clean !== '' && K::width($clean, K::nameFont($clean), $try) * 1.04 <= $nameMax) {
                    $size = $try;
                    break;
                }
            }
            $faceY = $y + $pad + 44;
            $cx = $nx + $d + 24;
            $blocks[] = [
                'y' => $y, 'h' => $h, 'd' => $d, 'faceY' => $faceY, 'active' => $active, 'low' => (int) ($p['clockMs'] ?? 0) < 10000,
                'colour' => $live['colours'][$colour] ?? $colour, 'avatar' => $p['avatar'] ?? null, 'nx' => $nx, 'cx' => $cx,
                'name' => K::name($p['name'] ?? '', $fallback, $size, $nameMax), 'nameSize' => $size, 'nameY' => round($y + $pad + $size * 0.8, 1),
                'clock' => K::clock((int) ($p['clockMs'] ?? 0), $cx, $active ? 64 : 44, K::CONTENT_RIGHT - $pad - $cx),
                // Correspondence: no clock for the side that waits, its day starts with its turn.
                'noClock' => ! empty($live['daily']) && ! $active,
                'clockY' => round($faceY + $d / 2 + ($active ? 24 : 17), 1),
            ];
            $y += $h + 16;
        }
    } else {
        $shown = array_slice(is_array($boards ?? null) ? $boards : [], 0, 2);
        // The text x 40..520, the boards x 560..1016 (the now-playing column covers x 1040..1280).
        $boardSize = count($shown) > 1 ? 208 : 400;
        foreach ($shown as $i => $entry) {
            $bx = count($shown) > 1 ? 568 + $i * 232 : 600;
            $drawings[] = ($entry['slug'] ?? null) === 'blockspace'
                ? ['x' => $bx, 'y' => 150, 'size' => $boardSize, 'bs' => BSD::layout($entry['view'], [], $bx + 8, 158, $boardSize - 16)]
                : BS::drawing($entry['view'], [], $bx, 150, $boardSize);
            $labels[] = ['x' => $bx, 'w' => $boardSize, 'y' => 150 + $boardSize + 44, 'text' => K::fit($entry['name'] ?? '', K::MONO, 20, $boardSize)];
        }
        $names = [];
        foreach ($shown as $entry) {
            $names[] = K::fit($entry['name'] ?? '', K::DISPLAY, 36, 480);
        }
        $rule = 258 + 52 * max(0, count($names) - 1) + 38;
        // COPY-CHECK: the board games are correspondence only since 2026-10-07 (App\Games\NineMensMorris, Checkers,
        // Blockli modes()), played on the board page in the browser; a game starts by a challenge (board.correspondence)
        // and every board game has its own ladder (ladder.show, plan P5).
        $facts = [
            ['Daily', '1 move a day, right in the browser.'],
            ['Challenge', 'Pick a player and send a challenge.'],
            ['Ladder', 'Climb its own ladder.'],
        ];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => $live ? 0.7 : 0.78])
@if ($live)
<rect x="{{ $split }}" y="0" width="{{ K::CONTENT_WIDTH - $split }}" height="720" fill="#F7931A"/>
@endif
@foreach ($drawings as $i => $dr)
<g data-unit="board-{{ $i }}">
<rect x="{{ $dr['x'] - 8 }}" y="{{ $dr['y'] - 8 }}" width="{{ $dr['size'] + 16 }}" height="{{ $dr['size'] + 16 }}" fill="#3A2C14"/>
@if (isset($dr['bs']))
<rect x="{{ $dr['x'] }}" y="{{ $dr['y'] }}" width="{{ $dr['size'] }}" height="{{ $dr['size'] }}" fill="{{ BSD::GROUND }}"/>
@foreach ($dr['bs']['cells'] as [$cx, $cy, $cs, $stone, $fresh])<rect @if ($stone !== null) data-stone="{{ $stone }}" @endif x="{{ $cx }}" y="{{ $cy }}" width="{{ $cs }}" height="{{ $cs }}" fill="{{ $stone === null ? BSD::CELL : BSD::FILL[$stone] }}" @if ($fresh) stroke="#FFFFFF" stroke-width="2" @endif/>@endforeach
@foreach ($dr['bs']['rungs'] as $rung)
<rect data-rung="{{ $rung['bonus'] }}" x="{{ $rung['x'] }}" y="{{ $rung['y'] }}" width="{{ $rung['w'] }}" height="{{ $rung['h'] }}" fill="{{ BSD::RUNG }}"/>
<text x="{{ $rung['label']['x'] }}" y="{{ $rung['label']['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $rung['label']['size'] }}" fill="{{ BSD::INK_MUTED }}">+{{ $rung['bonus'] }}</text>
@foreach ($rung['form'] as [$fx, $fy, $fs])<rect x="{{ $fx }}" y="{{ $fy }}" width="{{ $fs }}" height="{{ $fs }}" fill="{{ BSD::INK }}"/>@endforeach
@endforeach
@foreach ($dr['bs']['scores'] as $score)
@if ($live && $score['side'] === $dr['bs']['turn'])<rect x="{{ $score['x'] - 3 }}" y="{{ $score['y'] - 3 }}" width="{{ $score['swatch'] + 6 }}" height="{{ $score['swatch'] + 6 }}" fill="#FFFFFF"/>@endif
<rect x="{{ $score['x'] }}" y="{{ $score['y'] }}" width="{{ $score['swatch'] }}" height="{{ $score['swatch'] }}" fill="{{ BSD::FILL[$score['side']] }}"/>
<text data-unit="yield-{{ $score['side'] }}" x="{{ $score['x'] + $score['swatch'] * 1.4 }}" y="{{ $score['y'] + $score['swatch'] * 0.82 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $score['size'] }}" fill="{{ BSD::INK }}">{{ $score['value'] }} sat</text>
@endforeach
<text data-unit="queue" x="{{ $dr['bs']['queue']['x'] }}" y="{{ $dr['bs']['queue']['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ max(12, round($dr['bs']['queue']['size'] * 1.6, 1)) }}" fill="{{ BSD::INK_MUTED }}">{{ $dr['bs']['queue']['value'] }} to come</text>
@else
<rect x="{{ $dr['x'] }}" y="{{ $dr['y'] }}" width="{{ $dr['size'] }}" height="{{ $dr['size'] }}" fill="#1C1C20"/>
@foreach ($dr['cells'] as [$cx, $cy, $cs])<rect x="{{ $cx }}" y="{{ $cy }}" width="{{ $cs }}" height="{{ $cs }}" fill="#3F3F46"/>@endforeach
@foreach ($dr['lines'] as [$x1, $y1, $x2, $y2])<line x1="{{ $x1 }}" y1="{{ $y1 }}" x2="{{ $x2 }}" y2="{{ $y2 }}" stroke="#71717A" stroke-width="{{ $dr['stroke'] }}" stroke-linecap="round"/>@endforeach
@foreach ($dr['last'] as [$lx, $ly])<circle cx="{{ $lx }}" cy="{{ $ly }}" r="{{ round($dr['radius'] * 1.12, 2) }}" fill="#F7931A" fill-opacity="0.3"/>@endforeach
@if ($dr['lines'] !== [])
@foreach ($dr['dots'] as [$dx, $dy])<circle cx="{{ $dx }}" cy="{{ $dy }}" r="{{ $dr['dot'] }}" fill="#71717A"/>@endforeach
@endif
@foreach ($dr['pieces'] as $pc)
<circle data-piece="{{ $pc['side'] }}{{ $pc['king'] ? 'k' : '' }}" cx="{{ $pc['x'] }}" cy="{{ $pc['y'] }}" r="{{ round($dr['radius'] * 0.92, 2) }}" fill="{{ BS::PIECE_FILL[$pc['side']] ?? '#F4F4F5' }}" stroke="{{ BS::PIECE_STROKE[$pc['side']] ?? '#A1A1AA' }}" stroke-width="{{ round(max(1.5, $dr['radius'] * 0.08), 2) }}"/>
@if ($pc['king'])<circle cx="{{ $pc['x'] }}" cy="{{ $pc['y'] }}" r="{{ round($dr['radius'] * 0.45, 2) }}" fill="none" stroke="#F7931A" stroke-width="{{ round(max(1.5, $dr['radius'] * 0.1), 2) }}"/>@endif
@endforeach
{{-- Blockli: blocks as bars on the board, and in each side's tray the blocks it has left. --}}
@foreach ($dr['bars'] as $bar)
<rect data-bar="{{ $bar['side'] }}:{{ $bar['kind'] }}" x="{{ $bar['x'] }}" y="{{ $bar['y'] }}" width="{{ $bar['w'] }}" height="{{ $bar['h'] }}" rx="{{ $bar['r'] }}" fill="{{ BS::PIECE_FILL[$bar['side']] ?? '#F4F4F5' }}" stroke="{{ BS::PIECE_STROKE[$bar['side']] ?? '#A1A1AA' }}" stroke-width="{{ round(max(1, $bar['r'] * 0.24), 2) }}"/>
@endforeach
@endif
</g>
@endforeach
@if ($live)
<text data-unit="mode" data-box="{{ $cardX - 1 }} 112 {{ K::CONTENT_RIGHT }} 144" x="{{ $cardX }}" y="136" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize(K::clean($live['mode'] ?? ''), 22, $cardW) }}" fill="#17120A">{{ K::fit(K::clean($live['mode'] ?? ''), K::MONO, K::monoSize(K::clean($live['mode'] ?? ''), 22, $cardW), $cardW) }}</text>

@foreach ($blocks as $i => $blk)
@if ($blk['active'])<rect x="{{ $cardX }}" y="{{ $blk['y'] }}" width="{{ $cardW }}" height="{{ $blk['h'] }}" fill="#17120A"/>@endif
@include('stream.rotation.partials.face', ['face' => ['uri' => $blk['avatar'], 'tag' => null], 'x' => $blk['nx'], 'y' => $blk['faceY'], 'd' => $blk['d'], 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fRing' => $blk['active'] ? '#F7931A' : '#17120A'])
<circle data-unit="side-{{ $i }}" cx="{{ $blk['nx'] + $blk['d'] - 12 }}" cy="{{ $blk['faceY'] + $blk['d'] - 12 }}" r="{{ $blk['active'] ? 18 : 14 }}" fill="{{ $sideFill[$blk['colour']] }}" stroke="#17120A" stroke-width="3"/>
<text data-unit="name-{{ $i }}" data-box="{{ $blk['nx'] - 1 }} {{ $blk['y'] }} {{ K::CONTENT_RIGHT }} {{ $blk['faceY'] - 4 }}" x="{{ $blk['nx'] }}" y="{{ $blk['nameY'] }}" font-family="{{ $blk['name']['font'] }}" font-weight="800" font-size="{{ $blk['nameSize'] }}" fill="{{ $blk['active'] ? '#FFFFFF' : '#17120A' }}">{{ $blk['name']['text'] }}</text>
@if ($blk['noClock'])
<text data-unit="clock" x="{{ $blk['cx'] }}" y="{{ $blk['clockY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#17120A">1 move a day</text>
@else
@include('stream.rotation.partials.clock', ['c' => $blk['clock'], 'y' => $blk['clockY'], 'fill' => $blk['active'] ? ($blk['low'] ? '#F87171' : '#F7931A') : '#17120A'])
@endif
@endforeach
<use href="#mark-dark" xlink:href="#mark-dark" x="{{ $cardX }}" y="632" width="40" height="40"/>
<text x="{{ $cardX + 56 }}" y="659" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#17120A">esports.einundzwanzig.space</text>
@else
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'board games'])
<text data-unit="claim" data-box="39 166 520 222" x="40" y="206" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Board games</text>
@foreach ($names as $i => $name)
<text data-unit="game-{{ $i }}" data-box="39 {{ 218 + $i * 52 }} 520 {{ 272 + $i * 52 }}" x="40" y="{{ 258 + $i * 52 }}" font-family="Unbounded" font-weight="800" font-size="36" fill="#F7931A">{{ $name }}</text>
@endforeach
<rect x="40" y="{{ $rule }}" width="480" height="1" fill="#2A2A30"/>
@foreach ($facts as $i => [$label, $fact])
<text x="40" y="{{ $rule + 44 + $i * 62 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#F7931A">{{ $label }}</text>
<text data-unit="fact-{{ $i }}" data-box="153 {{ $rule + 22 + $i * 62 }} 520 {{ $rule + 50 + $i * 62 }}" x="154" y="{{ $rule + 44 + $i * 62 }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ K::monoSize($fact, 18, 366) }}" fill="#FFFFFF">{{ K::fit($fact, K::MONO, K::monoSize($fact, 18, 366), 366) }}</text>
<rect x="40" y="{{ $rule + 66 + $i * 62 }}" width="480" height="1" fill="#2A2A30"/>

@endforeach
@foreach ($labels as $i => $lb)
<text data-unit="board-name-{{ $i }}" data-box="{{ $lb['x'] - 1 }} {{ $lb['y'] - 22 }} {{ $lb['x'] + $lb['w'] + 1 }} {{ $lb['y'] + 6 }}" x="{{ $lb['x'] }}" y="{{ $lb['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $lb['text'] }}</text>
@endforeach
@endif
</svg>
