@props(['outcome' => null, 'size' => 22])

{{--
    One result as a block: W, D or L (translated) on the win, draw or loss
    colour, the same block in a ladder's form and in a result row, so the two
    read as one system. The letter carries the outcome, never the colour
    alone, and screen readers get the word. `outcome` null is a slot not yet
    played: the dashed block of the empty state.
--}}
@php
    [$letter, $word, $look] = match ($outcome) {
        'win' => [__('W'), __('win'), 'bg-win-tint text-win shadow-[inset_0_0_0_1px_var(--color-win-ring)]'],
        'loss' => [__('L'), __('loss'), 'bg-loss-tint text-loss shadow-[inset_0_0_0_1px_#5A2426]'],
        'draw' => [__('D'), __('draw'), 'bg-raised text-ink-2'],
        default => [null, null, 'border-[1.5px] border-dashed border-dash'],
    };
@endphp

<span {{ $attributes->class(['relative inline-flex shrink-0 items-center justify-center rounded-[3px] font-display leading-none font-bold', $look]) }}
      style="width: {{ $size }}px; height: {{ $size }}px; font-size: {{ $size >= 24 ? 12 : 11 }}px" data-outcome="{{ $outcome ?? 'none' }}">
    @if ($letter)
        <span aria-hidden="true">{{ $letter }}</span><span class="sr-only">{{ $word }}</span>
    @endif
</span>
