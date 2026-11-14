{{--
    Above the board on a phone, Go's practice (components/board/practice, design pass 2026-11-14). A lesson: its head
    and the task, or the verdict with the next action. A game against a bot: both sides as on a game's page
    (partials/go-player: the stone, the name, the score as it stands, the lead), without a clock, as nothing runs here;
    under them the line of the turn or of a tip with the tip's button. Once the game is over the result card under the
    board says how it ended (partials/result), so the line is gone.
--}}
<div class="flex flex-col gap-2 lg:hidden" x-cloak x-show="mode === 'learn'" data-test="lesson-phone">
    @include('pages.board.partials.go-lesson-head', ['phone' => true])
    @include('pages.board.partials.go-lesson-task', ['phone' => true])
</div>
<div class="flex flex-col gap-2 lg:hidden" x-cloak x-show="mode === 'bots' && stage !== 'pick'" data-test="game-phone">
    <div class="grid grid-cols-2 gap-2">
        <div class="flex min-w-0 flex-col items-start rounded-lg bg-card px-3 py-1" data-test="player-top">@include('pages.board.partials.go-player', ['at' => 'top', 'clock' => false])</div>
        <div class="flex min-w-0 flex-col items-start rounded-lg bg-card px-3 py-1" data-test="player-bottom">@include('pages.board.partials.go-player', ['at' => 'bottom', 'clock' => false])</div>
    </div>
    <div class="flex min-h-11 items-center gap-2" x-cloak x-show="stage === 'play'">
        <p class="m-0 min-w-0 grow text-[13px] leading-[18px] text-ink" :class="tip ? 'font-normal' : 'font-bold'" x-text="tip ? tip.text : turnLine" data-test="turn-phone"></p>
        <button type="button" class="rv-b2 shrink-0" x-bind:disabled="!canAsk" x-on:click="askTip()"
                x-text="asked === 0 ? @js(__('Get a tip')) : @js(__('Another tip'))" data-test="ask-tip-phone">{{ __('Get a tip') }}</button>
    </div>
</div>
