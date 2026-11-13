{{--
    Go's count as it stands (DerCaddy, 2026-10-27: play it out, then count): each side's stones and area, White's komi,
    who leads by how much; beside the board for players and spectators, the final count once the game is over. "Show
    area" marks on the board the empty points each side's area holds (boardGame.js countLine, toggleArea).
--}}
<div class="flex flex-wrap items-center gap-x-3" x-show="countLine" x-cloak data-test="count">
    <p class="m-0 min-w-0 grow text-[13px] text-ink-2" x-text="countLine" data-test="count-line"></p>
    <button type="button" class="min-h-11 cursor-pointer text-[13px] text-ink underline decoration-edge underline-offset-4 hover:decoration-btc"
            x-on:click="toggleArea()" :aria-pressed="showArea.toString()" x-text="showArea ? t.count.hide : t.count.show" data-test="toggle-area"></button>
</div>
