{{--
    The projected groups or first-round pairings of a tournament slide, from RotationKit::previewBoxes(): $p.
    Style per look: $panel (box fill, null = none), $rule (hairline under the title and between the two sides of a
    pairing, null = none), $titleFill, $seedFill, $nameFill, $openFill (an open spot and "+N more").
    Names are JetBrains Mono, fitted to the box; a seat without a name reads "Open spot".
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $pad = $p['pad'];
    $size = $p['size'];
    $seedW = 2 * 0.6 * $size;
@endphp
@foreach ($p['boxes'] as $bi => $box)
@php($nameX = $box['x'] + $pad + $seedW + 12)
@php($nameW = $box['w'] - 2 * $pad - $seedW - 12)
@if ($panel)<rect x="{{ $box['x'] }}" y="{{ $box['y'] }}" width="{{ $box['w'] }}" height="{{ $box['h'] }}" fill="{{ $panel }}"/>@endif
@if ($box['title'] !== null)
<text data-unit="box-{{ $bi }}-title" data-box="{{ $box['x'] }} {{ $box['y'] }} {{ $box['x'] + $box['w'] }} {{ $box['y'] + $box['h'] }}" x="{{ $box['x'] + $pad }}" y="{{ $box['titleY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $titleFill }}">{{ $box['title'] }}</text>
@if ($rule)<rect x="{{ $box['x'] + $pad }}" y="{{ round($box['titleY'] + $size * 0.5, 1) }}" width="{{ $box['w'] - 2 * $pad }}" height="1" fill="{{ $rule }}"/>@endif
@elseif ($rule && count($box['rows']) === 2)
<rect x="{{ $box['x'] + $pad }}" y="{{ $box['rows'][1]['y'] - $p['pitch'] * 0.68 }}" width="{{ $box['w'] - 2 * $pad }}" height="1" fill="{{ $rule }}"/>
@endif
@foreach ($box['rows'] as $ri => $row)
@php($n = K::name($row['name'], 'Open spot', $size, $nameW, false))
@if ($row['seed'] !== null)<text data-unit="box-{{ $bi }}-seed-{{ $ri }}" x="{{ $box['x'] + $pad + $seedW }}" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $seedFill }}" text-anchor="end">{{ $row['seed'] }}</text>@endif
<text data-unit="box-{{ $bi }}-name-{{ $ri }}" data-box="{{ $nameX - 1 }} {{ $box['y'] }} {{ $box['x'] + $box['w'] - $pad + 1 }} {{ $box['y'] + $box['h'] }}" x="{{ $nameX }}" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $row['name'] === null ? $openFill : $nameFill }}">{{ $n['text'] }}</text>
@endforeach
@if ($box['more'] > 0)<text data-unit="box-{{ $bi }}-more" data-box="{{ $nameX - 1 }} {{ $box['y'] }} {{ $box['x'] + $box['w'] - $pad + 1 }} {{ $box['y'] + $box['h'] }}" x="{{ $nameX }}" y="{{ $box['moreY'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $size }}" fill="{{ $openFill }}">+{{ $box['more'] }} more</text>@endif
@endforeach
