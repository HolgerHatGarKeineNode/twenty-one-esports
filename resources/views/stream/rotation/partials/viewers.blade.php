{{--
    The live viewer badge, "<eye> 12,345 watching", from RotationKit::viewerBadge(): $vb (never null here; a scene
    includes this only for a known count) carries position, faces and sizes; the inks are $eyeInk / $countInk /
    $wordInk. The rotation looks put it on their top line, right-aligned to x 1024: next to the client's LIVE badge,
    outside its corner (x >= 1040, y < 112).
    One <g data-unit> so the pixel sonde measures the badge as one piece.
--}}
<g data-unit="viewers" data-box="{{ $vb['box'] }}"><g transform="translate({{ $vb['eyeX'] }} {{ $vb['eyeY'] }}) scale({{ $vb['eyeScale'] }})" fill="none" stroke="{{ $eyeInk }}" stroke-width="2.2" stroke-linejoin="round"><path d="M1 12 Q12 1 23 12 Q12 23 1 12 Z"/><circle cx="12" cy="12" r="3.6" fill="{{ $eyeInk }}" stroke="none"/></g><text x="{{ $vb['countX'] }}" y="{{ $vb['y'] }}" font-family="{{ $vb['countFont'] }}" font-weight="{{ $vb['countFont'] === 'Unbounded' ? 800 : 700 }}" font-size="{{ $vb['countSize'] }}" fill="{{ $countInk }}" text-anchor="end">{{ $vb['count'] }}</text><text x="{{ $vb['wordX'] }}" y="{{ $vb['y'] }}" font-family="JetBrains Mono" font-weight="700" font-size="{{ $vb['wordSize'] }}" fill="{{ $wordInk }}" text-anchor="end">watching</text></g>
