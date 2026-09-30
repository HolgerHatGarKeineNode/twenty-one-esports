{{--
    One NIP-88 poll of a game channel (P21), inside gameChannel()'s scope with
    `poll` in hand: the question, one row per answer with its share, and the
    total. Before the close a logged-in viewer votes by picking an answer (a
    new pick replaces the old vote); afterwards the leading answer is marked.
    Texts are x-text only.
--}}
<div class="flex flex-col gap-2 rounded-card bg-well px-3 py-3 shadow-ring" data-test="game-chat-poll" :data-poll="poll.id">
    {{-- Keyed by the poll only: a vote updates the card in place (tallies), it never builds it anew. --}}
    <template x-for="r in [result(poll)]" :key="poll.id">
        <div class="flex flex-col gap-2">
            <p class="m-0 text-sm leading-snug font-bold break-words text-ink" x-text="poll.question" data-test="game-chat-poll-question-text"></p>
            <ul role="list" class="m-0 flex list-none flex-col gap-1.5 p-0">
                <template x-for="option in r.options" :key="option.id">
                    <li>
                        <button type="button" x-on:click="vote(poll, option.id)" :disabled="! me || r.closed || voting !== null"
                                :aria-pressed="option.mine.toString()" data-test="game-chat-poll-option" :data-option="option.id"
                                class="relative flex min-h-10 w-full cursor-pointer items-center gap-2 overflow-hidden rounded-control border px-3 py-2 text-left text-[13px] text-ink disabled:cursor-default"
                                :class="option.mine ? 'border-btc' : (option.leading ? 'border-win' : 'border-line hover:border-edge')">
                            <span aria-hidden="true" class="absolute inset-y-0 left-0 transition-[width] duration-300 motion-reduce:transition-none" :class="option.mine ? 'bg-btc-press' : (option.leading ? 'bg-win-tint' : 'bg-raised')" :style="'width:' + option.share + '%'"></span>
                            <span class="relative min-w-0 grow break-words" x-text="option.label"></span>
                            <span x-show="option.mine" class="relative shrink-0 text-[11px] text-btc-hi" x-text="t.yourVote"></span>
                            <b class="relative shrink-0 text-xs tabular-nums" x-text="option.share + '%'" data-test="game-chat-poll-share"></b>
                        </button>
                    </li>
                </template>
            </ul>
            <span class="flex flex-wrap items-center gap-x-2 text-[11px] text-ink-3">
                <span x-text="r.totalLabel" data-test="game-chat-poll-total"></span>
                <span aria-hidden="true">·</span>
                <span x-text="r.when"></span>
                <span x-show="me && r.mine !== null && ! mayPoll" class="basis-full" x-text="t.notCountedYet" data-test="game-chat-poll-not-counted"></span>
                <span x-show="r.uncounted > 0" class="basis-full" x-text="r.uncounted === 1 ? t.uncountedOne : t.uncounted.replace(':count', r.uncounted)" data-test="game-chat-poll-uncounted"></span>
            </span>
        </div>
    </template>
</div>
