@props(['item', 'size' => 28, 'badge' => true])

{{--
    The face of a dock item (MatchDock.dc.html "Anatomy of a tab"): the other
    player's avatar for chess, the clan logo (or its tag) for Rocket League, and a small knight or RL mark in the corner.
--}}
<span class="relative flex shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
    @if ($item->face)
        <x-avatar :user="$item->face" :size="$size" class="rounded-md" />
    @else
        <x-clan-tag :clan="$item->clan" :tag="$item->tag" :tile="$size" class="flex size-full items-center justify-center rounded-md bg-btc-tint text-[10px] font-bold text-btc" aria-hidden="true" />
    @endif
    @if ($badge)
        <span aria-hidden="true" class="absolute -right-1.5 -bottom-1 flex size-4 items-center justify-center rounded-[4px] bg-card text-ink-2">
            @if ($item->isChess())
                <x-icon name="chess" :size="12" />
            @else
                <span class="dk-slot">RL</span>
            @endif
        </span>
    @endif
</span>
