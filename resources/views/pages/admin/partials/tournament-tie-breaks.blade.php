{{--
    Up to three tie-breaks in order (Challonge's list; median Buchholz for
    Swiss only). `key` is the options key, `current` the chosen list, `labels`
    value => label of the tie-breaks this format offers. `same` (optional)
    lists the chosen tie-breaks that can never split a tie here: in a round
    robin counted game by game, games won and game difference follow from the
    points (Engine\Standings: a drawn game is half a game won).

    The help explains the tie-breaks chosen, not one that is not on the list
    (DerCaddy, 2026-11-04: the round robin's help explained Buchholz, which a
    round robin does not offer).
--}}
@php
    $tieBreakHelp = [
        'median-buchholz' => __('Buchholz, median: the points of your opponents without the best and the worst; the stronger your opponents were, the higher you rank.'),
        'head-to-head' => __('Head-to-head: who did better in the games between those tied.'),
        'match-wins' => __('Matches won: more matches won.'),
        'game-wins' => __('Games won: more single games won, a draw counts half.'),
        'game-win-percentage' => __('Game win percentage: the share of single games won.'),
        'game-difference' => __('Game difference: games won minus games lost.'),
        'points-scored' => __('Points scored: the points scored inside the games, goals for example.'),
        'points-difference' => __('Points difference: points scored minus points conceded.'),
    ];
    $chosenTieBreaks = array_values(array_unique(array_filter($current, fn ($value): bool => is_string($value) && isset($labels[$value]))));
    $sameTieBreaks = array_values(array_intersect($chosenTieBreaks, $same ?? []));
@endphp
<div class="grid gap-2 border-t border-hairline py-3 @[560px]:grid-cols-[minmax(0,1fr)_280px] @[560px]:gap-4">
    <span class="flex flex-col items-start gap-2">
        <span id="tb-{{ $key }}" class="text-[13px] font-bold">{{ __('Tie-breaks, in this order') }}</span>
        <ol class="m-0 flex w-full flex-col gap-1.5 pl-5 text-xs text-ink-2">
            @foreach ([0, 1, 2] as $index)
                <li wire:key="tb-{{ $key }}-{{ $index }}">
                    <x-ui.select-menu :model="'options.'.$key.'.'.$index" live :value="$current[$index] ?? null" :label="__('Tie-break :number', ['number' => $index + 1])" :options="$labels" width="16rem" />
                </li>
            @endforeach
        </ol>
    </span>
    <span class="flex flex-col gap-1.5 text-xs leading-normal text-ink-2" data-test="tie-break-help-{{ $key }}">
        <span>{{ __('Used when two have the same points, in this order.') }}</span>
        @foreach ($chosenTieBreaks as $tieBreak)
            @isset($tieBreakHelp[$tieBreak])
                <span wire:key="tbh-{{ $key }}-{{ $tieBreak }}">{{ $tieBreakHelp[$tieBreak] }}</span>
            @endisset
        @endforeach
        @if ($sameTieBreaks !== [])
            <span class="text-loss" role="status" data-test="tie-break-same">{{ __('Counted per game, everyone plays the same games, so :tieBreaks follow from the points and split no tie. Head-to-head does.', [
                'tieBreaks' => implode(__(' and '), array_map(fn (string $tieBreak): string => $labels[$tieBreak], $sameTieBreaks)),
            ]) }}</span>
        @endif
    </span>
</div>
