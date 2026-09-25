@props(['fen', 'flip' => false, 'last' => [], 'label' => ''])

{{--
    A small static board for a dock panel, rendered on the server so the
    dock needs no chess script on every page. Same squares, glyphs and
    last-move colours as the board kit (resources/js/chess.js boardCells(),
    house theme), without coordinates.
--}}
@php
    $cells = [];
    foreach (explode('/', explode(' ', $fen)[0]) as $r => $row) {
        $f = 0;
        foreach (str_split($row) as $ch) {
            if (ctype_digit($ch)) {
                for ($k = 0; $k < (int) $ch; $k++) {
                    $cells[] = [$r, $f++, ''];
                }
            } else {
                $cells[] = [$r, $f++, $ch];
            }
        }
    }
    if ($flip) {
        $cells = array_reverse($cells);
    }
@endphp
<div role="img" aria-label="{{ $label }}" {{ $attributes->class('cube cube-sm relative aspect-square shrink-0') }} style="background: #3A2C14">
    <div class="grid size-full grid-cols-8 grid-rows-8">
        @foreach ($cells as [$r, $f, $ch])
            @php
                $name = 'abcdefgh'[$f].(8 - $r);
                $light = ($r + $f) % 2 === 0;
                $isLast = in_array($name, $last, true);
                $bg = $isLast ? ($light ? '#F4C47F' : '#B8741F') : ($light ? '#CFCFD4' : '#62626C');
                $idx = $ch === '' ? -1 : strpos('kqrbnp', strtolower($ch));
                $white = $ch !== '' && $ch !== strtolower($ch);
            @endphp
            <div aria-hidden="true" class="relative" style="background: {{ $bg }}">
                @if ($ch !== '')
                    <svg viewBox="0 0 45 45" width="100%" height="100%" class="absolute top-0 left-0 block" aria-hidden="true"><g text-anchor="middle" style="font-family: 'DejaVu Sans', 'Noto Sans Symbols 2', 'Segoe UI Symbol', 'Apple Symbols', sans-serif; font-variant-emoji: text; font-size: 40px"><text x="22.5" y="38" fill="{{ $white ? '#FFFFFF' : '#0A0A0B' }}">{{ mb_chr(0x265A + $idx) }}&#xFE0E;</text>@if ($white)<text x="22.5" y="38" fill="#0A0A0B">{{ mb_chr(0x2654 + $idx) }}&#xFE0E;</text>@endif</g></svg>
                @endif
            </div>
        @endforeach
    </div>
</div>
