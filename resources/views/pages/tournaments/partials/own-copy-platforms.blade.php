{{--
    The platforms of a game played on the player's own copy (pages.tournaments.partials.own-copy): glyph chips linking
    to their store, "Free to play" first where true.
    $game (PlayedOnOwnCopy), $chip (the chip's classes), $noCrossplay (devices that play only against themselves).
--}}
<ul class="m-0 flex list-none flex-wrap gap-1.5 p-0 sm:gap-2" aria-label="{{ __('Platforms') }}" data-test="own-copy-platforms">
    @if ($game->isFreeToPlay())
        <li class="{{ $chip }} bg-win-tint text-win shadow-ring-win" data-test="own-copy-free">
            <x-icon name="check" :size="16" />{{ __('Free to play') }}
        </li>
    @endif
    @foreach ($game->stores() as $store)
        @php $alone = in_array($store->platform->device(), $noCrossplay, true); @endphp
        <li class="contents">
            <{{ $store->url !== null ? 'a' : 'span' }} @if ($store->url !== null) href="{{ $store->url }}" rel="noopener noreferrer" target="_blank" @endif data-test="own-copy-{{ $store->platform->value }}"
                @class([$chip, 'relative bg-raised text-ink shadow-ring', 'hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-edge)]' => $store->url !== null])>
                <x-icon :name="$store->platform->icon()" :size="16" />{{ $store->platform->label() }}
                @if ($alone)
                    {{-- On the chip's top edge, so it costs no width: five chips keep their two rows on a phone. --}}
                    <span class="absolute -top-1.5 right-1.5 rounded-xs bg-ground px-1 text-xs leading-4 font-bold whitespace-nowrap text-ink-2 shadow-ring" data-test="own-copy-no-crossplay">{{ __('no crossplay') }}</span>
                @endif
            </{{ $store->url !== null ? 'a' : 'span' }}>
        </li>
    @endforeach
</ul>
