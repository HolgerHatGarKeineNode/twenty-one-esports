{{--
    A QR code on a white square with a quiet zone: $x, $y, $size (outer px), $qr = the code as SVG, trusted
    (resources/stream/qr/*.svg): either a whole file as `qrencode -t SVG -m 0` writes it (its viewBox is used), or
    inner markup drawn in a 0..$modules square. Empty $qr draws nothing, so a missing file never shows a broken box.
    The quiet zone is 4 modules on each side, as the QR standard asks.
--}}
@php
    $qrMarkup = (string) ($qr ?? '');
    $qrSide = (float) ($modules ?? 0);
    if (preg_match('/<svg\b[^>]*\bviewBox="0 0 ([\d.]+) [\d.]+"[^>]*>(.*)<\/svg>/s', $qrMarkup, $m)) {
        $qrSide = (float) $m[1];
        $qrMarkup = $m[2];
    }
    $qrMarkup = preg_replace('/<\?xml.*?\?>|<!--.*?-->|\s(id)="[^"]*"/s', '', $qrMarkup) ?? '';
@endphp
@if (trim($qrMarkup) !== '' && $qrSide > 0)
@php($pad = $size * 4 / ($qrSide + 8))
<rect x="{{ $x }}" y="{{ $y }}" width="{{ $size }}" height="{{ $size }}" fill="#FFFFFF"/>
<svg x="{{ $x + $pad }}" y="{{ $y + $pad }}" width="{{ $size - 2 * $pad }}" height="{{ $size - 2 * $pad }}" viewBox="0 0 {{ $qrSide }} {{ $qrSide }}" shape-rendering="crispEdges">{!! $qrMarkup !!}</svg>
@endif
