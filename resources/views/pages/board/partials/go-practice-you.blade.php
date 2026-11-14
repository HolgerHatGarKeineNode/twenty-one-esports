{{--
    The player's own row under the moves of a game against a bot, from lg, as on a game's page (design pass 2026-11-14):
    the player's picture (a guest's letter), "You" and the stone played. On a phone both sides stand above the board
    (partials/go-practice-phone).
--}}
<div class="flex min-w-0 items-center gap-3 max-lg:hidden" x-cloak x-show="mode === 'bots' && stage !== 'pick'" data-test="side-card-bottom">
    <span class="relative shrink-0" aria-hidden="true">
        @auth
            <x-avatar :user="auth()->user()" :size="44" class="rounded-[4px]" />
        @else
            <span class="grid size-11 place-items-center rounded-[4px] bg-raised font-display text-sm font-bold text-ink" x-text="cfg.labels.you.slice(0, 1)"></span>
        @endauth
        <span class="absolute -right-1 -bottom-1 block size-4 rounded-full bg-ground ring-2 ring-ground" x-html="stoneGlyph(bottomSide)"></span>
    </span>
    <span class="flex min-w-0 grow flex-col">
        <b class="truncate text-[15px]" x-text="cfg.labels.you"></b>
        <span class="text-[13px] text-ink-2" x-text="colour(bottomSide)"></span>
    </span>
</div>
