{{--
    Go's bar, the stone input (DerCaddy, 2026-10-27), in the wrapper it shares with Blockli's bar (x-board.input-bar): on
    a phone fixed at the bottom (above the chat sheet, `$bottom`), the line over the buttons and never cut, so the board
    keeps the first screen; from lg the head of the middle column. The line says whose move it is, what a tap did, or
    why no stone may go there (a stone lies there, or the point has no liberty or is a ko). "Set stone" sets the stone a finger showed (a second tap
    on it does the same; a mouse sets with its click). "Pass" passes, and asks once more before a player's first pass of
    the game and when the other side has just passed, with the count the game would end in. Right after the own move "Take back" stands in its place, as in
    Blockli's bar (21 seconds, DerCaddy and the players, 2026-10-15). Everything is boardGame.js's stone input.
--}}
@php($bottom ??= 'max-lg:bottom-0')

<template x-if="layout.input === 'stones' && color && state.status === 'active'">
    <x-board.input-bar name="stone-input" status="stone-status" :bottom="$bottom" stacked
                       tone="confirmPass && canMove ? 'font-bold text-btc-hi' : (stone && canMove && !stoneMoves.has(stone) ? 'text-loss' : 'text-ink-2')">
        <div class="flex shrink-0 gap-2 lg:w-full">
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md bg-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-on-btc btn-p hover:text-on-btc"
                    x-show="stone && canMove && stoneMoves.has(stone) && pointerType !== 'mouse'" x-cloak x-on:click="setStone()" data-test="place-stone">{{ __('Set stone') }}</button>
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border px-[18px] text-[13px] whitespace-nowrap disabled:cursor-default disabled:opacity-50"
                    :class="confirmPass && canMove ? 'border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border-line bg-well text-ink'"
                    x-show="!canTakeBack && !state.noPass" :disabled="!passMove" x-on:click="pass()" data-test="pass">
                <span x-text="confirmPass && canMove ? (opponentPassed ? @js(__('Pass and end')) : @js(__('Pass anyway'))) : @js(__('Pass'))"></span>
            </button>
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center gap-2 rounded-md border border-btc bg-btc-press px-[18px] text-[13px] font-bold whitespace-nowrap text-btc-hi"
                    x-show="canTakeBack" x-cloak x-on:click="takeBack()" :aria-label="t.takeBack.left.replace(':seconds', takeBackLeft)" data-test="take-back">
                {{ __('Take back') }} <span class="min-w-[2ch] tabular-nums" x-text="takeBackLeft"></span>
            </button>
        </div>
        {{-- Always in the page, so a screen reader hears each new hint (the keys say where the stone is). --}}
        <p class="sr-only" role="status" aria-live="polite" x-text="stoneHint" data-test="stone-hint"></p>
    </x-board.input-bar>
</template>
