@props(['item', 'size' => 28, 'badge' => true])

{{--
    The face of a dock item (MatchDock.dc.html "Anatomy of a tab"): the other
    player's avatar for chess, the clan tag for Rocket League (there is no clan
    art yet), and a small knight or RL mark in the corner.
--}}
<span class="relative flex shrink-0" style="width: {{ $size }}px; height: {{ $size }}px">
    @if ($item->face)
        <x-avatar :user="$item->face" :size="$size" class="rounded-md" />
    @else
        <span class="flex size-full items-center justify-center rounded-md bg-btc-tint text-[10px] font-bold text-btc" aria-hidden="true">{{ $item->tag }}</span>
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
