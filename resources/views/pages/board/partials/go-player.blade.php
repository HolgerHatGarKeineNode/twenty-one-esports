{{--
    A side of a go game on a phone, one of the two cards above the board (`$at`: top or bottom). While the game runs: the
    side's stone, the name, its score as it stands, the clock, and the lead beside the clock of the side that leads.
    Once it is over the clock has nothing left to say and gives its place to what the game ended in: the side's score in
    the title face for a counted game, the winner's name bold; a game that was not counted shows the side's colour alone
    (its score as it stood is in the strip under the result, marked as not counted).

    `$clock` false (Go's practice, where no clock runs): the side's colour stands in the clock's place.
--}}
@php
    $side = $at === 'top' ? 'topSide' : 'bottomSide';
    $clock ??= true;
@endphp

<span class="flex w-full min-w-0 items-center gap-1.5 text-xs leading-4">
    <span class="size-3.5 shrink-0" x-html="stoneGlyph({{ $side }})"></span>
    <span class="min-w-0 truncate" :class="won({{ $side }}) ? 'font-bold' : ''" x-text="t.names[{{ $side }}]"></span>
    <template x-if="score && state.status === 'active'">
        <span class="ml-auto shrink-0 text-[13px] tabular-nums" :class="score.lead !== ({{ $side }} === 'w' ? 'b' : 'w') ? 'font-bold text-ink' : 'text-ink-2'" x-text="points(score[{{ $side }}].score)" data-test="score-{{ $at }}"></span>
    </template>
</span>
<span class="flex w-full items-baseline gap-1.5">
    @if ($clock)
        <span class="sr-only" x-text="colour({{ $side }})"></span>
        <span role="timer" class="shrink-0 font-display text-lg font-bold whitespace-nowrap tabular-nums max-lg:leading-6" :class="state.clock.running === {{ $side }} ? 'text-btc-hi' : 'text-ink'" x-show="state.status === 'active'" x-text="clock({{ $side }})" data-test="clock-{{ $at }}"></span>
    @else
        <span class="truncate text-xs leading-6 text-ink-2" x-show="state.status === 'active'" x-text="colour({{ $side }})"></span>
    @endif
    <b class="ml-auto shrink-0 text-[11px] tabular-nums text-ink max-[359px]:hidden" x-show="state.status === 'active' && score && score.lead === {{ $side }}" x-text="score ? '+' + points(score.margin) : ''" data-test="lead-{{ $at }}"></b>
    <template x-if="state.status !== 'active'">
        <span class="flex min-w-0 items-baseline gap-2 leading-6">
            <template x-if="counted">
                <b class="shrink-0 font-display text-lg leading-6 tabular-nums" :class="won({{ $side }}) ? 'text-ink' : 'text-ink-2'" x-text="points(score[{{ $side }}].score)" data-test="score-{{ $at }}"></b>
            </template>
            <span class="truncate text-xs leading-6 text-ink-2" aria-hidden="true" x-text="colour({{ $side }})"></span>
        </span>
    </template>
</span>
