{{--
    H1 · Hyperbitcoinization live (plan "Hyperbitcoinization", P6). A running match: the world map with who owns what
    (territories in their owner's faction colour, the central banks as gold pins) on the left, the chronicle of the
    latest battles under it; on the right the tension card (on the generated header plate, with the clash icon) and one
    card per faction: its portrait, the player's face, name, banks and territories, who moves, who leads, who
    is out. Without the match (it just ended, or the game is off): the plate, the title and one line. The client's LIVE
    badge sits over x >= 1040, y < 112; nothing is drawn there.

    Data contract (HyperScene::data()):
      $hyper     array{id: int, over: bool, round: int, limit: int, mode: string, phase: string,
                   seats: list<array{seat: int, name: string, faction: string, color: string, portrait: ?string,
                     avatar: ?string, territories: int, banks: int, units: int, out: bool, bot: bool, toMove: bool,
                     leader: bool}>,
                   owners: array<string, int|null> (territory id => seat), colors: array<int, string> (seat => colour),
                   chronicle: list<array{key: string, seat: int, text: string, kind: string}> (newest first),
                   tension: array{tense: bool, reason: ?string, caption: string, ...}}|null
      $map       array{width: int, height: int, neutral: string, territories: array<string, array{d: string, cx: float,
                   cy: float, bank: bool, name: string}>} (resources/stream/hyper/map.json, 832 x 368)
      $art       array{plate: ?string, ribbon: ?string, clash: ?string} data URIs (resources/stream/hyper)
      $stats     array (unused here)
      $backdrop  ?string, the game's blurred cover, under the plate when the plate is missing
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $live = is_array($hyper ?? null) ? $hyper : null;
    $mapData = is_array($map ?? null) ? $map : ['width' => 832, 'height' => 368, 'neutral' => '', 'territories' => []];
    $plate = K::coverUri($art['plate'] ?? null);
    $ribbon = K::coverUri($art['ribbon'] ?? null);
    $clash = K::coverUri($art['clash'] ?? null);
    $mx = 24;
    $my = 100;
    $cards = [];
    $rows = [];
    if ($live) {
        $seatsShown = array_slice($live['seats'] ?? [], 0, 6);
        $n = max(1, count($seatsShown));
        $cardH = $n <= 4 ? 84 : 66;
        $gap = 8;
        $y = 240;
        foreach ($seatsShown as $i => $s) {
            $d = $cardH - 16;
            $nameX = 892 + 6 + $d + 14;
            $cards[] = [
                'y' => $y, 'h' => $cardH, 'd' => $d, 'nameX' => $nameX,
                'color' => K::colour($s['color'] ?? null, '#A1A1AA'),
                'portrait' => K::coverUri($s['portrait'] ?? null),
                'avatar' => $s['avatar'] ?? null,
                // The name ends before the LEAD / OUT chip (x 1196).
                'name' => K::fit(K::clean($s['name'] ?? ''), K::DISPLAY, $cardH > 70 ? 19 : 17, 1188 - $nameX),
                'nameSize' => $cardH > 70 ? 19 : 17,
                // The faction is the portrait and the colour; the line counts what the faction holds.
                'line' => K::fit(($s['banks'] ?? 0).' '.(($s['banks'] ?? 0) === 1 ? 'bank' : 'banks').' · '.($s['territories'] ?? 0).' '.(($s['territories'] ?? 0) === 1 ? 'territory' : 'territories'), K::MONO, 15, 1244 - $nameX),
                'out' => (bool) ($s['out'] ?? false),
                'toMove' => (bool) ($s['toMove'] ?? false),
                'leader' => (bool) ($s['leader'] ?? false),
            ];
            $y += $cardH + $gap;
        }
        foreach (array_slice($live['chronicle'] ?? [], 0, 4) as $i => $row) {
            $rows[] = ['y' => 548 + $i * 44, 'color' => K::colour($live['colors'][$row['seat'] ?? -1] ?? null, '#A1A1AA'), 'text' => K::fit(K::clean($row['text'] ?? ''), K::MONO, 19, 792), 'kind' => $row['kind'] ?? 'conquest'];
        }
        $tension = $live['tension'] ?? [];
        $caption = K::wrap($tension['caption'] ?? '', K::MONO, 17, 262, 2);
        $phase = ['buy' => 'recruiting', 'attack' => 'attacking', 'fortify' => 'fortifying'][$live['phase'] ?? ''] ?? '';
        $sub = K::fit(($live['mode'] ?? 'Live').' · Round '.($live['round'] ?? 1).(($live['limit'] ?? 0) > 0 ? ' of '.$live['limit'] : '').($live['over'] ?? false ? ' · match over' : ($phase !== '' ? ' · '.$phase : '')), K::MONO, 18, 640);
    }
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#070B16"/>
@if ($plate)
<image data-unit="plate" image-rendering="optimizeSpeed" x="0" y="0" width="1280" height="720" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $plate }}"/>
<rect width="1280" height="720" fill="#070B16" fill-opacity="{{ $live ? 0.62 : 0.5 }}"/>
@else
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.72])
@endif
<text data-unit="title" data-box="31 22 700 60" x="32" y="54" font-family="Unbounded" font-weight="800" font-size="30" fill="#F7931A">HYPERBITCOINIZATION</text>
@if ($live)
<text data-unit="sub" data-box="31 66 680 88" x="32" y="84" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">{{ $sub }}</text>
{{-- The world map: neutral land, then every territory in its owner's colour, then the central banks. --}}
<g data-unit="map" data-box="{{ $mx }} {{ $my }} {{ $mx + $mapData['width'] }} {{ $my + $mapData['height'] }}">
<rect x="{{ $mx - 4 }}" y="{{ $my - 4 }}" width="{{ $mapData['width'] + 8 }}" height="{{ $mapData['height'] + 8 }}" fill="#0B1428" fill-opacity="0.82" stroke="#F7931A" stroke-opacity="0.55" stroke-width="1.5"/>
<g transform="translate({{ $mx }} {{ $my }})">
@if (($mapData['neutral'] ?? '') !== '')<path d="{{ $mapData['neutral'] }}" fill="#1E2638"/>@endif
@foreach ($mapData['territories'] as $tid => $terr)
@php($owner = $live['owners'][$tid] ?? null)
<path data-territory="{{ $tid }}" data-owner="{{ $owner ?? '' }}" d="{{ $terr['d'] }}" fill="{{ $owner === null ? '#3A4258' : K::colour($live['colors'][$owner] ?? null, '#A1A1AA') }}" fill-opacity="{{ $owner === null ? 0.9 : 0.86 }}" stroke="#070B16" stroke-width="0.7"/>
@endforeach
@foreach ($mapData['territories'] as $tid => $terr)
@if ($terr['bank'] ?? false)
@php($owner = $live['owners'][$tid] ?? null)
<circle data-bank="{{ $tid }}" cx="{{ $terr['cx'] }}" cy="{{ $terr['cy'] }}" r="6" fill="#FFD27A" stroke="{{ $owner === null ? '#070B16' : '#FFFFFF' }}" stroke-width="2"/>
@endif
@endforeach
</g>
</g>
{{-- The chronicle of the latest battles, newest first. --}}
<text data-unit="chronicle-head" data-box="23 494 400 514" x="24" y="510" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#F7931A">LATEST BATTLES</text>
@forelse ($rows as $i => $row)
<rect x="24" y="{{ $row['y'] - 24 }}" width="832" height="36" fill="#0B1428" fill-opacity="{{ $i === 0 ? 0.92 : 0.7 }}"/>
<rect x="24" y="{{ $row['y'] - 24 }}" width="6" height="36" fill="{{ $row['color'] }}"/>
<text data-unit="battle-{{ $i }}" data-box="43 {{ $row['y'] - 20 }} 845 {{ $row['y'] + 6 }}" x="44" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="19" fill="{{ $row['kind'] === 'bank' || $row['kind'] === 'win' ? '#FFD27A' : '#FFFFFF' }}">{{ $row['text'] }}</text>
@empty
<text data-unit="battle-none" data-box="23 530 845 554" x="24" y="548" font-family="JetBrains Mono" font-weight="700" font-size="19" fill="#A1A1AA">No battle yet. The armies are still being raised.</text>
@endforelse
{{-- Tension: the generated header plate, the clash icon, how tense and why. --}}
<g data-unit="tension" data-box="880 124 1256 226">
@if ($ribbon)<image x="880" y="124" width="376" height="101" preserveAspectRatio="none" xlink:href="{{ $ribbon }}"/>@else<rect x="880" y="124" width="376" height="101" rx="8" fill="#151B2C" stroke="#F7931A" stroke-width="1.5"/>@endif
@if ($clash)<image x="896" y="140" width="68" height="68" xlink:href="{{ $clash }}"/>@endif
<text x="976" y="157" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="{{ ($tension['tense'] ?? false) ? '#FF6B4A' : '#F7931A' }}">{{ ($tension['tense'] ?? false) ? 'TENSION: HIGH' : 'TENSION: STEADY' }}</text>
@foreach ($caption as $i => $line)
<text data-unit="caption-{{ $i }}" data-box="975 {{ 166 + $i * 22 }} 1240 {{ 188 + $i * 22 }}" x="976" y="{{ 182 + $i * 22 }}" font-family="JetBrains Mono" font-weight="700" font-size="17" fill="#FFFFFF">{{ $line }}</text>
@endforeach
</g>
{{-- One card per faction. --}}
@foreach ($cards as $i => $c)
<g data-unit="seat-{{ $i }}" data-box="880 {{ $c['y'] }} 1256 {{ $c['y'] + $c['h'] }}" @if ($c['out']) opacity="0.45" @endif>
<rect x="880" y="{{ $c['y'] }}" width="376" height="{{ $c['h'] }}" rx="6" fill="#0B1428" fill-opacity="0.9" stroke="{{ $c['toMove'] ? '#F7931A' : '#1F2A44' }}" stroke-width="{{ $c['toMove'] ? 2.5 : 1 }}"/>
<rect x="880" y="{{ $c['y'] }}" width="6" height="{{ $c['h'] }}" fill="{{ $c['color'] }}"/>
<clipPath id="hp-{{ $i }}"><rect x="898" y="{{ $c['y'] + 8 }}" width="{{ $c['d'] }}" height="{{ $c['d'] }}" rx="6"/></clipPath>
@if ($c['portrait'])<image x="898" y="{{ $c['y'] + 8 }}" width="{{ $c['d'] }}" height="{{ $c['d'] }}" preserveAspectRatio="xMidYMid slice" clip-path="url(#hp-{{ $i }})" xlink:href="{{ $c['portrait'] }}"/>@else<rect x="898" y="{{ $c['y'] + 8 }}" width="{{ $c['d'] }}" height="{{ $c['d'] }}" rx="6" fill="{{ $c['color'] }}"/>@endif
@if ($c['avatar'])
@include('stream.rotation.partials.face', ['face' => ['uri' => $c['avatar'], 'tag' => null], 'x' => 898 + $c['d'] - 22, 'y' => $c['y'] + $c['d'] - 14, 'd' => 28, 'id' => 'hs'.$i, 'fUnit' => 'seat-face-'.$i, 'fRing' => $c['color']])
@endif
<text data-unit="seat-name-{{ $i }}" x="{{ $c['nameX'] }}" y="{{ $c['y'] + ($c['h'] > 70 ? 36 : 30) }}" font-family="Unbounded" font-weight="800" font-size="{{ $c['nameSize'] }}" fill="#FFFFFF">{{ $c['name'] }}</text>
<text data-unit="seat-line-{{ $i }}" x="{{ $c['nameX'] }}" y="{{ $c['y'] + ($c['h'] > 70 ? 62 : 52) }}" font-family="JetBrains Mono" font-weight="700" font-size="15" fill="{{ $c['color'] }}">{{ $c['line'] }}</text>
@if ($c['leader'] && ! $c['out'])<rect x="1196" y="{{ $c['y'] + 6 }}" width="54" height="20" rx="4" fill="#F7931A"/><text x="1223" y="{{ $c['y'] + 21 }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#17120A">LEAD</text>@endif
@if ($c['out'])<rect x="1196" y="{{ $c['y'] + 6 }}" width="54" height="20" rx="4" fill="#3A4258"/><text x="1223" y="{{ $c['y'] + 21 }}" text-anchor="middle" font-family="JetBrains Mono" font-weight="700" font-size="13" fill="#FFFFFF">OUT</text>@endif
@if ($c['toMove'] && ! $c['out'])<polygon data-unit="to-move" points="{{ 1244 }},{{ $c['y'] + $c['h'] - 22 }} {{ 1244 }},{{ $c['y'] + $c['h'] - 8 }} {{ 1234 }},{{ $c['y'] + $c['h'] - 15 }}" fill="#F7931A"/>@endif
</g>
@endforeach
@else
<text data-unit="none" data-box="31 300 1100 340" x="32" y="330" font-family="Unbounded" font-weight="800" font-size="28" fill="#FFFFFF">No match on right now.</text>
<text data-unit="none-sub" data-box="31 356 1100 380" x="32" y="374" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#F7931A">Risk with currency spaces: Bitcoiners against the central banks.</text>
@endif
<text data-unit="url" data-box="879 690 1256 712" x="1256" y="706" text-anchor="end" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#FFFFFF">esports.einundzwanzig.space</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 54, K::DISPLAY, 22, 16))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#FFFFFF', 'countInk' => '#FFFFFF', 'wordInk' => '#A1A1AA'])@endif
</svg>
