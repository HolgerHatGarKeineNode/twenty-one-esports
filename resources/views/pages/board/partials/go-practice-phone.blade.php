{{--
    Above the board on a phone, Go's practice (components/board/practice, design pass 2026-11-14). A lesson: its head
    and the task, or the verdict with the next action. A game against a bot: both sides as on a game's page
    (partials/go-player: the stone, the name, the score as it stands, the lead), without a clock, as nothing runs here;
    nothing else, so the board keeps its place whatever is said. A tip has its own place under the board
    (partials/go-practice-tip), its button stands in the bar (partials/go-practice-tip-button), and the bar says whose
    move it is. Before 2026-11-14 the tip stood here beside its button, 232 px wide in four lines, and pushed the board
    down 46 px under the finger. Once the game is over the result card under the board says how it ended.
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
</div>
