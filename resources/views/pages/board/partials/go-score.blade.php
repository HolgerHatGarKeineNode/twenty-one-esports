{{--
    Go's score as it stands (design pass 2026-11-14; before it one grey line and a text link): who leads by how much;
    each side's score beside a bar that divides the points of both (Black's part, White's, the komi in it, a tick at the
    half); each side's stones, area and komi. All of it is the server's count (boardGame.js score, R13): no estimate.
    "Show area" (partials/go-area-toggle) marks each side's area on the board.

    While the game runs: beside the board from lg (a card of the middle column); on a phone under the board, in the room
    between it and the bar (boardGame.js fitBoard keeps it there while it fits), the scores themselves in the two player
    cards above the board. Once the game is over the result says who won by how much, once: from lg the result card
    holds the scores, the bar and the numbers (`$inResult`, the switch beside its button), on a phone they follow that
    card, and the line here speaks only for a game that was not counted (boardGame.js scoreNote). The sentence for a
    screen reader is the count in one line (countLine).
--}}
@php($inResult ??= false)

<div @class(['flex min-w-0 flex-col gap-2', 'lg:rounded-lg lg:bg-card lg:p-4' => ! $inResult]) data-test="count">
    @if ($inResult)
        <span class="text-xs leading-4 text-ink-2" x-show="scoreNote" x-text="scoreNote" data-test="count-lead"></span>
    @else
        <div class="flex min-w-0 items-center gap-3">
            {{-- On a phone the bar stands under the sentence, beside the switch: one row of 44 px; from lg it has its own row between the two scores. --}}
            <span class="flex min-w-0 grow flex-col gap-1.5 max-lg:pb-1">
                <b class="text-[13px] leading-5 text-ink lg:text-[15px]" x-show="scoreNote" x-text="scoreNote" data-test="count-lead"></b>
                {{-- The wrapper hides it from lg: .go-balance sets its own display (go/board.css) and would win over the utility. --}}
                <span class="lg:hidden" aria-hidden="true">
                    <span class="go-balance">
                        <span data-part="black" :style="{ width: score.parts.black }"></span>
                        <span data-part="white" :style="{ width: score.parts.white }"></span>
                        <span data-part="komi" :style="{ width: score.parts.komi }"></span>
                    </span>
                </span>
            </span>
            @include('pages.board.partials.go-area-toggle')
        </div>
    @endif
    <div class="flex items-center gap-3 max-lg:hidden" aria-hidden="true">
        <span class="flex shrink-0 items-center gap-2"><span class="size-5" x-html="stoneGlyph('w')"></span><b class="font-display text-2xl leading-8 tabular-nums" x-text="points(score.w.score)" data-test="score-black"></b></span>
        <span class="go-balance grow" data-test="count-bar">
            <span data-part="black" :style="{ width: score.parts.black }"></span>
            <span data-part="white" :style="{ width: score.parts.white }"></span>
            <span data-part="komi" :style="{ width: score.parts.komi }"></span>
        </span>
        <span class="flex shrink-0 items-center gap-2"><b class="font-display text-2xl leading-8 tabular-nums" x-text="points(score.b.score)" data-test="score-white"></b><span class="size-5" x-html="stoneGlyph('b')"></span></span>
    </div>
    <div class="grid grid-cols-[auto_minmax(0,1fr)] gap-3 text-xs leading-4 text-ink-2" aria-hidden="true">
        <span class="flex flex-wrap gap-x-2">
            <span>{{ __('Stones') }} <b class="text-ink tabular-nums" x-text="score.w.stones"></b></span>
            <span>{{ __('Area') }} <b class="text-ink tabular-nums" x-text="score.w.area"></b></span>
        </span>
        <span class="flex flex-wrap justify-end gap-x-2">
            <span>{{ __('Stones') }} <b class="text-ink tabular-nums" x-text="score.b.stones"></b></span>
            <span>{{ __('Area') }} <b class="text-ink tabular-nums" x-text="score.b.area"></b></span>
            <span x-show="score.b.komi > 0">{{ __('Komi') }} <b class="text-ink tabular-nums" x-text="points(score.b.komi)"></b></span>
        </span>
    </div>
    <p class="sr-only" x-text="countLine" data-test="count-line"></p>
</div>
