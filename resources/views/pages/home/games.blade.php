{{--
    The games on the start page (Main.dc.html #spiele, HomePhone.dc.html): the browser games first (played right
    here, user 2026-10-10), the own-copy games under them; every tile the full 16:9 cover, the name and one real
    data line (HomeBoard::games()). On a phone the browser games are a list and the own-copy games one line.

    $games: HomeBoard::games(); $count: how many games the league has.
--}}
@php
    $allGames = trans_choice('All :count game|All :count games', $count);
@endphp

<section id="spiele" aria-labelledby="play-h" class="flex flex-col border-t border-hairline p-4 lg:gap-4 lg:border-0 lg:p-0" data-test="home-games">
    <div class="mb-2 flex items-center gap-4 lg:mb-0">
        <h2 id="play-h" class="rv-h3 lg:font-display lg:text-xl lg:font-semibold">{{ __('Play in the browser') }}</h2>
        <span class="rv-m max-lg:hidden">{{ __('right away, no copy of your own') }}</span>
        <span class="grow"></span>
        <a href="{{ route('play') }}" @navigate(route('play')) class="rv-lk text-[13px]" data-test="games-all"><span class="max-lg:hidden">{{ $allGames }}</span><span class="lg:hidden">{{ __('All :count', ['count' => $count]) }}</span></a>
    </div>

    {{-- Desktop: tiles --}}
    <div class="hidden grid-cols-[repeat(3,1fr)] gap-4 lg:grid xl:grid-cols-[repeat(4,1fr)]" data-test="browser-games">
        @foreach ($games['browser'] as $game)
            <a href="{{ $game['href'] }}" @navigate($game['href']) class="rv-gt" data-test="game-tile" data-game="{{ $game['slug'] }}">
                <x-game-cover :game="$game['slug']" class="rv-cv" />
                <span class="flex flex-col gap-1"><b>{{ $game['name'] }}</b><x-game-credit :game="$game['slug']" :link="false" /><span class="rv-m">{{ $game['meta'] }}</span></span>
            </a>
        @endforeach
    </div>

    {{-- Phone: a list --}}
    <ul class="m-0 flex list-none flex-col p-0 lg:hidden" data-test="browser-games-list">
        @foreach ($games['browser'] as $game)
            <li>
                <a href="{{ $game['href'] }}" @navigate($game['href']) class="flex items-center gap-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="game-row" data-game="{{ $game['slug'] }}">
                    <x-game-cover :game="$game['slug']" size="thumb" class="h-[54px] w-24 rounded-[2px] [&_img]:object-cover" />
                    <span class="flex min-w-0 grow flex-col"><b>{{ $game['name'] }}</b><x-game-credit :game="$game['slug']" :link="false" /><span class="rv-m">{{ $game['meta'] }}</span></span>
                    <x-icon name="next" :size="16" class="shrink-0 text-ink-3" />
                </a>
            </li>
        @endforeach
    </ul>

    @if ($games['own'] !== [])
        <div class="mt-2 hidden items-center gap-4 lg:flex">
            <h3 class="rv-h3">{{ __('With your own copy') }}</h3>
            <span class="rv-m">{{ __('your own account, the result is reported') }}</span>
        </div>
        <div class="hidden grid-cols-[repeat(3,1fr)] gap-3 lg:grid xl:grid-cols-[repeat(5,1fr)]" data-test="own-games">
            @foreach ($games['own'] as $game)
                <a href="{{ $game['href'] }}" @navigate($game['href']) class="rv-gt" data-test="game-tile" data-game="{{ $game['slug'] }}">
                    <x-game-cover :game="$game['slug']" class="rv-cv" />
                    <span class="flex flex-col gap-1"><b>{{ $game['name'] }}</b><x-game-credit :game="$game['slug']" :link="false" /><span class="rv-m">{{ $game['meta'] }}</span></span>
                </a>
            @endforeach
        </div>
        <a href="{{ route('play') }}" @navigate(route('play')) class="flex min-h-12 items-center gap-2 text-[13px] text-ink-2 hover:text-ink lg:hidden" data-test="own-games-line">
            <span class="grow">{{ __('With your own copy') }}: {{ implode(', ', array_map(fn (array $game): string => \App\Support\GameNames::cube($game['slug']), $games['own'])) }}</span>
            <x-icon name="next" :size="16" class="shrink-0 text-ink-3" />
        </a>
    @endif
</section>
