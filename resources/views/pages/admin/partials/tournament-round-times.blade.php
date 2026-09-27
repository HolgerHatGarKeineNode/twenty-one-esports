@php
    use App\Support\Tournaments\Estimator;
    use App\Support\Tournaments\RoundTimes;

    /*
     * How long each round really took next to its estimate (P18, the
     * measurement): read-only, from the first match's start to the round's
     * close. Nothing is shown before a round is measured.
     */
    $roundTimes = RoundTimes::forTournament($this->tournament);
    $timesProfile = $this->tournament->profile();
    $twoStages = count(array_unique(array_column($roundTimes, 'stage'))) > 1;
@endphp

@if ($roundTimes !== [])
    <section aria-labelledby="round-times-h" class="flex flex-col gap-2 rounded-lg bg-card p-4 lg:px-6 lg:py-5" data-test="round-times">
        <h2 id="round-times-h" class="m-0 text-[15px] font-bold">{{ __('Round times') }}</h2>
        <ul class="m-0 flex list-none flex-col gap-1 p-0 text-[13px]">
            @foreach ($roundTimes as $round)
                <li data-test="round-time">{{ ($twoStages ? __('Stage :stage', ['stage' => $round['stage']]).', ' : '').__('Round :number: :measured (estimate :estimate)', [
                    'number' => $round['number'], 'measured' => Estimator::format($round['minutes'], $timesProfile), 'estimate' => Estimator::format($round['estimate'], $timesProfile),
                ]) }}</li>
            @endforeach
        </ul>
    </section>
@endif
