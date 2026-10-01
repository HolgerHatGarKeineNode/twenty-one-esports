{{--
    A game's mark on its own slides: the game's cover (the image of its tiles and cards) sharp at 16:9, edged in
    $gmEdge, at $gmX/$gmY, $gmW wide. Without a cover (no file) a drawn stand-in of Blockfill's well: a glass well with
    its bottom rows mined in orange, so the slide still shows the game. One <g data-unit> for the pixel sonde.

    Params: $gmCover (data URI or null; RotationKit::coverUri() filters it), $gmX, $gmY, $gmW, $gmEdge (colour).
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php
    $gmUri = K::coverUri($gmCover ?? null);
    $gmH = round($gmW * 9 / 16);
    $gmIn = 4;
    $gmIx = $gmX + $gmIn;
    $gmIy = $gmY + $gmIn;
    $gmIw = $gmW - 2 * $gmIn;
    $gmIh = $gmH - 2 * $gmIn;
@endphp
<g data-unit="game-mark" data-box="{{ $gmX }} {{ $gmY }} {{ $gmX + $gmW }} {{ $gmY + $gmH }}">
<rect x="{{ $gmX }}" y="{{ $gmY }}" width="{{ $gmW }}" height="{{ $gmH }}" fill="{{ $gmEdge }}"/>
<rect x="{{ $gmIx }}" y="{{ $gmIy }}" width="{{ $gmIw }}" height="{{ $gmIh }}" fill="#0A0A0B"/>
@if ($gmUri)
<image x="{{ $gmIx }}" y="{{ $gmIy }}" width="{{ $gmIw }}" height="{{ $gmIh }}" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $gmUri }}"/>
@else
@php($gmCell = floor(($gmIh - 16) / 8))
@php($gmWx = $gmIx + round(($gmIw - 6 * $gmCell) / 2))
@php($gmWy = $gmIy + 8)
<rect x="{{ $gmWx - 2 }}" y="{{ $gmWy }}" width="{{ 6 * $gmCell + 4 }}" height="{{ 8 * $gmCell + 2 }}" fill="none" stroke="#F7931A" stroke-width="2"/>
@foreach (range(0, 17) as $gmI)
<rect x="{{ $gmWx + ($gmI % 6) * $gmCell + 1 }}" y="{{ $gmWy + (7 - intdiv($gmI, 6)) * $gmCell + 1 }}" width="{{ $gmCell - 2 }}" height="{{ $gmCell - 2 }}" fill="#F7931A"/>
@endforeach
@endif
</g>
