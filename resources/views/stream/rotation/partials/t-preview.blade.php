{{--
    The projected groups or first-round pairings of a tournament slide, from RotationKit::previewBoxes(): $p, with
    RotationKit::previewFaces() of the same preview as $faces (per box, per row). A seat is its face and its name; an
    open seat is a dashed empty circle and "Open spot". No seed numbers: the order in a group says it, and "Who plays"
    lists seeds with Elo.
    Style per look: $panel (box fill, null = none), $rule (hairline under the title and between the two sides of a
    pairing, null = none), $titleFill, $nameFill, $openFill (an open spot and "+N more"), $ring (face ring),
    $pvShape ('round' | 'square'), $pvId (prefix for the face clip ids). Names are JetBrains Mono, fitted to the box.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $pad = $p['pad'];
    $boxW = $p['boxes'] === [] ? 0 : $p['boxes'][0]['w'];
    // Narrow boxes (four across) keep the face and the type a size smaller, so a name keeps about ten characters.
    $size = $boxW < 220 ? min($p['size'], 17.0) : $p['size'];
    $fd = floor(min($boxW < 220 ? 30 : 40, $p['pitch'] - 6));
@endphp
@foreach ($p['boxes'] as $bi => $box)
@php($nameX = $box['x'] + $pad + $fd + 10)
@php($nameW = $box['w'] - 2 * $pad - $fd - 10)
@if ($panel)<rect x="{{ $box['x'] }}" y="{{ $box['y'] }}" width="{{ $box['w'] }}" height="{{ $box['h'] }}" fill="{{ $panel }}"/>@endif
@if ($box['title'] !== null)
<text data-unit="box-{{ $bi }}-title" data-box="{{ $box['x'] }} {{ $box['y'] }} {{ $box['x'] + $box['w'] }} {{ $box['y'] + $box['h'] }}" x="{{ $box['x'] + $pad }}" y="{{ $box['titleY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $titleFill }}">{{ $box['title'] }}</text>
@if ($rule)<rect x="{{ $box['x'] + $pad }}" y="{{ round($box['titleY'] + $size * 0.5, 1) }}" width="{{ $box['w'] - 2 * $pad }}" height="1" fill="{{ $rule }}"/>@endif
@elseif ($rule && count($box['rows']) === 2)
<rect x="{{ $box['x'] + $pad }}" y="{{ $box['rows'][1]['y'] - $p['pitch'] * 0.68 }}" width="{{ $box['w'] - 2 * $pad }}" height="1" fill="{{ $rule }}"/>
@endif
@foreach ($box['rows'] as $ri => $row)
@php($n = K::name($row['name'], 'Open spot', $size, $nameW, false))
@php($top = round($row['y'] - $p['pitch'] * 0.68 + ($p['pitch'] - $fd) / 2, 1))
@include('stream.rotation.partials.face', ['face' => $faces[$bi][$ri] ?? null, 'x' => $box['x'] + $pad, 'y' => $top, 'd' => $fd, 'id' => ($pvId ?? 'pv').'-'.$bi.'-'.$ri,
    'fUnit' => 'box-'.$bi.'-face-'.$ri, 'fOpen' => $row['name'] === null ? 'open' : null, 'fRing' => $ring ?? null, 'fShape' => $pvShape ?? 'round', 'fOpenInk' => $openFill])
<text data-unit="box-{{ $bi }}-name-{{ $ri }}" data-box="{{ $nameX - 1 }} {{ $box['y'] }} {{ $box['x'] + $box['w'] - $pad + 1 }} {{ $box['y'] + $box['h'] }}" x="{{ $nameX }}" y="{{ round($top + $fd / 2 + $size * 0.36, 1) }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $row['name'] === null ? $openFill : $nameFill }}">{{ $n['text'] }}</text>
@endforeach
@if ($box['more'] > 0)<text data-unit="box-{{ $bi }}-more" data-box="{{ $nameX - 1 }} {{ $box['y'] }} {{ $box['x'] + $box['w'] - $pad + 1 }} {{ $box['y'] + $box['h'] }}" x="{{ $nameX }}" y="{{ $box['moreY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $openFill }}">+{{ $box['more'] }} more</text>@endif
@endforeach
