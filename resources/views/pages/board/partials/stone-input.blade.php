{{--
    Go's bar, the stone input (DerCaddy, 2026-10-27), as Blockli's bar: on a phone one row fixed at the bottom (above the
    chat sheet, `$bottom`), so the board keeps the first screen; from lg the head of the middle column. The line says
    whose move it is, what a tap did, or why no stone may go there. "Set stone" sets the stone a finger showed (a second tap
    on it does the same; a mouse sets with its click). "Pass" passes, and asks once more when the other side has just
    passed, with the count the game would end in. Right after the own move "Take back" stands in its place, as in
    Blockli's bar (21 seconds, DerCaddy and the players, 2026-10-15). Everything is boardGame.js's stone input.
--}}
@php($bottom ??= 'max-lg:bottom-0')

<template x-if="layout.input === 'stones' && color && state.status === 'active'">
    <div @class(['flex flex-col gap-2 max-lg:fixed max-lg:inset-x-0 max-lg:z-20 max-lg:flex-row max-lg:items-center max-lg:gap-3 max-lg:border-t max-lg:border-line max-lg:bg-bar max-lg:px-4 max-lg:py-1.5 max-lg:shadow-[0_-16px_32px_rgba(10,10,11,.8)]', $bottom])
         x-ref="dock" data-page-bar data-test="stone-input">
        <p class="m-0 line-clamp-2 min-w-0 grow text-xs lg:text-[13px]"
           :class="confirmPass && canMove ? 'font-bold text-btc-hi' : (stone && canMove && !stoneMoves.has(stone) ? 'text-loss' : 'text-ink-2')"
           x-text="barLine" data-test="stone-status"></p>
        <div class="flex shrink-0 gap-2 lg:w-full">
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md bg-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-on-btc btn-p hover:text-on-btc"
                    x-show="stone && canMove && stoneMoves.has(stone) && pointerType !== 'mouse'" x-cloak x-on:click="setStone()" data-test="place-stone">{{ __('Set stone') }}</button>
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border px-[18px] text-[13px] whitespace-nowrap disabled:cursor-default disabled:opacity-50"
                    :class="confirmPass && canMove ? 'border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border-line bg-well text-ink'"
                    x-show="!canTakeBack && !state.noPass" :disabled="!passMove" x-on:click="pass()" data-test="pass">
                <span x-text="confirmPass && canMove ? @js(__('Pass and end')) : @js(__('Pass'))"></span>
            </button>
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center gap-2 rounded-md border border-btc bg-btc-press px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi"
                    x-show="canTakeBack" x-cloak x-on:click="takeBack()" :aria-label="t.takeBack.left.replace(':seconds', takeBackLeft)" data-test="take-back">
                {{ __('Take back') }} <span class="min-w-[2ch] tabular-nums" x-text="takeBackLeft"></span>
            </button>
        </div>
        {{-- Always in the page, so a screen reader hears each new hint (the keys say where the stone is). --}}
        <p class="sr-only" role="status" aria-live="polite" x-text="stoneHint" data-test="stone-hint"></p>
    </div>
</template>
