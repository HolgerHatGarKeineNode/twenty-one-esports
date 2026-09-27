{{--
    The scene's backdrop: $uri (the contract's 'backdrop', an already blurred and darkened 640x360 JPEG; checked here
    with RotationKit::backdropUri, anything else draws nothing) full-bleed, then a flat scrim of the ground colour at
    $bdDim (0..1) so text on it keeps its contrast (the pixel sonde measures every text against it). Optional $bdX,
    $bdY, $bdW, $bdH (default the whole frame).
    Render cost, measured with rsvg-convert on the heaviest slide (tc2, 2026-09-27): smooth upscaling of the image
    cost ~30 ms and a radial vignette ~27 ms a frame; the image is blurred already, so it is scaled as fast as rsvg
    can (optimizeSpeed) and the scrim is one flat rect.
--}}
@use('App\Support\TwentyOne\Stream\RotationKit', 'K')
@php($bdUri = K::backdropUri($uri ?? null))
@if ($bdUri)
<image image-rendering="optimizeSpeed" x="{{ $bdX ?? 0 }}" y="{{ $bdY ?? 0 }}" width="{{ $bdW ?? 1280 }}" height="{{ $bdH ?? 720 }}" preserveAspectRatio="xMidYMid slice" xlink:href="{{ $bdUri }}"/>
<rect x="{{ $bdX ?? 0 }}" y="{{ $bdY ?? 0 }}" width="{{ $bdW ?? 1280 }}" height="{{ $bdH ?? 720 }}" fill="#0A0A0B" fill-opacity="{{ $bdDim ?? 0.7 }}"/>
@endif
