{{--
    D5 · Board live (plan "Mühle und Dame", P7). With a live board game: the arena of a1-match, the board on the dark
    left half, the two players as fighter cards on the orange right half (face, a disc of their colour, name, clock;
    the side to move in a near-black block). Without one: the terminal ticker of d4, the board games switched on as
    their start positions on the right, the claim and three facts on the left. The board is the rules' own view
    (BoardRules::view()) scaled by BoardScene::drawing(), so the view knows no game.

    Data contract (BoardScene::data()):
      $board     array{game: string, mode: string, e.g. "Checkers · Blitz 5+3, casual",
                   white: array{name: string, clockMs: int, toMove: bool, avatar: ?string},
                   black: array{name: string, clockMs: int, toMove: bool, avatar: ?string},
                   view: array (BoardRules::view()), last: list<string> (point ids of the last move)}|null
      $boards    list<array{name: string, view: array, last: list<string>}>: the teaser's start positions (without $board)
      $stats     array: the stats bar counts (c-chrome, teaser only)
      $backdrop  ?string, optional: the live game's cover as backdrop, else the brand's
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@use('App\Support\TwentyOne\Stream\BoardScene', 'BS')
@php
    $live = is_array($board ?? null) ? $board : null;
    $drawings = [];
    $blocks = [];
    $labels = [];
    if ($live) {
        $drawings[] = BS::drawing($live['view'], $live['last'] ?? [], 48, 88, 544);
        $y = 176;
        foreach ([['black', 'b', 'Black player'], ['white', 'w', 'White player']] as [$side, $colour, $fallback]) {
            $p = $live[$side] ?? [];
            $active = (bool) ($p['toMove'] ?? false);
            $h = $active ? 224 : 150;
            $d = $active ? 152 : 110;
            $pad = $active ? 36 : 20;
            $nx = 704 + $d + 24;
            $sizes = $active ? [34, 28, 24, 20] : [30, 26, 22, 20];
            $size = end($sizes);
            $clean = K::clean($p['name'] ?? '');
            foreach ($sizes as $try) {
                if ($clean !== '' && K::width($clean, K::nameFont($clean), $try) * 1.04 <= 1208 - $nx) {
                    $size = $try;
                    break;
                }
            }
            $blocks[] = [
                'y' => $y, 'h' => $h, 'd' => $d, 'faceY' => $y + $pad, 'active' => $active, 'low' => (int) ($p['clockMs'] ?? 0) < 10000,
                'colour' => $colour, 'avatar' => $p['avatar'] ?? null, 'nx' => $nx,
                'name' => K::name($p['name'] ?? '', $fallback, $size, 1208 - $nx), 'nameSize' => $size, 'nameY' => round($y + $pad + 10 + $size * 0.8, 1),
                'clock' => K::clock((int) ($p['clockMs'] ?? 0), $nx, $active ? 64 : 44, 1208 - $nx),
                'clockY' => $y + $pad + $d - 2,
            ];
            $y += $h + 16;
        }
    } else {
        $shown = array_slice(is_array($boards ?? null) ? $boards : [], 0, 2);
        $boardSize = count($shown) > 1 ? 260 : 400;
        foreach ($shown as $i => $entry) {
            $bx = count($shown) > 1 ? 700 + $i * 280 : 780;
            $drawings[] = BS::drawing($entry['view'], [], $bx, 150, $boardSize);
            $labels[] = ['x' => $bx, 'w' => $boardSize, 'y' => 150 + $boardSize + 44, 'text' => K::fit($entry['name'] ?? '', K::MONO, 20, $boardSize)];
        }
        $names = [];
        foreach ($shown as $entry) {
            $names[] = K::fit($entry['name'] ?? '', K::DISPLAY, 40, 600);
        }
        $rule = 258 + 52 * max(0, count($names) - 1) + 38;
        // COPY-CHECK: both board games are blitz 5+3 (App\Games\NineMensMorris, Checkers modes()), played on the
        // board page in the browser; the lobby pairs from its casual queue ("Find opponent", board.lobby) and every
        // board game has its own casual ladder (ladder.show, plan P5).
        $facts = [
            ['Blitz', '5+3, right in the browser.'],
            ['Casual', 'Find an opponent in seconds.'],
            ['Ladder', 'Climb its own casual ladder.'],
        ];
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => $live ? 0.7 : 0.78])
@if ($live)
<rect x="640" y="0" width="640" height="720" fill="#F7931A"/>
@endif
@foreach ($drawings as $i => $dr)
<g data-unit="board-{{ $i }}">
<rect x="{{ $dr['x'] - 8 }}" y="{{ $dr['y'] - 8 }}" width="{{ $dr['size'] + 16 }}" height="{{ $dr['size'] + 16 }}" fill="#3A2C14"/>
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
</g>
@endforeach
@if ($live)
<text data-unit="mode" data-box="679 124 1232 156" x="680" y="148" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#17120A">{{ K::fit(K::clean($live['mode'] ?? ''), K::MONO, 22, 552) }}</text>
@foreach ($blocks as $i => $blk)
@if ($blk['active'])<rect x="680" y="{{ $blk['y'] }}" width="552" height="{{ $blk['h'] }}" fill="#17120A"/>@endif
@include('stream.rotation.partials.face', ['face' => ['uri' => $blk['avatar'], 'tag' => null], 'x' => 704, 'y' => $blk['faceY'], 'd' => $blk['d'], 'id' => 'p'.$i,
    'fUnit' => 'face-'.$i, 'fRing' => $blk['active'] ? '#F7931A' : '#17120A'])
<circle data-unit="side-{{ $i }}" cx="{{ 704 + $blk['d'] - 12 }}" cy="{{ $blk['faceY'] + $blk['d'] - 12 }}" r="{{ $blk['active'] ? 20 : 16 }}" fill="{{ BS::PIECE_FILL[$blk['colour']] }}" stroke="#17120A" stroke-width="3"/>
<text data-unit="name-{{ $i }}" data-box="{{ $blk['nx'] - 1 }} {{ $blk['y'] }} 1209 {{ $blk['clockY'] - $blk['clock']['size'] * 0.75 - 4 }}" x="{{ $blk['nx'] }}" y="{{ $blk['nameY'] }}" font-family="{{ $blk['name']['font'] }}" font-weight="800" font-size="{{ $blk['nameSize'] }}" fill="{{ $blk['active'] ? '#FFFFFF' : '#17120A' }}">{{ $blk['name']['text'] }}</text>
@include('stream.rotation.partials.clock', ['c' => $blk['clock'], 'y' => $blk['clockY'], 'fill' => $blk['active'] ? ($blk['low'] ? '#F87171' : '#F7931A') : '#17120A'])
@endforeach
<use href="#mark-dark" xlink:href="#mark-dark" x="680" y="624" width="48" height="48"/>
<text x="744" y="657" font-family="JetBrains Mono" font-weight="700" font-size="24" fill="#17120A">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 64, K::DISPLAY, 24, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#17120A', 'countInk' => '#17120A', 'wordInk' => '#17120A'])@endif
@else
@include('stream.rotation.partials.c-chrome', ['stats' => $stats ?? [], 'section' => 'board games'])
<text data-unit="claim" data-box="39 166 640 222" x="40" y="206" font-family="Unbounded" font-weight="800" font-size="40" fill="#FFFFFF">Board games</text>
@foreach ($names as $i => $name)
<text data-unit="game-{{ $i }}" data-box="39 {{ 218 + $i * 52 }} 640 {{ 272 + $i * 52 }}" x="40" y="{{ 258 + $i * 52 }}" font-family="Unbounded" font-weight="800" font-size="40" fill="#F7931A">{{ $name }}</text>
@endforeach
<rect x="40" y="{{ $rule }}" width="600" height="1" fill="#2A2A30"/>
@foreach ($facts as $i => [$label, $fact])
<text x="40" y="{{ $rule + 44 + $i * 62 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">{{ $label }}</text>
<text data-unit="fact-{{ $i }}" data-box="149 {{ $rule + 22 + $i * 62 }} 640 {{ $rule + 50 + $i * 62 }}" x="150" y="{{ $rule + 44 + $i * 62 }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ K::fit($fact, K::MONO, 20, 490) }}</text>
<rect x="40" y="{{ $rule + 66 + $i * 62 }}" width="600" height="1" fill="#2A2A30"/>
@endforeach
@foreach ($labels as $i => $lb)
<text data-unit="board-name-{{ $i }}" data-box="{{ $lb['x'] - 1 }} {{ $lb['y'] - 22 }} {{ $lb['x'] + $lb['w'] + 1 }} {{ $lb['y'] + 6 }}" x="{{ $lb['x'] }}" y="{{ $lb['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">{{ $lb['text'] }}</text>
@endforeach
@endif
</svg>
