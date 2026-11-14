{{--
    Go's bar, the stone input (DerCaddy, 2026-10-27), in the wrapper it shares with Blockli's bar (x-board.input-bar): on
    a phone fixed at the bottom (above the chat sheet, `$bottom`), the line over the buttons and never cut, so the board
    keeps the first screen; from lg the head of the middle column. The line says whose move it is, what a tap did, or
    why no stone may go there (a stone lies there, or the point has no liberty or is a ko). "Set stone" sets the stone a
    finger showed (a second tap on it does the same; a mouse sets with its click). Right after the own move "Take back"
    stands in the pass's place, as in Blockli's bar (21 seconds, DerCaddy and the players, 2026-10-15).

    "Pass" asks before a player's first pass of the game and before the pass that ends it (R18: what is final). It is
    one question in the bar's own height: the line says what the pass means (the board is counted as it stands, dead
    stones too, with the result both passing gives; or that this pass ends the game, with the result), and two buttons
    answer it, "Keep playing" first and focused, the pass beside it. Escape, a tap on the board or "Keep playing" keep
    the game going. Everything is boardGame.js's stone input.

    `$quietIdle` (a game's page): from lg the line is gone while it only says whose move it is, which the turn card
    above it says already.
--}}
@php
    $bottom ??= 'max-lg:bottom-0';
    $quietIdle ??= false;
    // The line's colour: the question in the league's orange, a refused stone in red; from lg gone while it only says whose move it is.
    $tone = "[passAsks ? 'font-bold text-btc-hi' : (stone && canMove && !stoneMoves.has(stone) ? 'text-loss' : 'text-ink-2'), ".($quietIdle ? "barLine === statusLine ? 'lg:hidden' : ''" : "''").']';
@endphp

<template x-if="layout.input === 'stones' && color && state.status === 'active'">
    <x-board.input-bar name="stone-input" status="stone-status" :bottom="$bottom" stacked x-on:keydown.escape.window="if (passAsks) keepPlaying()"
                       :tone="$tone">
        <div class="flex shrink-0 gap-2 lg:w-full" :role="passAsks ? 'group' : null" :aria-label="passAsks ? barLine : null">
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md bg-btc px-[18px] text-[13px] font-bold whitespace-nowrap text-on-btc btn-p hover:text-on-btc"
                    x-show="stone && canMove && stoneMoves.has(stone) && pointerType !== 'mouse'" x-cloak x-on:click="setStone()" data-test="place-stone">{{ __('Set stone') }}</button>
            {{-- The safe answer to the pass that asks: first, and focused (boardGame.js pass()). --}}
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-ink-2 bg-raised px-2 text-[13px] font-bold whitespace-nowrap text-ink max-[359px]:text-xs min-[360px]:px-3"
                    x-show="passAsks" x-cloak x-on:click="keepPlaying()" data-test="keep-playing">{{ __('Keep playing') }}</button>
            <button type="button" class="inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border px-2 text-[13px] whitespace-nowrap disabled:cursor-default disabled:opacity-50 max-[359px]:text-xs min-[360px]:px-3"
                    :class="passAsks ? 'border-btc bg-btc-press font-bold text-btc-hi' : 'btn-w border-line bg-well text-ink'"
                    x-show="!canTakeBack && !state.noPass" :disabled="!passMove" x-on:click="pass()" data-test="pass">
                <span x-text="passAsks ? (opponentPassed ? @js(__('Pass and end')) : @js(__('Pass anyway'))) : @js(__('Pass'))"></span>
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
