{{--
    One side of a projected pairing or group (before the draw): the seed,
    and the entry holding that seed now, or an open spot.

    $side: TournamentLanding::projection() side {seed, name, mix, you}
--}}
<span class="flex min-h-7 min-w-0 items-center gap-2 text-[13px]">
    <span class="w-6 shrink-0 text-right font-display text-xs font-bold text-ink-3 tabular-nums">{{ $side['seed'] }}</span>
    @if ($side['name'] !== null)
        <span @class(['min-w-0 grow truncate', 'font-bold text-btc-hi' => $side['you']])>{{ $side['name'] }}@if ($side['you']) <span class="text-xs font-normal">({{ __('you') }})</span>@endif</span>
    @elseif ($side['mix'])
        <span class="min-w-0 grow truncate text-ink-2">{{ __('Mix team') }}</span>
    @else
        <span class="min-w-0 grow truncate text-ink-3">{{ __('Open spot') }}</span>
    @endif
</span>
