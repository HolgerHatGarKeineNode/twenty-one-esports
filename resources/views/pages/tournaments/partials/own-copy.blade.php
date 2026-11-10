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
    $line (tournament page, while the viewer's vote on the start is due):
    one line of 44 px with the platforms one tap away under it, so the
    vote's first tick stays in the first screen.
--}}
@php
    $ownCopyGame = app(\App\Games\GameRegistry::class)->find($tournament->game);
    $compact ??= false;
    $line ??= false;
    $noCrossplay = (array) config('esports.casual.crossplay_excluded.'.$tournament->game, []);
    $chip = 'inline-flex min-h-11 items-center gap-1.5 rounded-sm px-2 text-xs font-bold sm:gap-2 sm:px-3 sm:text-[13px]';
@endphp

@if ($ownCopyGame instanceof \App\Games\Contracts\PlayedOnOwnCopy)
    @php $ownCopyName = \App\Support\GameNames::game($tournament->game); @endphp
    @if ($line)
        <details class="group rounded-md bg-well shadow-[inset_0_0_0_2px_var(--color-bolt)]" data-test="own-copy" data-line>
            <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 px-3 text-[13px] font-bold text-ink [&::-webkit-details-marker]:hidden">
                <x-icon name="warn" :size="18" class="shrink-0 text-bolt" />
                <span class="min-w-0 grow truncate" title="{{ __('You need your own copy of :game to play', ['game' => $ownCopyName]) }}">{{ __('You need your own copy of :game to play', ['game' => $ownCopyName]) }}</span>
                <x-icon name="chevron-down" :size="16" class="shrink-0 text-ink-2 transition-transform group-open:rotate-180" />
            </summary>
            <div class="px-3 pb-3">
                @include('pages.tournaments.partials.own-copy-platforms', ['game' => $ownCopyGame, 'chip' => $chip, 'noCrossplay' => $noCrossplay])
            </div>
        </details>
    @else
        <div @class(['flex flex-col rounded-md bg-well shadow-[inset_0_0_0_2px_var(--color-bolt)]', 'gap-2.5 p-3 sm:p-4' => $compact, 'gap-3 p-4' => ! $compact]) role="note" data-test="own-copy">
            <p @class(['m-0 flex items-start gap-2 font-display leading-snug font-bold text-ink', 'text-base sm:text-lg' => $compact, 'text-lg sm:text-xl' => ! $compact])>
                <x-icon name="warn" :size="$compact ? 20 : 24" class="mt-0.5 text-bolt" />
                <span>{{ __('You need your own copy of :game to play', ['game' => $ownCopyName]) }}</span>
            </p>
            @include('pages.tournaments.partials.own-copy-platforms', ['game' => $ownCopyGame, 'chip' => $chip, 'noCrossplay' => $noCrossplay])
        </div>
    @endif
@endif
