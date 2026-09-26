{{--
    The public marker of a director result (TOURNAMENT-FORMATS.md, section 6):
    a chip that opens who entered it, who corrected it, and that the players
    did not confirm it. Plain <details>: works without JavaScript.

    $marker: TournamentView::marker()
--}}
<details class="group text-xs" data-test="director-marker">
    <summary class="inline-flex min-h-6 cursor-pointer items-center gap-1.5 rounded-xs border border-line px-2 text-ink-2">
        <x-icon name="shield-check" :size="12" />
        {{ __('Entered by the tournament director') }}{{ $marker['corrected'] ? ', '.__('corrected') : '' }}
    </summary>
    <div class="mt-1.5 flex flex-col gap-0.5 rounded-sm bg-ground px-2.5 py-2 leading-normal text-ink-2">
        @foreach ($marker['lines'] as $line)
            <span>{{ $line }}</span>
        @endforeach
    </div>
</details>
