{{--
    The live season as a strip under the hero: its name, the block height
    and the mined supply from the chain, and the way into rated play and the
    mining page.

    $season: name, height, mined, supply (pages/home.blade.php).
--}}
<section aria-labelledby="live-h" class="mx-4 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-card bg-card px-4 py-3 shadow-ring lg:mx-12 lg:flex-nowrap lg:px-5 lg:py-4" data-test="season-live">
    <h2 id="live-h" class="m-0 flex min-w-0 items-center gap-2 font-display text-base leading-tight font-bold lg:text-lg">
        <span class="size-2 shrink-0 animate-live rounded-full bg-win" aria-hidden="true"></span>{{ __(':season is live', ['season' => $season['name']]) }}
    </h2>
    <dl class="m-0 flex grow gap-6 text-xs text-ink-2">
        <div class="flex flex-col"><dt>{{ __('Block height') }}</dt><dd class="m-0 font-display text-base font-bold text-ink tabular-nums">{{ $season['height'] }}</dd></div>
        <div class="flex flex-col"><dt>{{ __('Mined') }}</dt><dd class="m-0 font-display text-base font-bold text-ink tabular-nums">{{ $season['mined'] }} <span class="font-mono text-xs font-normal text-ink-2">{{ __('of :supply sats', ['supply' => $season['supply']]) }}</span></dd></div>
    </dl>
    <span class="flex shrink-0 items-center gap-4 max-sm:w-full">
        <a href="{{ route('chess.lobby') }}" @navigate(route('chess.lobby')) class="btn-p inline-flex h-11 items-center justify-center gap-2 rounded-md bg-btc px-5 text-[13px] font-bold text-on-btc hover:text-on-btc max-sm:grow"><x-icon name="pawn" :size="18" />{{ __('Play rated now') }}</a>
        <a href="{{ route('mining') }}" @navigate(route('mining')) class="inline-flex min-h-11 items-center text-[13px]" data-test="season-mining">{{ __('The chain') }}</a>
    </span>
</section>
