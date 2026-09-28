{{--
    Stepping through the moves of a running game (P55): first, previous,
    next, current — 44 px each, with Home ← → End on the keyboard — and what
    the board shows. The enclosing x-data is a board with
    resources/js/moveHistory.js (withHistory): chessGame or dailyGame.

    While it follows the game the state part names the newest move, or from
    lg holds the slot (the blitz move field: typing a move and stepping back
    share one row, so the field stays in the first viewport at 1440 x 900).
    On an earlier position the state part becomes the way back: "Viewing
    12… Nf6 / Back to the current position", and a move played meanwhile
    turns its first line into "New move: 13. Qh5". Every state has the same
    height, so nothing around the bar moves when someone steps back.

    From sm one row, the steps left of the state. Below sm the four steps
    share the first row and the state takes the full width of the second:
    next to the steps it had 135 px, and "Back to the current position" was
    cut off (German on both lines, measured 2026-09-28).
--}}
@php
    $step = 'btn-w flex h-11 cursor-pointer items-center justify-center rounded-md border border-line bg-well text-ink disabled:cursor-default disabled:opacity-40 sm:w-11 sm:shrink-0';
    $field = $slot->hasActualContent();
@endphp
<div {{ $attributes->class('relative flex flex-col gap-2 sm:flex-row sm:items-center') }} data-test="history-bar">
    <div class="grid grid-cols-4 gap-2 sm:flex">
        <button type="button" aria-label="{{ __('First move') }}" aria-keyshortcuts="Home" x-on:click="go(0)" :disabled="shownIndex === 0" class="{{ $step }}" data-test="history-first"><x-icon name="first" :size="16" /></button>
        <button type="button" aria-label="{{ __('Previous move') }}" aria-keyshortcuts="ArrowLeft" x-on:click="go(shownIndex - 1)" :disabled="shownIndex === 0" class="{{ $step }}" data-test="history-prev"><x-icon name="prev" :size="16" /></button>
        <button type="button" aria-label="{{ __('Next move') }}" aria-keyshortcuts="ArrowRight" x-on:click="go(shownIndex + 1)" :disabled="! browsing" class="{{ $step }}" data-test="history-next"><x-icon name="next" :size="16" /></button>
        <button type="button" aria-label="{{ __('Current position') }}" aria-keyshortcuts="End" x-on:click="toLive()" :disabled="! browsing" class="{{ $step }}" data-test="history-last"><x-icon name="last" :size="16" /></button>
    </div>

    <div class="flex h-11 min-w-0 grow">
        <span x-show="! browsing" @class(['flex min-w-0 flex-col justify-center gap-0.5 px-1 leading-4', 'lg:hidden' => $field]) data-test="history-live">
            <b class="truncate text-sm" x-text="newestLabel || @js(__('Start position'))"></b>
            <span class="truncate text-xs text-ink-2">{{ __('Current position') }}</span>
        </span>
        @if ($field)
            <div x-show="! browsing" class="flex min-w-0 grow max-lg:hidden">{{ $slot }}</div>
        @endif
        <button type="button" x-show="browsing" x-cloak x-on:click="toLive()" data-test="history-back"
                class="flex h-11 min-w-0 grow cursor-pointer flex-col items-start justify-center gap-0.5 rounded-md border-0 bg-btc-press px-3 text-left leading-4 shadow-[inset_0_0_0_1px_var(--color-btc)]">
            <span x-show="! newMoves" class="max-w-full truncate text-xs text-ink" x-text="shownLabel ? @js(__('Viewing :move')).replace(':move', shownLabel) : @js(__('Viewing the start position'))" data-test="history-viewing"></span>
            <span x-show="newMoves" class="flex max-w-full items-center gap-1.5 text-xs font-bold text-btc-hi" data-test="history-new">
                <span class="size-2 shrink-0 animate-live rounded-full bg-btc" aria-hidden="true"></span><span class="truncate" x-text="@js(__('New move: :move')).replace(':move', newestLabel)"></span>
            </span>
            <span class="max-w-full truncate text-[13px] font-bold text-btc-hi">{{ __('Back to the current position') }}</span>
        </button>
    </div>

    {{-- Said once per step and once per new move, not with every tick of the page --}}
    <span class="sr-only" aria-live="polite" x-text="browsing ? (newMoves ? @js(__('New move: :move')).replace(':move', newestLabel) + '. ' : '') + (shownLabel ? @js(__('Viewing :move')).replace(':move', shownLabel) : @js(__('Viewing the start position'))) : ''"></span>
</div>
