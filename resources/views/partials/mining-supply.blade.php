{{--
    The season's supply over time (Season.dc.html "Supply"): mined per day with the forecast, the halvings that happened
    in words, the forecast of the season end. Included by /mining: below the pay table before Block 0 and between
    seasons, in the chain column of a live season from the first block on (plan "mining-page-clarity" P4).
    Expects $chain, $zone, $ended, $live, $sats and $date of the page.
--}}
@php
    use App\Support\SeasonChain\ChainOverview;
@endphp
<section aria-labelledby="supply-h" class="rv-card flex flex-col gap-3 p-4 lg:p-6" data-test="mining-supply">
    <h2 id="supply-h" class="rv-h2">{{ __('Supply: mined and left') }}</h2>
    <x-supply-chart :curve="$chain['curve']" :zone="$zone" :ended="$ended" />
    @if ($chain['curve']['halvings'] !== [])
        {{-- The halvings that happened, in words: the chart's hairlines carry no numbers on a phone. --}}
        <ul class="m-0 flex list-none flex-col gap-1 p-0 text-xs text-ink-2" data-test="mining-halvings-done">
            @foreach ($chain['curve']['halvings'] as $halving)
                <li>{{ $halving['closed']
                    ? __(':game mined its whole share on :when', ['game' => ChainOverview::gameLabel($halving['key']), 'when' => $date($halving['at'], 'D j M')])
                    : __(':game halved on :when and entered era :era', ['game' => ChainOverview::gameLabel($halving['key']), 'when' => $date($halving['at'], 'D j M'), 'era' => $halving['entered']]) }}</li>
            @endforeach
        </ul>
    @endif
    @if ($live)
        <p class="m-0 text-xs text-ink-2">{{ __('At the rate of the last 4 weeks about :sats sats get mined by the season end (:percent %).', ['sats' => $sats($chain['estimate']['end_mined']), 'percent' => $chain['estimate']['end_mined_percent']]) }}</p>
    @endif
</section>
