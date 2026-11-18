@props(['keys', 'live' => false, 'ended' => false])

@php
    /*
     * Where every game stands in its own halving (`season-chain-v2`; /mining
     * and the admin season page): a season has no era, every game with a
     * share halves by what it mined. One row per share key of
     * ChainOverview::keys(): its era, what a win mines for each winner now per
     * mode, what it mined of its share, and how far its next halving is in
     * sats and wins (ChainOverview::halvingLine()). Never a date. An ended
     * season shows where each game stood, and promises no further win.
     */
    use App\Support\PreSeason;
    use App\Support\SeasonChain\ChainOverview;
@endphp

<div {{ $attributes->class('flex flex-col') }} data-test="key-halvings">
    @foreach ($keys as $key)
        @php
            $pays = [];
            foreach ($key['modes'] as $weightKey => $mode) {
                if (ChainOverview::mines((string) $weightKey) && $mode['pays'] > 0) {
                    $pays[] = ChainOverview::keyLabel((string) $weightKey).' '.PreSeason::formatSats($mode['pays']);
                }
            }
            $percent = $key['pot'] > 0 ? min(100, round(100 * $key['mined'] / $key['pot'], 2)) : 0;
        @endphp
        <div class="flex flex-col gap-1.5 border-b border-hairline py-3 text-[13px] last:border-0" data-test="key-halving" data-key="{{ $key['key'] }}" data-era="{{ $key['era'] ?? 'closed' }}">
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <b>{{ ChainOverview::gameLabel($key['key']) }}</b>
                <span class="rv-tag" data-test="key-era">{{ $key['closed'] ? ($key['pot'] > 0 ? __('mined out') : __('no share')) : __('Era :era of :eras', ['era' => $key['era'], 'eras' => $key['eras']]) }}</span>
                <span class="grow"></span>
                <span class="text-ink-2 tabular-nums" data-test="key-mined">{{ __(':mined of :pot sats mined', ['mined' => PreSeason::formatSats($key['mined']), 'pot' => PreSeason::formatSats($key['pot'])]) }}</span>
            </div>
            @if ($live || $ended)
                <span class="block h-1 w-full overflow-hidden rounded-[2px] bg-raised" aria-hidden="true">
                    <span class="block h-full bg-btc" style="width: {{ $percent }}%"></span>
                </span>
            @endif
            @unless ($ended)
            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-ink-2">
                @if ($pays !== [])
                    <span data-test="key-pays">{{ __('A win mines now, in sats per winner: :list', ['list' => implode(' · ', $pays)]) }}</span>
                @endif
                <span class="grow"></span>
                <span @class(['tabular-nums', 'text-btc-hi' => ! $key['closed']]) data-test="key-next-halving">{{ ChainOverview::halvingLine($key) }}</span>
            </div>
            @endunless
        </div>
    @endforeach
</div>
