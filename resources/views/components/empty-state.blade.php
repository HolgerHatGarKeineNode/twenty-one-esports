@props(['heading', 'text' => null])

{{--
    Empty state from States.dc.html ("Empty ladder"): three dashed block slots,
    a display heading, one line of direction and the actions in the slot.
    Empty is an invitation to act, so the slot should hold at least one action.
--}}
<div {{ $attributes->class('flex flex-col gap-3.5') }}>
    <span class="flex gap-3.5" aria-hidden="true">
        @foreach (range(1, 3) as $blockSlot)
            <span class="size-8 rounded-[3px] border-[1.5px] border-dashed border-dash"></span>
        @endforeach
    </span>
    <h2 class="m-0 font-display text-[22px] leading-[1.25] font-bold">{{ $heading }}</h2>
    @if ($text)
        <p class="m-0 max-w-[60ch] text-[13px] leading-normal text-ink-2">{{ $text }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-1 flex flex-wrap gap-3">{{ $slot }}</div>
    @endif
</div>
