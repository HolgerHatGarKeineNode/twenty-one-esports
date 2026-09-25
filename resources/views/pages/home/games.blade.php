@php
    $games = [
        [
            'name' => __('Chess'),
            'icon' => 'pawn',
            'cube' => 'var(--color-btc)',
            'modes' => __('Blitz 5+3, Daily'),
            'extra' => __('Live games'),
            'extraHref' => route('games.index'),
            'desc' => __('Blitz 5+3 right here in the browser, or daily correspondence with one move a day. Play solo or on a board for your clan in 3-board team matches.'),
            'mobileDesc' => __('Play right here in the browser. Solo, or on a board for your clan in 3-board team matches.'),
            'stats' => [['5', __('live now'), __('live now')], ['38', __('games today'), __('games today')], ['12', __('searching for blitz'), __('searching now')]],
            'primary' => __('Find opponent'),
            'primaryHref' => route('chess.lobby'),
            'secondary' => __('Challenge a friend'),
            'secondaryHref' => route('chess.challenge'),
        ],
        [
            'name' => 'Rocket League',
            'icon' => 'rocket-league',
            'cube' => 'var(--color-btc-hi)',
            'modes' => __('3v3, 2v2, 1v1'),
            'extra' => __('Explorer'),
            'extraHref' => route('games.rocket-league'),
            'desc' => __('Clans play 3v3, 2v2 and 1v1 series in your own Rocket League client, then enter the goals here. Both captains confirm the result.'),
            'mobileDesc' => __('Clans play series in your own game client, then enter the goals here. Both captains confirm.'),
            'stats' => [['8', __('clans'), __('clans')], ['5', __('open challenges'), __('open challenges')], ['3', __('series today'), __('series today')]],
            'primary' => __('Find a clan'),
            'primaryHref' => route('clans.index'),
            'secondary' => __('Challenge a clan'),
            'secondaryHref' => route('challenges.create'),
        ],
    ];
@endphp

<section aria-labelledby="pick-h" class="col-span-full flex flex-col gap-3 lg:mt-2">
    <h2 id="pick-h" class="mt-2 mb-0 font-display text-xl font-bold lg:mt-0">{{ __('Pick your game') }}</h2>
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2 lg:gap-5">
        @foreach ($games as $game)
            <div class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:grid lg:grid-cols-[72px_minmax(0,1fr)] lg:gap-5 lg:p-6">
                <div class="flex items-center gap-5 lg:block">
                    <span aria-hidden="true" class="cube mt-3 flex size-11 shrink-0 items-center justify-center text-on-btc lg:mt-4 lg:size-14" style="background: {{ $game['cube'] }}">
                        <x-icon :name="$game['icon']" :size="24" class="lg:hidden" />
                        <x-icon :name="$game['icon']" :size="30" class="hidden lg:block" />
                    </span>
                    <span class="flex flex-col gap-0.5 pl-2 lg:hidden">
                        <h3 class="m-0 font-display text-[17px] font-bold">{{ $game['name'] }}</h3>
                        <span class="text-xs text-ink-2">{{ $game['modes'] }}</span>
                    </span>
                </div>

                <div class="flex flex-col gap-3">
                    <span class="hidden items-baseline justify-between lg:flex">
                        <h3 class="m-0 font-display text-lg font-bold">{{ $game['name'] }}</h3>
                        <a href="{{ $game['extraHref'] }}" class="inline-flex min-h-6 items-center text-xs">{{ $game['extra'] }}</a>
                    </span>
                    <p class="m-0 text-[13px] leading-[1.6] text-ink-2 lg:hidden">{{ $game['mobileDesc'] }}</p>
                    <p class="m-0 hidden max-w-[60ch] text-[13px] leading-[1.6] text-ink-2 lg:block">{{ $game['desc'] }}</p>

                    <div class="grid grid-cols-3 gap-2 text-[13px] lg:flex lg:gap-6">
                        @foreach ($game['stats'] as [$value, $label, $shortLabel])
                            <span class="flex flex-col gap-0.5 lg:flex-row lg:items-baseline lg:gap-1.5">
                                <b class="text-base">{{ $value }}</b>
                                <span class="text-[11px] text-ink-2 lg:hidden">{{ $shortLabel }}</span>
                                <span class="hidden text-ink-2 lg:inline">{{ $label }}</span>
                            </span>
                        @endforeach
                    </div>

                    <div class="grid grid-cols-2 gap-2 lg:mt-1 lg:flex lg:gap-3">
                        <a href="{{ $game['primaryHref'] }}" class="btn-p flex h-11 items-center justify-center rounded-lg bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc lg:inline-flex">{{ $game['primary'] }}</a>
                        <a href="{{ $game['secondaryHref'] }}" class="btn-s flex h-11 items-center justify-center rounded-lg border border-edge px-[18px] text-center text-[13px] text-ink hover:text-ink lg:inline-flex lg:text-sm">{{ $game['secondary'] }}</a>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</section>
