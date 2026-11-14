{{--
    The tip's button of a bot game in Go's bar on a phone (partials/stone-input `$extra`, design pass 2026-11-14): beside
    "Pass", under the thumb; the tip itself shows under the board (partials/go-practice-tip). It gives way to "Set stone"
    and to the pass that asks, so the bar never holds three buttons. From lg the tips card has the button.
--}}
<button type="button" class="btn-w inline-flex h-11 grow cursor-pointer items-center justify-center rounded-md border border-line bg-well px-2 text-[13px] whitespace-nowrap text-ink disabled:cursor-default disabled:opacity-50 max-[359px]:text-xs min-[360px]:px-3 lg:hidden"
        x-cloak x-show="mode === 'bots' && stage === 'play' && !passAsks && !(stone && canMove && stoneMoves.has(stone) && pointerType !== 'mouse')"
        x-bind:disabled="!canAsk" x-on:click="askTip()" x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))" data-test="ask-tip-phone">{{ __('Get a tip') }}</button>
