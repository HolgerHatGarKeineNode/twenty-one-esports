{{--
    A live bracket from RotationKit::bracketLayout(): $L. Each round a column with its label on top (the current round in
    $accent, the others in $muted), each match a box of two rows: face, name, score. A live match is the one box with an
    $accent frame and a bar on its left; the winner of a played match keeps $nameFill and gets the score on an $accent
    chip, the loser and a side still to come are $muted. A voided match keeps its place dimmed (half opacity, names
    $muted) with a small "voided" mark where a score would be: no score, no winner. Links run from each shown feeder to its box ($rule, $accent
    into a live box).
    Style per look: $panel (box fill), $rule, $accent, $nameFill, $muted, $chipInk (score on the chip), $bShape
    ('round' | 'square' faces), $bId (prefix for the face clip ids).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@foreach ($L['links'] as $link)
<path d="{{ $link['d'] }}" fill="none" stroke="{{ $link['live'] ? $accent : $rule }}" stroke-width="2"/>
@endforeach
@foreach ($L['columns'] as $ci => $col)
<text data-unit="col-{{ $ci }}" data-box="{{ $col['x'] - 1 }} {{ $L['top'] }} {{ $col['x'] + $col['w'] + 1 }} {{ $L['top'] + 26 }}" x="{{ $col['x'] }}" y="{{ $L['top'] + 18 }}" font-family="JetBrains Mono" font-weight="700" font-size="16" fill="{{ $col['current'] ? $accent : $muted }}">{{ K::fit($col['label'], K::MONO, 16, $col['w']) }}</text>
@foreach ($col['boxes'] as $bi => $box)
@php($live = $box['state'] === 'live')
@php($void = $box['state'] === 'void')
@if ($void)<g opacity="0.55">@endif
<rect x="{{ $box['x'] }}" y="{{ $box['y'] }}" width="{{ $box['w'] }}" height="{{ $box['h'] }}" fill="{{ $panel }}"@if ($live) stroke="{{ $accent }}" stroke-width="2"@endif/>
@if ($live)<rect x="{{ $box['x'] }}" y="{{ $box['y'] }}" width="5" height="{{ $box['h'] }}" fill="{{ $accent }}"/>@endif
<rect x="{{ $box['x'] + $L['pad'] }}" y="{{ round($box['y'] + $L['pad'] + $L['pitch'], 1) }}" width="{{ $box['w'] - 2 * $L['pad'] }}" height="1" fill="{{ $rule }}"/>
@foreach ($box['rows'] as $ri => $row)
@php($faceX = $box['x'] + $L['pad'] + ($live ? 8 : 4))
@php($nameX = $faceX + $L['fd'] + 8)
@php($chipW = $row['score'] === null ? ($void && $ri === 0 ? K::width('voided', K::MONO, 14) + 8 : 0) : max(26, K::width($row['score'], K::MONO, $L['size']) + 12))
@php($lost = $void || ! $row['known'] || ($box['state'] === 'done' && ! $row['won']))
@if ($row['known'])
@include('stream.rotation.partials.face', ['face' => $row['face'], 'x' => $faceX, 'y' => $row['top'], 'd' => $L['fd'], 'id' => $bId.'-'.$ci.'-'.$bi.'-'.$ri, 'fUnit' => 'b-'.$ci.'-'.$bi.'-face-'.$ri, 'fShape' => $bShape ?? 'round', 'fRing' => $row['won'] ? $accent : null])
@endif
<text data-unit="b-{{ $ci }}-{{ $bi }}-name-{{ $ri }}" data-box="{{ ($row['known'] ? $nameX : $faceX) - 1 }} {{ $box['y'] }} {{ $box['x'] + $box['w'] - $L['pad'] - $chipW - 3 }} {{ $box['y'] + $box['h'] }}" x="{{ $row['known'] ? $nameX : $faceX }}" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $L['size'] }}" fill="{{ $lost ? $muted : $nameFill }}">{{ K::fit($row['name'] === '' ? 'To be decided' : $row['name'], K::MONO, $L['size'], $box['x'] + $box['w'] - $L['pad'] - $chipW - 6 - ($row['known'] ? $nameX : $faceX)) }}</text>
@if ($row['score'] !== null)
@if ($row['won'])<rect x="{{ $box['x'] + $box['w'] - $L['pad'] - $chipW }}" y="{{ $row['top'] }}" width="{{ $chipW }}" height="{{ $L['fd'] }}" fill="{{ $accent }}"/>@endif
<text data-unit="b-{{ $ci }}-{{ $bi }}-score-{{ $ri }}" x="{{ $box['x'] + $box['w'] - $L['pad'] - $chipW / 2 }}" y="{{ $row['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $L['size'] }}" fill="{{ $row['won'] ? $chipInk : $muted }}" text-anchor="middle">{{ $row['score'] }}</text>
@endif
@endforeach
@if ($void)<text data-unit="b-{{ $ci }}-{{ $bi }}-void" data-box="{{ $box['x'] + $box['w'] - $L['pad'] - 6 - K::width('voided', K::MONO, 14) }} {{ $box['y'] }} {{ $box['x'] + $box['w'] }} {{ $box['y'] + $L['pad'] + $L['pitch'] }}" x="{{ $box['x'] + $box['w'] - $L['pad'] - 4 }}" y="{{ $box['rows'][0]['y'] ?? $box['y'] + 20 }}" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="{{ $muted }}" text-anchor="end">voided</text></g>@endif
@endforeach
@endforeach
