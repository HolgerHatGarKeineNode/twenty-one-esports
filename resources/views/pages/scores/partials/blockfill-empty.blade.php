{{--
    A Blockfill week without a single verified run: the podium stands empty (gold, silver, bronze waiting) and one line
    says what fills it. A finished empty week says nobody played it.
    $finished: the week is over.
--}}
<div class="flex flex-col gap-3" data-test="score-empty-week">
    <ol class="m-0 flex list-none flex-col p-0" aria-hidden="true">
        @foreach ([1 => 'bg-rank-gold', 2 => 'bg-rank-silver', 3 => 'bg-rank-bronze'] as $place => $tone)
            <li class="grid min-h-14 grid-cols-[32px_minmax(0,1fr)_auto] items-center gap-x-3 border-b border-hairline px-2 last:border-b-0 lg:grid-cols-[40px_minmax(0,1fr)_auto]">
                <span class="flex size-7 items-center justify-center justify-self-center rounded-full font-display text-[13px] font-bold text-on-btc opacity-40 {{ $tone }}">{{ $place }}</span>
                <span class="flex items-center gap-2.5">
                    <span class="size-7 shrink-0 rounded-sm border border-dashed border-dash"></span>
                    <span class="h-2.5 w-full max-w-40 rounded-xs bg-raised"></span>
                </span>
                <span class="font-mono text-ink-3 tabular-nums">–:––.–––</span>
            </li>
        @endforeach
    </ol>
    <p class="m-0 px-2 text-[13px] leading-normal text-ink-2">{{ $finished ? __('Nobody set a time this week.') : __('No time yet this week. The first verified run takes #1.') }}</p>
</div>
