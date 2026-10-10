{{--
    The mempool on the start page (Main.dc.html #mempool, HomePhone.dc.html): its counts, the chain of cubes and,
    on desktop, one row of proud moments from real records (HomeBoard::pride()); on a phone one line with today's
    fastest run instead. A guest sees no "to confirm": nothing of theirs waits.

    $strip: MempoolStrip::build(); $counts: HomeBoard::counts(); $pride: HomeBoard::pride(); $user; $best: HomeBoard::bestToday().
--}}
<section id="mempool" aria-labelledby="mem-h" class="flex flex-col gap-3 border-t border-hairline p-4 lg:border-0 lg:p-0" data-test="home-mempool">
    <div class="flex items-center gap-2 lg:gap-4">
        <h2 id="mem-h" class="rv-h3 lg:font-display lg:text-xl lg:font-semibold">{{ __('Mempool') }}</h2>
        <span class="flex gap-4 text-[13px] text-ink-2 max-lg:hidden" data-test="mempool-counts">
            <span>{{ __('live') }} <b class="text-ink tabular-nums">{{ $counts['live'] }}</b></span>
            @if ($user)
                <span>{{ __('to confirm') }} <b class="text-ink tabular-nums">{{ $counts['confirm'] }}</b></span>
            @endif
            <span>{{ __('finished') }} <b class="text-ink tabular-nums">{{ $counts['done'] }}</b></span>
        </span>
        <span class="rv-m lg:hidden">{{ __('live') }} {{ $counts['live'] }}, {{ __('finished') }} {{ $counts['done'] }}</span>
        <span class="grow"></span>
        <a href="{{ route('matches.index') }}" @navigate(route('matches.index')) class="rv-lk text-[13px]" data-test="mempool-open"><span class="max-lg:hidden">{{ __('Open the mempool') }}</span><span class="lg:hidden">{{ __('Open it') }}</span></a>
    </div>

    @if ($strip['finished'] !== [] || $strip['running'] !== [])
        <x-ui.mempool-chain :finished="$strip['finished']" :running="$strip['running']" />
    @else
        <p class="m-0 text-[13px] text-ink-2">{{ __('No match in the mempool yet.') }} <a href="{{ route('play') }}" class="rv-lk">{{ __('All games') }}</a></p>
    @endif

    @if ($pride !== [])
        <div class="flex h-11 flex-nowrap items-center gap-4 overflow-hidden rounded-[4px] border border-hairline bg-tick px-4 text-[13px] max-lg:hidden" data-test="pride-row">
            @foreach ($pride as $index => $moment)
                @if ($index > 0)
                    <span class="h-5 w-px shrink-0 bg-line" aria-hidden="true"></span>
                @endif
                <span class="flex items-center gap-2 whitespace-nowrap" @if ($moment['title']) title="{{ $moment['title'] }}" @endif data-test="pride-{{ $moment['kind'] }}">
                    <x-icon :name="$moment['kind'] === 'zap' ? 'bolt' : 'trophy'" :size="14" @class(['text-btc' => $moment['kind'] !== 'zap', 'text-bolt' => $moment['kind'] === 'zap']) />
                    <span class="text-ink-2">{{ $moment['label'] }}</span>
                    <b class="tabular-nums">{{ $moment['value'] }}</b>
                    @if ($moment['name'])
                        <span>{{ $moment['name'] }}</span>
                    @endif
                </span>
            @endforeach
        </div>
    @endif

    @if ($strip['finished'] !== [] || $best !== null)
        <span class="rv-m lg:hidden">
            @if ($strip['finished'] !== []){{ __('Swipe for older blocks.') }}@endif
            @if ($best !== null) {{ __('Fastest run today: :time :name', ['time' => $best['value'], 'name' => $best['name']]) }}@endif
        </span>
    @endif
</section>
