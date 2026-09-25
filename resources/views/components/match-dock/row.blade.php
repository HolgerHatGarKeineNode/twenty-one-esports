@props(['item', 'nowMs', 'variant' => 'list'])

{{--
    One dock item as a list row: `list` for "+N more" and the game-page list
    (MatchDock.dc.html), `sheet` for the phone sheet (MobileMatchDock.dc.html),
    where what needs you carries its one action and the rest opens its page.
--}}
@php
    $urgent = $item->isUrgent($nowMs);
@endphp
@if ($variant === 'sheet')
    <div wire:key="sheet-{{ $item->key }}" {{ $attributes->class(['flex items-center gap-2 py-1 pl-3', 'shadow-[inset_3px_0_0_var(--color-btc)]' => $item->needsYou, 'shadow-[inset_3px_0_0_var(--color-line)]' => ! $item->needsYou]) }} data-test="dock-sheet-row" data-key="{{ $item->key }}">
        <a href="{{ $item->href }}" class="dk-row min-w-0 grow px-1 text-ink hover:text-ink">
            <x-match-dock.face :item="$item" :size="36" :badge="false" />
            <span class="flex min-w-0 grow flex-col gap-0.5">
                <span @class(['dk-name', 'text-ink' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])>{{ $item->name }}</span>
                <span class="dk-line text-ink-2">
                    @if ($item->isChess())<x-icon name="chess" :size="14" />@else<span class="dk-slot">RL</span>@endif
                    <span class="truncate">{{ $item->line }}</span>
                </span>
                <span @class(['truncate text-xs', 'text-loss' => $urgent, 'text-ink' => ! $urgent && $item->needsYou, 'text-ink-2' => ! $urgent && ! $item->needsYou])
                      @if ($item->tick) data-tick='@json($item->tick)' data-suffix="{{ __(':left left') }}" @endif>{{ $item->tick ? __(':left left', ['left' => $item->trailing]) : $item->trailing }}</span>
            </span>
            @unless ($item->needsYou && $item->action)
                <x-icon name="next" :size="16" class="text-ink-3" />
            @endunless
        </a>
        @if ($item->needsYou && $item->action)
            <a href="{{ $item->href }}" class="dk-cta mr-3 h-11 shrink-0 px-3.5 text-[13px]" aria-label="{{ $item->action }}, {{ $item->sentence }}" data-test="dock-sheet-action">{{ $item->action }}</a>
        @endif
    </div>
@else
    <a href="{{ $item->href }}" wire:key="row-{{ $item->key }}" {{ $attributes->class('dk-row text-ink hover:text-ink') }} data-test="dock-row" data-key="{{ $item->key }}">
        <x-match-dock.face :item="$item" :size="32" :badge="false" />
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <span @class(['dk-name', 'text-ink' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])>{{ $item->name }} @if ($item->number !== '')<span class="font-normal text-ink-3">{{ $item->number }}</span>@endif</span>
            <span @class(['dk-line', 'text-btc' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])><span class="truncate">{{ $item->line }}</span></span>
        </span>
        <x-icon name="next" :size="16" class="text-ink-3" />
    </a>
@endif
