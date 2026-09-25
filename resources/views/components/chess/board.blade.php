@props(['playable' => false, 'frame' => '#3A2C14', 'label' => 'boardLabel'])

{{--
    The chess board from the board kit (CHESS-BOARD.md section 4), rendered by
    Alpine from `cells` (resources/js/chess.js boardCells()). The enclosing
    x-data provides `cells`, the aria label expression named by `label` and,
    on a playable board, `clickSquare(name)`. The slot holds overlays.

    Pieces are the kit's Unicode glyphs in SVG text; the kit's planned SVG
    piece set waits for its license check. Below lg the board runs edge to
    edge without the cube faces (MobileChessGame), from lg on it is the cube.
    frame: #3A2C14 live/waiting, #F7931A finished (kit section 1).
--}}
<div role="img" data-bleed x-bind:aria-label="{{ $label }}" {{ $attributes->class('relative aspect-square w-full cube-lg cursor-default select-none') }} style="background: {{ $frame }}">
    <div class="grid size-full grid-cols-8 grid-rows-8">
        <template x-for="c in cells" :key="c.name">
            <div aria-hidden="true" class="relative" :style="`background: ${c.bg}; box-shadow: ${c.ring}`"
                 @if ($playable) x-on:click="clickSquare(c.name)" :data-square="c.name" :class="c.hot && 'cursor-pointer'" @endif>
                <span class="absolute top-0.5 left-1 text-[11px] font-bold" :style="`color: ${c.coordC}`" x-text="c.rank"></span>
                <span class="absolute right-1 bottom-px text-[11px] font-bold" :style="`color: ${c.coordC}`" x-text="c.file"></span>
                <svg x-show="c.piece" viewBox="0 0 45 45" width="100%" height="100%" class="absolute top-0 left-0 block" aria-hidden="true"><g text-anchor="middle" style="font-family: 'DejaVu Sans', 'Noto Sans Symbols 2', 'Segoe UI Symbol', 'Apple Symbols', sans-serif; font-variant-emoji: text; font-size: 40px"><text x="22.5" y="38" :fill="c.fill" x-text="c.solid"></text><text x="22.5" y="38" fill="#0A0A0B" x-text="c.outline"></text></g></svg>
                <span x-show="c.dot" class="absolute top-[36%] left-[36%] size-[28%] rounded-full bg-[rgba(23,18,10,.45)]"></span>
            </div>
        </template>
    </div>
    {{ $slot }}
</div>
