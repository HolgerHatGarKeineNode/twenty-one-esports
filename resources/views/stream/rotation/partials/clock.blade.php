{{--
    A clock from RotationKit::clock(): $c = [digits, size, x0, x1], baseline $y, colour $fill.
    One <g data-unit> so the pixel sonde measures the clock as one piece.
--}}
<g data-unit="clock" data-box="{{ $c['x0'] - 2 }} {{ $y - $c['size'] * 0.8 }} {{ $c['x1'] + 2 }} {{ $y + 2 }}" font-family="Unbounded" font-weight="800" font-size="{{ $c['size'] }}" fill="{{ $fill }}" text-anchor="middle">@foreach ($c['digits'] as $d)<text x="{{ $d['x'] }}" y="{{ $y }}">{{ $d['ch'] }}</text>@endforeach</g>
