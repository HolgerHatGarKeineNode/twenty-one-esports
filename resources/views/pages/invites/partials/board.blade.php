{{--
    The start position in the board kit's look (house theme, glyph pieces in
    SVG text like components/chess/board), static: the invite landing shows
    the game, nobody moves on it. `dim` greys it out for a closed invite.
--}}
@php
    $rows = ['rnbqkbnr', 'pppppppp', '', '', '', '', 'PPPPPPPP', 'RNBQKBNR'];
@endphp
<div role="img" aria-label="{{ __('Chess board in the start position') }}"
     @class(['cube-lg relative aspect-square w-full p-[4%]', 'opacity-45 grayscale' => $dim ?? false]) style="background: #3A2C14">
    <div class="grid size-full grid-cols-8 grid-rows-8" aria-hidden="true">
        @for ($r = 0; $r < 8; $r++)
            @for ($f = 0; $f < 8; $f++)
                @php
                    $piece = $rows[$r][$f] ?? '';
                    $index = $piece === '' ? -1 : strpos('kqrbnp', strtolower($piece));
                    $white = $piece !== '' && $piece !== strtolower($piece);
                @endphp
                <div class="relative" style="background: {{ ($r + $f) % 2 === 0 ? '#CFCFD4' : '#62626C' }}">
                    @if ($piece !== '')
                        <svg viewBox="0 0 45 45" width="100%" height="100%" class="absolute top-0 left-0 block"><g text-anchor="middle" style="font-family: 'DejaVu Sans', 'Noto Sans Symbols 2', 'Segoe UI Symbol', 'Apple Symbols', sans-serif; font-variant-emoji: text; font-size: 40px"><text x="22.5" y="38" fill="{{ $white ? '#FFFFFF' : '#0A0A0B' }}">{{ mb_chr(0x265A + $index) }}&#xFE0E;</text>@if ($white)<text x="22.5" y="38" fill="#0A0A0B">{{ mb_chr(0x2654 + $index) }}&#xFE0E;</text>@endif</g></svg>
                    @endif
                </div>
            @endfor
        @endfor
    </div>
</div>
