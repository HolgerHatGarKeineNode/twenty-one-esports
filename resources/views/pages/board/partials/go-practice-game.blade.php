{{--
    The head of a game against a bot in Go's practice (components/board/practice, design pass 2026-11-14; before it a
    card with the bot's letter and one bold line for the result): the bot as the opponent of a game's page, named, with
    its level and its stone; who is to move in the turn card's place; and, once the game is over, the result card of a
    game's page there (partials/result: the winner's stone, the margin of the count, why it ended, the count itself
    from lg) with the next action as its one orange button: the next bot after a win, else the same bot again.
--}}
<div class="flex min-w-0 items-center gap-3 max-lg:hidden" data-test="side-card-top">
    <span class="relative shrink-0" aria-hidden="true">
        <span class="grid size-11 place-items-center rounded-[4px] bg-raised font-display text-sm font-bold text-ink" x-text="botName.slice(0, 1)"></span>
        <span class="absolute -right-1 -bottom-1 block size-4 rounded-full bg-ground ring-2 ring-ground" x-html="stoneGlyph(botSide)"></span>
    </span>
    <span class="flex min-w-0 grow flex-col">
        <b class="truncate text-[15px]"><span x-text="botName"></span> <span class="text-xs font-normal text-ink-3" x-text="cfg.bots[botId].level"></span></b>
        <span class="text-[13px] text-ink-2" x-text="colour(botSide)"></span>
    </span>
</div>
<template x-if="stage === 'play'">
    <div class="flex items-center gap-4 rounded-[4px] border border-btc-ring bg-feature px-4 py-3 max-lg:hidden" role="status" data-test="turn-card">
        <b class="text-[15px]" x-text="turnLine" data-test="turn-line"></b>
    </div>
</template>
<template x-if="stage === 'over'">
    <div class="flex flex-col gap-2">
        @include('pages.board.partials.result', ['stones' => true, 'teamUrl' => null, 'head' => 'practiceHead', 'why' => 'practiceWhy', 'actions' => 'pages.board.partials.go-practice-next'])
        <div class="flex gap-2 lg:hidden" data-test="practice-more-phone">@include('pages.board.partials.go-practice-more')</div>
    </div>
</template>
