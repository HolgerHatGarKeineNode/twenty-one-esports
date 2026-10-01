{{--
    M1 · Broadcast desk · the mempool: the games of every game as cubes, the way /matches shows them. Channel frame
    over the brand backdrop; headline and one line on top, then one row of cubes: the left group up to a dashed
    divider, the right group after it. A cube is its game's colour and logo, the score big, how long ago or how far;
    under it the people, a winner crowned and big with who they beat. While no season runs (casual): played games
    left, running and scheduled ones right. While a season runs: the season's latest mined blocks left ("Block 812",
    an orange top face, the miners and the block's reward in sats), the running games right as the pending mempool.
    An empty side is an open cube that invites to play; nothing played and nothing running is a row of open cubes.

    Data contract:
      $mempool   array (MempoolSlides::all()): mode 'casual'|'season', rest, season, finished, running, blocks
      $stats     array: the ticker counts (b-chrome)
      $backdrop  ?string, optional: the brand backdrop, as in a1-match
    Positions and fitted strings come from MempoolLayout::layout(); this view only draws them.
--}}
@use('App\Support\TwentyOne\Stream\MempoolLayout', 'L')
@php
    $lay = L::layout(is_array($mempool ?? null) ? $mempool : []);
    $w = L::W;
    $h = L::H;
    $dd = L::D;
    $cy = L::CUBE_Y;
@endphp
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="1280" height="720" viewBox="0 0 1280 720">
@include('stream.rotation.partials.defs')
<rect width="1280" height="720" fill="#0A0A0B"/>
@include('stream.rotation.partials.backdrop', ['uri' => $backdrop ?? null, 'bdDim' => 0.8])
@include('stream.rotation.partials.b-chrome', ['stats' => $stats ?? [], 'bugNote' => $lay['bugNote']])
<text data-unit="headline" data-box="39 100 1241 164" x="40" y="150" font-family="Unbounded" font-weight="800" font-size="52" fill="#FFFFFF">{{ $lay['headline'] }}</text>
<text data-unit="lead" data-box="39 172 1241 200" x="40" y="194" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#ADADB0">{{ $lay['lead'] }}</text>
@foreach ($lay['labels'] as $i => $label)
<text data-unit="group-{{ $i }}" data-box="{{ $label['x'] - 1 }} 206 {{ $label['x'] + 200 }} 230" x="{{ $label['x'] }}" y="224" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#ADADB0">{{ $label['text'] }}</text>
@endforeach
@if ($lay['divider'] !== null)
<path d="M{{ $lay['divider'] }} 214V580" stroke="#55555C" stroke-width="2" stroke-dasharray="6 6"/>
@endif
@foreach ($lay['cubes'] as $cube)
@php
    $x = $cube['x'];
    $c = $cube['c'];
    $id = $cube['id'];
    $top = "M{$x} {$cy}L".($x + $dd).' '.($cy - $dd).'H'.($x + $w + $dd)."L".($x + $w)." {$cy}Z";
    $side = 'M'.($x + $w)." {$cy}L".($x + $w + $dd).' '.($cy - $dd).'V'.($cy + $h - $dd).'L'.($x + $w).' '.($cy + $h).'Z';
    $fillH = round($h * $cube['level'] / 100, 1);
