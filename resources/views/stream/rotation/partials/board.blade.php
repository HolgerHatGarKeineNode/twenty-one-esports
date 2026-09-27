{{--
    One chess board from RotationKit::board(): $b = [x, y, sq, size, squares, pieces].
    Optional: $frame (px of the wooden rim around it, default 8; 0 = none), $frameFill (default #3A2C14).
--}}
@php($rim = $frame ?? 8)
@if ($rim > 0)<rect x="{{ $b['x'] - $rim }}" y="{{ $b['y'] - $rim }}" width="{{ $b['size'] + 2 * $rim }}" height="{{ $b['size'] + 2 * $rim }}" fill="{{ $frameFill ?? '#3A2C14' }}"/>@endif
@foreach ($b['squares'] as $s)<rect x="{{ $s['x'] }}" y="{{ $s['y'] }}" width="{{ $b['sq'] }}" height="{{ $b['sq'] }}" fill="{{ $s['fill'] }}"/>@endforeach

@foreach ($b['pieces'] as $p)<use href="#p-{{ $p['id'] }}" xlink:href="#p-{{ $p['id'] }}" x="{{ $p['x'] }}" y="{{ $p['y'] }}" width="{{ $b['sq'] }}" height="{{ $b['sq'] }}"/>@endforeach

