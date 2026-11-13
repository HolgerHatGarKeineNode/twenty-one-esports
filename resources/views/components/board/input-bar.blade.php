{{--
    The bar of a board game's own input (Blockli's blocks, Go's stones), one wrapper for both so they cannot drift apart:
    on a phone fixed at the bottom (above the chat sheet, `$bottom`), so the board keeps the first screen; from lg the
    head of the middle column. It holds the line (boardGame.js barLine: whose move it is, what a tap did, why nothing may
    go there) and, in its slot, the game's buttons.

    Blockli's bar is one row on a phone, the line beside the buttons, cut after two lines (three tighter ones under
    360 px): its texts are short, and the row never grows. Go's is `stacked`: the line over the buttons and never cut,
    two lines high whatever it says, because its longest lines (the first pass with the count, in German) have no room
    beside "Trotzdem passen" at 320 px; a longer line makes the bar taller instead of losing words.

    `tone` and `line` are the line's Alpine class expression and its own classes; `name` and `status` the data-test
    names of the bar and the line.
--}}
@props(['name', 'status', 'tone', 'bottom' => 'max-lg:bottom-0', 'stacked' => false, 'line' => ''])

<div {{ $attributes->class([
        'flex flex-col gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:z-20 max-lg:border-t max-lg:border-line max-lg:bg-bar max-lg:px-4 max-lg:py-1.5 max-lg:shadow-[0_-16px_32px_rgba(10,10,11,.8)]',
        'max-lg:flex-row max-lg:items-center max-lg:gap-3 max-[359px]:gap-2 max-[359px]:px-3' => ! $stacked,
        $bottom,
    ]) }} x-ref="dock" data-page-bar data-test="{{ $name }}">
    <p @class([
        'm-0 min-w-0 text-xs lg:text-[13px]',
        'max-lg:min-h-8' => $stacked,
        'line-clamp-2 grow max-[359px]:line-clamp-3 max-[359px]:leading-[14px]' => ! $stacked,
        $line,
    ]) :class="{{ $tone }}" x-text="barLine" data-test="{{ $status }}"></p>
    {{ $slot }}
</div>
