{{--
    "You need your own copy": on every tournament page of a game played
    outside the site (App\Games\Contracts\PlayedOnOwnCopy), above the sign-up
    action (user, 2026-10-03: players signed up who did not own the game).
    One bold line, the platforms as glyph chips linking to their store, and
    "Free to play" where true. Renders nothing for a game played on the site.
    The chips are smaller below sm, so five of them take two rows on a phone.
    $tournament; $confirm (sign-up page): the ownership tick, bound to the
    component's `ownsGame`.
--}}
@php
    $ownCopyGame = app(\App\Games\GameRegistry::class)->find($tournament->game);
    $confirm ??= false;
    $chip = 'inline-flex min-h-11 items-center gap-1.5 rounded-sm px-2 text-xs font-bold sm:gap-2 sm:px-3 sm:text-[13px]';
@endphp

@if ($ownCopyGame instanceof \App\Games\Contracts\PlayedOnOwnCopy)
    @php $ownCopyName = \App\Support\GameNames::game($tournament->game); @endphp
    <div class="flex flex-col gap-3 rounded-md bg-well p-4 shadow-[inset_0_0_0_2px_var(--color-bolt)]" role="note" data-test="own-copy">
        <p class="m-0 flex items-start gap-2.5 font-display text-lg leading-snug font-bold text-ink sm:text-xl">
            <x-icon name="warn" :size="24" class="mt-0.5 text-bolt" />
            <span>{{ __('You need your own copy of :game to play', ['game' => $ownCopyName]) }}</span>
        </p>
        <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0 sm:gap-2" aria-label="{{ __('Platforms') }}" data-test="own-copy-platforms">
            @if ($ownCopyGame->isFreeToPlay())
                <li class="{{ $chip }} bg-win-tint text-win shadow-ring-win" data-test="own-copy-free">
                    <x-icon name="check" :size="16" />{{ __('Free to play') }}
                </li>
            @endif
            @foreach ($ownCopyGame->stores() as $store)
                <li class="contents">
                    @if ($store->url !== null)
                        <a href="{{ $store->url }}" rel="noopener noreferrer" target="_blank" data-test="own-copy-{{ $store->platform->value }}"
                           class="{{ $chip }} bg-raised text-ink shadow-ring hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-edge)]">
                            <x-icon :name="$store->platform->icon()" :size="16" />{{ $store->platform->label() }}
                        </a>
                    @else
                        <span class="{{ $chip }} bg-raised text-ink shadow-ring" data-test="own-copy-{{ $store->platform->value }}">
                            <x-icon :name="$store->platform->icon()" :size="16" />{{ $store->platform->label() }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($confirm)
            <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-md bg-card px-3 text-[13px] font-bold shadow-ring has-[:checked]:bg-btc-chip has-[:checked]:shadow-[inset_0_0_0_1px_var(--color-btc)]">
                <input type="checkbox" class="size-5 shrink-0 accent-[#F7931A]" wire:model="ownsGame" data-test="owns-game">
                <span>{{ __('I own :game on one of these platforms', ['game' => $ownCopyName]) }}</span>
            </label>
        @endif
    </div>
@endif
