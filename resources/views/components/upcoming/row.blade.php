@props(['item', 'nowMs', 'suffix'])

{{--
    One upcoming event as a list row (<livewire:upcoming-events>): the game's cover,
    who or which tournament, the state and its countdown; the whole row opens the room
    or the tournament. A registered tournament the player may still pull out of carries
    the way to its sign-up page beside it.
--}}
<li {{ $attributes->class('flex min-w-0 items-center gap-1') }} wire:key="upcoming-{{ $item->key }}" data-test="upcoming-row" data-key="{{ $item->key }}">
    <a href="{{ $item->href }}" class="dk-row min-w-0 grow text-ink hover:text-ink" aria-label="{{ $item->sentence }}">
        <x-game-cover :game="(string) $item->game()" size="thumb" class="w-14 shrink-0 rounded-tag" />
        <span class="flex min-w-0 grow flex-col gap-0.5">
            <b class="truncate text-[13px] leading-4">{{ $item->name }}@if ($item->number !== '') <span class="font-normal text-ink-3">{{ $item->number }}</span>@endif</b>
            <x-upcoming.when :item="$item" :now-ms="$nowMs" :suffix="$suffix" class="text-xs" />
        </span>
        <x-icon name="next" :size="16" class="shrink-0 text-ink-3" />
    </a>
    @if ($item->withdraw)
        <a href="{{ $item->withdraw }}" class="inline-flex min-h-11 shrink-0 items-center px-2 text-xs text-ink-2 hover:text-ink" aria-label="{{ __('Can’t make it? Pull out of :name', ['name' => $item->name]) }}" data-test="upcoming-withdraw">{{ __('Pull out') }}</a>
    @endif
</li>