@endphp
<g data-unit="cube-{{ $id }}">
@if ($cube['state'] === 'fin' || $cube['state'] === 'mined')
<linearGradient id="{{ $id }}-front" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $c[0] }}"/><stop offset="1" stop-color="{{ $c[1] }}"/></linearGradient>
<linearGradient id="{{ $id }}-side" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $cube['state'] === 'mined' ? '#B9640A' : $c[3] }}"/><stop offset="1" stop-color="{{ $cube['state'] === 'mined' ? '#7A430A' : $c[4] }}"/></linearGradient>
<path d="{{ $side }}" fill="url(#{{ $id }}-side)"/>
<path d="{{ $top }}" fill="{{ $cube['state'] === 'mined' ? '#F7931A' : $c[2] }}"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="url(#{{ $id }}-front)"/>
@elseif ($cube['state'] === 'live')
<linearGradient id="{{ $id }}-front" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $c[0] }}"/><stop offset="1" stop-color="{{ $c[1] }}"/></linearGradient>
<path d="{{ $side }}" fill="{{ $c[3] }}" fill-opacity="0.55"/>
<path d="{{ $top }}" fill="{{ $c[0] }}" fill-opacity="0.45"/>
<rect x="{{ $x }}" y="{{ $cy }}" width="{{ $w }}" height="{{ $h }}" fill="#141417"/>
@if ($fillH > 0)<rect x="{{ $x }}" y="{{ $cy + $h - $fillH }}" width="{{ $w }}" height="{{ $fillH }}" fill="url(#{{ $id }}-front)" fill-opacity="0.3"/>@endif
<rect x="{{ $x + 1 }}" y="{{ $cy + 1 }}" width="{{ $w - 2 }}" height="{{ $h - 2 }}" fill="none" stroke="{{ $c[0] }}" stroke-width="2"/>
@else
@php($ink = $cube['state'] === 'next' ? $c[0] : ($cube['ghost'] === 'ghost-block' ? '#F7931A' : '#6B6B72'))
<path d="{{ $side }}" fill="none" stroke="{{ $ink }}" stroke-width="2" stroke-dasharray="6 4" stroke-opacity="0.7"/>
<path d="{{ $top }}" fill="none" stroke="{{ $ink }}" stroke-width="2" stroke-dasharray="6 4" stroke-opacity="0.7"/>
<rect x="{{ $x + 1 }}" y="{{ $cy + 1 }}" width="{{ $w - 2 }}" height="{{ $h - 2 }}" fill="#141417" fill-opacity="0.85" stroke="{{ $ink }}" stroke-width="2" stroke-dasharray="6 4"/>
@if ($cube['ghost'] === 'ghost-game' || $cube['ghost'] === 'ghost-block')
<path d="M{{ $x + $w / 2 - 22 }} {{ $cy + $h / 2 }}H{{ $x + $w / 2 + 22 }}M{{ $x + $w / 2 }} {{ $cy + $h / 2 - 22 }}V{{ $cy + $h / 2 + 22 }}" stroke="{{ $ink }}" stroke-width="6" stroke-linecap="round"/>
@endif
@endif
</g>
@if ($cube['link'] !== null)
{{-- The chain: two links from this block to the next one. --}}
<rect x="{{ $cube['link']['x'] }}" y="{{ $cy + 44 }}" width="{{ $cube['link']['w'] }}" height="8" fill="#F7931A"/>
<rect x="{{ $cube['link']['x'] }}" y="{{ $cy + 100 }}" width="{{ $cube['link']['w'] }}" height="8" fill="#F7931A"/>
@endif
@if ($cube['state'] !== 'ghost')
<g transform="translate({{ $x + 14 }} {{ $cy + 14 }}) scale(0.9167)" fill="{{ $cube['ink'] }}" stroke="{{ $cube['ink'] }}">{!! L::logo($cube['icon']) !!}</g>
<text data-unit="mode-{{ $id }}" data-box="{{ $x + 45 }} {{ $cy + 14 }} {{ $x + $w - 12 }} {{ $cy + 40 }}" x="{{ $x + 46 }}" y="{{ $cy + 33 }}" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="{{ $cube['ink'] }}">{{ $cube['mode'] }}</text>
<text data-unit="score-{{ $id }}" data-box="{{ $x + 15 }} {{ $cy + 46 }} {{ $x + $w - 12 }} {{ $cy + 108 }}" x="{{ $x + 16 }}" y="{{ $cy + 96 }}" font-family="Unbounded" font-weight="800" font-size="{{ $cube['scoreSize'] }}" fill="{{ $cube['ink'] }}">{{ $cube['score'] }}</text>
@if ($cube['when'] !== '')<text data-unit="when-{{ $id }}" data-box="{{ $x + 15 }} {{ $cy + 118 }} {{ $x + ($cube['casual'] ? $w - 80 : $w - 12) }} {{ $cy + 144 }}" x="{{ $x + 16 }}" y="{{ $cy + 137 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $cube['inkSoft'] }}">{{ $cube['when'] }}</text>@endif
@if ($cube['casual'])
<g data-unit="casual-{{ $id }}" data-box="{{ $x + $w - 76 }} {{ $cy + 116 }} {{ $x + $w - 12 }} {{ $cy + 146 }}">
<rect x="{{ $x + $w - 74 }}" y="{{ $cy + 118 }}" width="60" height="26" rx="4" fill="none" stroke="{{ $cube['inkSoft'] }}" stroke-width="1.5"/>
<text x="{{ $x + $w - 44 }}" y="{{ $cy + 136 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="{{ $cube['inkSoft'] }}" text-anchor="middle">casual</text>
</g>
@endif
@endif
@foreach ($cube['faces'] as $face)
@include('stream.rotation.partials.face', ['face' => $face['face'], 'x' => $face['x'], 'y' => $face['y'], 'd' => $face['d'], 'id' => $face['id'], 'fUnit' => 'face-'.$face['id'], 'fRing' => $face['ring'], 'fCrown' => $face['crown'], 'fGround' => '#2A2A30', 'fTagFill' => '#2A2A30', 'fTagInk' => '#FFFFFF', 'fOpen' => $face['open'] ?? null, 'fOpenInk' => $face['openInk'] ?? null])
@endforeach
@if ($cube['vs'])<text x="{{ $cube['vs']['x'] }}" y="{{ $cube['vs']['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0" text-anchor="middle">vs</text>@endif
@if ($cube['line1'])<text data-unit="line1-{{ $id }}" data-box="{{ $x - 1 }} {{ L::LINE1_Y - 26 }} {{ $x + L::COL }} {{ L::LINE1_Y + 8 }}" x="{{ $x }}" y="{{ L::LINE1_Y }}" font-family="{{ $cube['line1']['font'] }}" font-weight="800" font-size="{{ $cube['line1']['size'] }}" fill="#FFFFFF">{{ $cube['line1']['text'] }}</text>@endif
@if ($cube['line2'])<text data-unit="line2-{{ $id }}" data-box="{{ $x - 1 }} {{ L::LINE2_Y - 20 }} {{ $x + L::COL }} {{ L::LINE2_Y + 6 }}" x="{{ $x }}" y="{{ L::LINE2_Y }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $cube['line2']['size'] }}" fill="{{ $cube['line2']['ink'] }}">{{ $cube['line2']['text'] }}</text>@endif
@foreach ($cube['extra'] as $j => $line)
<text data-unit="line{{ $j + 3 }}-{{ $id }}" data-box="{{ $x - 1 }} {{ $line['y'] - 16 }} {{ $x + L::COL }} {{ $line['y'] + 5 }}" x="{{ $x }}" y="{{ $line['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="#ADADB0">{{ $line['text'] }}</text>
@endforeach
@endforeach
@foreach ($lay['legend'] as $i => $key)
<rect x="{{ $key['x'] }}" y="{{ L::FOOT_Y - 14 }}" width="16" height="16" rx="3" fill="{{ $key['colour'] }}"/>
<text data-unit="legend-{{ $i }}" data-box="{{ $key['textX'] - 1 }} {{ L::FOOT_Y - 17 }} {{ $key['textX'] + mb_strlen($key['name']) * 0.6 * $key['size'] + 2 }} {{ L::FOOT_Y + 6 }}" x="{{ $key['textX'] }}" y="{{ L::FOOT_Y }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $key['size'] }}" fill="#ADADB0">{{ $key['name'] }}</text>
@endforeach
<text data-unit="cta" data-box="39 {{ L::FOOT_Y - 20 }} {{ $lay['legend'][0]['x'] ?? 1241 }} {{ L::FOOT_Y + 8 }}" x="40" y="{{ L::FOOT_Y }}" font-family="JetBrains Mono" font-weight="700" font-size="22" fill="#FFFFFF">{{ $lay['cta'] }}</text>
</svg>
