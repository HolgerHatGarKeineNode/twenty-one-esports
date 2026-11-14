{{--
    A board game that is over: who won and why, and the way on (the next opponent, the team match, the tournament).
    Go's (design pass 2026-11-14; before it one bold line) names the winner with the stone, the margin of a counted
    game and the reason in a line of its own, holds the count from lg (partials/go-score), stands in the turn card's
    place and comes in once (go/board.css, R17); nothing moves on by itself.
--}}
<template x-if="state.status !== 'active'">
    <div @class(['flex flex-col gap-3', 'go-result rounded-[4px] border border-btc-ring bg-feature p-3 lg:p-4' => $stones, 'rounded-lg bg-card p-4' => ! $stones]) @if ($stones) data-first-screen @endif data-test="board-result">
        @if ($stones)
            {{-- Go: the winner's stone, who won by how much, and why the game ended (boardGame.js resultHead, resultWhy). --}}
            <div class="flex min-w-0 flex-col gap-1.5" data-test="result">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="mt-px flex shrink-0 lg:mt-0.5" aria-hidden="true">
                        <template x-for="side in resultSides" :key="side"><span class="size-5 lg:size-6 [&+span]:-ml-2" x-html="stoneGlyph(side)"></span></template>
                    </span>
                    <b class="min-w-0 grow font-display text-base leading-snug text-balance [overflow-wrap:anywhere] lg:text-lg" x-text="resultHead" data-test="result-head"></b>
                    {{-- A counted game: the margin as a number of its own, the sentence for a screen reader. --}}
                    <b class="shrink-0 font-display text-base leading-snug whitespace-nowrap tabular-nums lg:text-lg" x-show="resultMargin" x-text="resultMargin" aria-hidden="true" data-test="result-margin"></b>
                    <span class="sr-only" x-text="resultMargin ? countResult(state.count) : ''"></span>
                </div>
                {{-- Why it ended, and from lg the switch beside it, where it stands while the game runs too (beside the sentence of
                     partials/go-score): the card's one button is then the way on, whole in German and English (R11). --}}
                <div class="flex min-w-0 items-center gap-3">
                    <span class="min-w-0 grow text-xs leading-normal text-ink-2 lg:text-[13px]" x-text="resultWhy" data-test="result-why"></span>
                    <template x-if="score && !phone">@include('pages.board.partials.go-area-toggle')</template>
                </div>
            </div>
            {{-- From lg the card holds the count too: both scores beside the bar, each side's stones, area and komi (on a phone
                 they follow the card, so its button keeps the first screen). The margin stands once, in the line above. --}}
            <template x-if="score && !phone">@include('pages.board.partials.go-score', ['inResult' => true])</template>
        @else
            <span class="text-base font-bold" data-test="result" x-text="outcome"></span>
        @endif
        @if ($teamUrl !== null)
            {{-- A team match board: no next search, the team match decides what comes next (as a chess board). --}}
            <x-button :href="$teamUrl" class="w-full" data-test="back-to-team-match">{{ __('Back to the team match') }}</x-button>
        @elseif ($boardGame->tournament_match_id === null)
            @if ($stones)
                <x-button :href="route('board.lobby', $boardGame->game)" icon="bolt" class="w-full whitespace-nowrap" data-test="next-opponent">{{ __('Find next opponent') }}</x-button>
            @else
                <x-button :href="route('board.lobby', $boardGame->game)" icon="bolt" data-test="next-opponent">{{ __('Find next opponent') }}</x-button>
            @endif
        @else
            {{-- A tournament game: what comes next in the tournament (user, 2026-10-03), rendered now for a game already over. --}}
            <x-tournaments.game-end-slot :url="route('tournaments.show', $boardGame->tournamentMatch?->tournament_id ?? 0)" :panel="$boardGame->isActive() ? null : \App\Support\Tournaments\TournamentGameEnd::of($boardGame, auth()->user())" />
        @endif
    </div>
</template>
