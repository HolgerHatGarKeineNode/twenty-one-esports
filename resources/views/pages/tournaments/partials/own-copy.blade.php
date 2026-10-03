{{--
    "You need your own copy": on every tournament page of a game played
    outside the site (App\Games\Contracts\PlayedOnOwnCopy), above the sign-up
    action (user, 2026-10-03: players signed up who did not own the game).
    One bold line, the platforms as glyph chips linking to their store, and
    "Free to play" where true. Renders nothing for a game played on the site.
    The chips are smaller below sm, so five of them take two rows on a phone.
    A platform that plays only against itself (`esports.casual.crossplay_excluded`,
    EA FC on Switch) says "no crossplay" on its chip.
    $tournament; $compact (sign-up page): a smaller line and padding, so the
    confirm button stays in a phone's first screen; the ownership tick is
    pages.tournaments.partials.own-copy-tick, next to that button.
--}}
@php
    $ownCopyGame = app(\App\Games\GameRegistry::class)->find($tournament->game);
    $compact ??= false;
    $noCrossplay = (array) config('esports.casual.crossplay_excluded.'.$tournament->game, []);
    $chip = 'inline-flex min-h-11 items-center gap-1.5 rounded-sm px-2 text-xs font-bold sm:gap-2 sm:px-3 sm:text-[13px]';
@endphp

@if ($ownCopyGame instanceof \App\Games\Contracts\PlayedOnOwnCopy)
    @php $ownCopyName = \App\Support\GameNames::game($tournament->game); @endphp
    <div @class(['flex flex-col rounded-md bg-well shadow-[inset_0_0_0_2px_var(--color-bolt)]', 'gap-2.5 p-3 sm:p-4' => $compact, 'gap-3 p-4' => ! $compact]) role="note" data-test="own-copy">
        <p @class(['m-0 flex items-start gap-2 font-display leading-snug font-bold text-ink', 'text-base sm:text-lg' => $compact, 'text-lg sm:text-xl' => ! $compact])>
            <x-icon name="warn" :size="$compact ? 20 : 24" class="mt-0.5 text-bolt" />
            <span>{{ __('You need your own copy of :game to play', ['game' => $ownCopyName]) }}</span>
        </p>
        <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0 sm:gap-2" aria-label="{{ __('Platforms') }}" data-test="own-copy-platforms">
            @if ($ownCopyGame->isFreeToPlay())
                <li class="{{ $chip }} bg-win-tint text-win shadow-ring-win" data-test="own-copy-free">
                    <x-icon name="check" :size="16" />{{ __('Free to play') }}
                </li>
            @endif
            @foreach ($ownCopyGame->stores() as $store)
                @php $alone = in_array($store->platform->device(), $noCrossplay, true); @endphp
                <li class="contents">
                    <{{ $store->url !== null ? 'a' : 'span' }} @if ($store->url !== null) href="{{ $store->url }}" rel="noopener noreferrer" target="_blank" @endif data-test="own-copy-{{ $store->platform->value }}"
                        @class([$chip, 'relative bg-raised text-ink shadow-ring', 'hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-edge)]' => $store->url !== null])>
                        <x-icon :name="$store->platform->icon()" :size="16" />{{ $store->platform->label() }}
                        @if ($alone)
                            {{-- On the chip's top edge, so it costs no width: five chips keep their two rows on a phone. --}}
                            <span class="absolute -top-1.5 right-1.5 rounded-xs bg-ground px-1 text-[10px] leading-3 font-bold whitespace-nowrap text-ink-2 shadow-ring" data-test="own-copy-no-crossplay">{{ __('no crossplay') }}</span>
                        @endif
                    </{{ $store->url !== null ? 'a' : 'span' }}>
                </li>
            @endforeach
        </ul>
    </div>
@endif
