{{--
    One side of a projected pairing or group (before the draw): the seed,
    and the entry holding that seed now, or an open spot. A long name is cut
    with an ellipsis inside its box; "(you)" stays visible beside it.

    $side: TournamentLanding::projection() side {seed, name, mix, you}
--}}
<span class="flex min-h-7 min-w-0 items-center gap-2 text-[13px]">
    <span class="w-6 shrink-0 text-right font-display text-xs font-bold text-ink-3 tabular-nums">{{ $side['seed'] }}</span>
    @if ($side['name'] !== null)
        <span @class(['min-w-0 truncate', 'grow' => ! $side['you'], 'font-bold text-btc-hi' => $side['you']]) title="{{ $side['name'] }}">{{ $side['name'] }}</span>
        @if ($side['you'])
            <span class="-ml-1 shrink-0 grow text-xs text-btc-hi">({{ __('you') }})</span>
        @endif
    @elseif ($side['mix'])
        <span class="min-w-0 grow truncate text-ink-2">{{ __('Mix team') }}</span>
    @else
        <span class="min-w-0 grow truncate text-ink-3">{{ __('Open spot') }}</span>
    @endif
</span>
