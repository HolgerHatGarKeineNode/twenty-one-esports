{{--
    The season's supply over time (Season.dc.html "Supply"): mined per day with the forecast, the halvings that happened
    in words, the forecast of the season end. Included by /mining: below the pay table before Block 0 and between
    seasons as its own card; in a live season from the first block on folded inside the chain block (`$folded`, plan
    "mining-page-clarity" P4, fix round 2026-11-21: open it ran 489 px at 390), the forecast in its summary line.
    Expects $chain, $zone, $ended, $live, $sats and $date of the page.
--}}
@php
    use App\Support\Formats;
    use App\Support\SeasonChain\ChainOverview;

    $folded ??= false;
    $forecast = $live ? __('At the rate of the last 4 weeks about :sats sats get mined by the season end (:percent %).', ['sats' => $sats($chain['estimate']['end_mined']), 'percent' => Formats::number($chain['estimate']['end_mined_percent'], 1)]) : null;
@endphp
@if ($folded)
    <details class="rv-adv min-w-0 rounded-none border-0 bg-transparent" data-test="mining-supply">
        <summary><x-icon name="chevron-down" :size="16" />{{ __('Supply: mined and left') }}</summary>
        <div class="flex min-w-0 flex-col gap-3 px-4 pb-4">
@else
    <section aria-labelledby="supply-h" class="rv-card flex flex-col gap-3 p-4 lg:p-6" data-test="mining-supply">
        <h2 id="supply-h" class="rv-h2">{{ __('Supply: mined and left') }}</h2>
@endif
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
        @if ($forecast !== null)
            <p class="m-0 text-xs text-ink-2">{{ $forecast }}</p>
        @endif
@if ($folded)
        </div>
    </details>
@else
    </section>
@endif
