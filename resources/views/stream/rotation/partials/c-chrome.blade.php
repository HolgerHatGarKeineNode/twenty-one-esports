{{--
    Direction C (terminal ticker): header line (mark, wordmark, $section in grey) and the stats bar at the bottom
    (rule at y 625, cells of equal width over x 40..880, the site URL in the last 360 px). Counts come from $stats;
    a missing one drops its cell and the others share the width. Optional $viewers (int|null, from the scene): the
    viewer badge on the header line, right-aligned to x 1024.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php($cells = K::statCells($stats ?? []))
@php($cellW = $cells === [] ? 0 : 840 / count($cells))
<use href="#mark" xlink:href="#mark" x="40" y="32" width="32" height="32"/>
<text x="84" y="54" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#FFFFFF">TWENTY ONE ESPORTS</text>
<text x="290" y="54" font-family="JetBrains Mono" font-weight="700" font-size="18" fill="#8B8B90">{{ $section }}</text>
@php($vb = K::viewerBadge($viewers ?? null, 1024, 54, K::MONO, 18, 18))
@if ($vb)@include('stream.rotation.partials.viewers', ['vb' => $vb, 'eyeInk' => '#F7931A', 'countInk' => '#FFFFFF', 'wordInk' => '#8B8B90'])@endif
<rect x="40" y="625" width="1200" height="1" fill="#2A2A30"/>
@foreach ($cells as $i => [$label, $value])
@php($cx = 40 + $i * $cellW + ($i > 0 ? 16 : 0))
@if ($i > 0)<rect x="{{ 40 + $i * $cellW }}" y="626" width="1" height="66" fill="#2A2A30"/>@endif
<text data-unit="stat-label-{{ $i }}" x="{{ $cx }}" y="650" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#8B8B90">{{ $label }}</text>
<text data-unit="stat-value-{{ $i }}" data-box="{{ $cx - 1 }} 656 {{ 40 + ($i + 1) * $cellW - 8 }} 692" x="{{ $cx }}" y="684" font-family="JetBrains Mono" font-weight="700" font-size="28" fill="#F7931A">{{ $value }}</text>
@endforeach
<rect x="880" y="626" width="1" height="66" fill="#2A2A30"/>
<text x="896" y="650" font-family="JetBrains Mono" font-weight="700" font-size="14" fill="#8B8B90">play</text>
<text data-unit="stat-url" data-box="895 664 1240 690" x="896" y="682" font-family="JetBrains Mono" font-weight="700" font-size="20" fill="#FFFFFF">esports.einundzwanzig.space</text>
