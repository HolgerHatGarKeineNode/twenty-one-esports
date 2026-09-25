@props(['item', 'nowMs'])

{{--
    One collapsed dock tab, 188 x 48 (MatchDock.dc.html "Anatomy of a tab"):
    edge, face, who, the state in words, the number, the fuse. A disclosure
    button for its panel. `data-tick` lets resources/js/matchDock.js count
    the number down; `data-need` lets it notice a tab that turns to "on you".
--}}
@php
    $urgent = $item->isUrgent($nowMs);
    $share = $item->fuseShare($nowMs);
@endphp
<button type="button" wire:key="tab-{{ $item->key }}"
        {{ $attributes->class(['dk-tab w-[188px] shrink-0', 'dk-you' => $item->needsYou, 'dk-wait' => ! $item->needsYou]) }}
        x-bind:class="open === @js('item:'.$item->key) && 'dk-open'"
        x-on:click="toggle(@js('item:'.$item->key), $el)"
        x-bind:aria-expanded="(open === @js('item:'.$item->key)).toString()" aria-expanded="false"
        aria-controls="dock-panel-{{ $item->key }}"
        data-dock-tab="{{ $item->key }}" data-need="{{ $item->needsYou ? '1' : '0' }}"
        data-test="dock-tab">
    <x-match-dock.face :item="$item" />
    <span class="flex min-w-0 grow flex-col gap-0.5">
        <span @class(['dk-name', 'text-ink' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])><span class="sr-only">{{ $item->sentence }}. </span><span aria-hidden="true">{{ $item->name }}</span></span>
        <span aria-hidden="true" @class(['dk-line', 'text-btc' => $item->needsYou, 'text-ink-2' => ! $item->needsYou])>
            @if ($item->isLive() && $item->kind === 'series')<span class="dk-live size-2 shrink-0 rounded-full bg-btc"></span>@endif
            <span class="truncate">{{ $item->state }}</span>
            <span class="grow"></span>
            <span @class(['shrink-0', 'text-loss' => $urgent, 'text-ink' => ! $urgent && $item->needsYou, 'text-ink-2' => ! $urgent && ! $item->needsYou])
                  @if ($item->tick) data-tick='@json($item->tick)' @endif data-test="dock-tab-number">{{ $item->trailing }}</span>
        </span>
    </span>
    @if ($share !== null)
        <span aria-hidden="true" @class(['dk-fuse', 'bg-loss' => $urgent, 'bg-btc' => ! $urgent]) style="width: calc((100% - 3px) * {{ round($share, 4) }})" data-fuse='@json($item->tick)'></span>
    @endif
</button>
