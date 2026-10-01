{{--
    How a Blockfill run reaches the board, as three steps on one track: play a ranked run, the server replays it
    (StackerRuns, the verifier), the best time of the week counts (ScoreRuns). A real sequence, so it reads left to right.
--}}
<ol class="relative m-0 grid list-none grid-cols-3 gap-2 p-0 before:absolute before:top-5 before:right-[16.67%] before:left-[16.67%] before:h-px before:bg-line" aria-label="{{ __('How a run counts') }}" data-test="blockfill-steps">
    @foreach ([
        ['play', __('Play'), __('Ranked, logged in')],
        ['shield-check', __('Verified'), __('The server replays it')],
        ['trophy', __('Leaderboard'), __('Your best time counts')],
    ] as [$icon, $step, $line])
        <li class="relative flex min-w-0 flex-col items-center gap-1.5 text-center">
            <span class="mb-0.5 flex size-10 items-center justify-center rounded-md bg-btc-chip text-btc-hi shadow-[0_0_0_4px_var(--color-ground)]"><x-icon :name="$icon" :size="18" /></span>
            <b class="text-[13px] leading-tight">{{ $step }}</b>
            <span class="max-w-[24ch] text-xs leading-snug text-ink-2">{{ $line }}</span>
        </li>
    @endforeach
</ol>
