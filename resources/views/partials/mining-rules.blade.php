{{--
    The rules in force and the change log of the season chain (Season.dc.html "Advanced statistics"). Before Block 0
    and between seasons an open disclosure in the page's left column, with the halvings as bars; in a live season a
    part of the closed reference at the end of /mining (plan "mining-page-clarity" P5), without the bars and the
    weights the pay cards already show. Expects the page's variables ($chain, $inForce, $live, $hasChain, $firstPay,
    $payKey, $shortKey, $sats, $date, …).
--}}
@php
    use App\Support\SeasonChain\ChainOverview;
    use App\Support\SeasonChain\SeasonRelease;
@endphp
{{-- Advanced statistics (canvas NEU (Scope)): the halvings as bars and the rules in force, from the same chain. --}}
@if ($live)
<div class="flex flex-col gap-3" data-test="mining-rules">
    <h3 class="m-0 text-[15px] font-bold">{{ __('Rules in force') }}</h3>
@else
<details class="rv-adv" open data-test="mining-rules">
        <summary><x-icon name="chevron-down" :size="16" />{{ __('Advanced statistics') }} <span class="rv-m font-normal max-sm:hidden">{{ $payKey ? __('Halvings of the season, :game', ['game' => $shortKey($payKey)]) : __('Halvings of the season') }}</span></summary>
@endif
    <div class="flex flex-col gap-6 p-4">
        @if ($firstPay > 0 && ! $live)
            <figure class="m-0 flex flex-col gap-2">
                <figcaption class="text-[13px] text-ink-2">{{ __('Sats per win per era') }}</figcaption>
                <div class="flex items-end gap-2 border-b border-edge" role="img" aria-label="{{ collect($chain['schedule'])->map(fn ($row) => __('Era :era', ['era' => $row['era']]).': '.$sats((int) ($row['rewards'][$payKey] ?? 0)))->implode(', ') }}">
                    @foreach ($chain['schedule'] as $row)
                        @php($pay = (int) ($row['rewards'][$payKey] ?? 0))
                        <div class="flex h-[150px] flex-1 flex-col items-center justify-end gap-1" aria-hidden="true">
                            <span class="rv-m text-ink-2 tabular-nums">{{ $sats($pay) }}</span>
                            <span class="block w-full bg-btc" style="height: {{ max(2, round(110 * $pay / $firstPay)) }}px"></span>
                            <span class="rv-m">E{{ $row['era'] }}</span>
                        </div>
                    @endforeach
                </div>
            </figure>
        @endif
        <table class="rv-tb">
            <thead><tr><th class="max-sm:whitespace-normal">{{ match (true) { $live => __('Rules in force now'), $ended => __('Rules at the season end'), default => __('Rules of the Pre-Season draft') } }}</th><th class="is-r">{{ __('Value') }}</th></tr></thead>
            <tbody>
                @foreach ([
                    // Live: the weights are in the pay cards and the eras table already (plan P3).
                    ...($live ? [] : [[__('Game weights, per winning player'), collect($inForce->weights)->map(fn ($milli, $key) => $shortKey((string) $key).' '.SeasonRelease::factor((int) $milli).'×')->implode(', ')]]),
                    [$shareTerm, ChainOverview::sharesLine($inForce->shares)],
                    [__('Blocks per player a day'), collect($inForce->daily)->map(fn ($daily, $game) => ChainOverview::gameLabel((string) $game).' '.$daily)->implode(', ')],
                    [__('Blocks per pairing'), __(':day a day, :season in the season', ['day' => $inForce->pairLimitPerDay, 'season' => $inForce->pairLimitPerSeason])],
                    [__('Same trust circle'), $inForce->subtree > 100 ? __('rule off') : __('no block from :percent %', ['percent' => $inForce->subtree])],
                    [__('Real chess game'), __('from :moves moves', ['moves' => $inForce->moves])],
                ] as [$term, $value])
                    <tr class="max-sm:flex max-sm:flex-col max-sm:border-b max-sm:border-hairline max-sm:py-2">
                        <td class="whitespace-normal max-sm:h-auto max-sm:border-0 max-sm:p-0 max-sm:text-ink-2">{{ $term }}
                            @if ($term === $shareTerm && array_sum($inForce->shares) > 0)
                                {{-- The split as a bar in each game's colour (P53); the words carry the numbers, the colour never alone. --}}
                                <span class="mt-1.5 flex h-2 max-w-80 gap-0.5 overflow-hidden rounded-[2px] bg-raised" aria-hidden="true" data-test="share-cap-bar">
                                    @foreach ($inForce->shares as $shareGame => $share)
                                        <span @class(['h-full basis-0', match (true) { $shareGame === 'chess' => 'bg-chess', str_starts_with((string) $shareGame, 'rocket') => 'bg-rl', str_starts_with((string) $shareGame, 'ea-sports-fc') => 'bg-fc', $shareGame === 'age-of-empires-2' => 'bg-aoe', default => 'bg-ink-3' }]) style="flex-grow: {{ max(0, (float) $share) }}" title="{{ ChainOverview::gameLabel((string) $shareGame) }} {{ $share }} %"></span>
                                    @endforeach
                                    @if (array_sum($inForce->shares) < 100)
                                        <span class="h-full basis-0" style="flex-grow: {{ 100 - array_sum($inForce->shares) }}"></span>
                                    @endif
                                </span>
                            @endif
                        </td>
                        <td class="is-r whitespace-normal max-sm:h-auto max-sm:border-0 max-sm:p-0 max-sm:text-left">{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($hasChain)
            <div class="flex flex-col gap-2">
                <h3 class="rv-h3">{{ __('Change log') }}</h3>
                <ol class="m-0 flex list-none flex-col p-0" data-test="mining-changes">
                    @foreach ($chain['changes'] as $change)
                        <li class="flex flex-col gap-1 border-b border-hairline py-2.5 text-[13px]">
                            <span class="flex flex-wrap gap-x-3"><b>{{ $date($change->effective_at) }}</b><span class="text-ink-2">{{ __('by :name', ['name' => $change->changedBy?->displayName() ?? substr($change->changed_by_pubkey, 0, 8)]) }}</span></span>
                            <span class="text-ink-2">{{ $change->reason }}</span>
                        </li>
                    @endforeach
                    <li class="flex flex-col gap-1 py-2.5 text-[13px]">
                        <span class="flex flex-wrap gap-x-3"><b>{{ $date($chain['season']->genesis_at) }}</b><span class="text-ink-2">{{ __('Block 0, released by :name', ['name' => $chain['season']->releasedBy?->displayName() ?? substr($chain['season']->released_by_pubkey, 0, 8)]) }}</span></span>
                        <span class="text-ink-2">{{ $chain['season']->genesis_message }}</span>
                    </li>
                </ol>
                @if ($live)
                    <p class="m-0 text-xs text-ink-3">{{ __('Any board member can adjust a limit during the season. A change counts only for blocks saved after it takes effect, never back.') }}</p>
                @endif
            </div>
        @endif
    </div>
@if ($live)
</div>
@else
</details>
@endif
