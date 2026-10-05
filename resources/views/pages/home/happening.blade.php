{{--
    Happening now: the live stream when it is on air, the newest live blitz
    board to watch (the other live games listed), tournaments being played,
    the latest results with the winners' faces, and who joined the league.
    A part with nothing live says so and offers the action that fills it.

    $live: HomeHub::live(); $running: running tournaments; $results:
    HomeHub::results(); $newcomers: HomeHub::newcomers().
--}}
@php
    $boards = $live['boards'];
    $featured = $boards->first();
    $others = $boards->slice(1);
    // The 24/7 stream (P20) shows here while it is on air, and never otherwise.
    $stream = App\Support\TwentyOne\LiveStatus::current();
    $streamLive = $stream->live;
@endphp

<section aria-labelledby="now-h" class="flex flex-col gap-4 px-4 lg:gap-5 lg:px-12" data-test="happening-now">
    <h2 id="now-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Happening now') }}</h2>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-5">
        {{-- Live boards: the newest one to watch, the others as rows --}}
        <div class="flex flex-col gap-3 rounded-card bg-card p-4 lg:col-span-5 lg:p-5" data-test="live-boards">
            <span class="flex items-baseline justify-between gap-3">
                <span class="flex items-center gap-2 text-[13px] font-bold">
                    @if ($live['live'] + $live['daily'] > 0)
                        <span class="size-2 animate-live rounded-full bg-win" aria-hidden="true"></span>
                    @endif
                    <span data-test="live-boards-count">{{ trans_choice(':count live board|:count live boards', $live['live']) }}, {{ trans_choice(':count daily game|:count daily games', $live['daily']) }}</span>
                </span>
                <a href="{{ route('games.index') }}" @navigate(route('games.index')) class="inline-flex min-h-11 shrink-0 items-center text-xs" data-test="home-live-games">{{ __('Watch all') }}</a>
            </span>

            @if ($featured)
                <a href="{{ route('games.show', $featured) }}" @navigate(route('games.show', $featured)) class="flex flex-col gap-2 text-ink hover:text-ink" data-test="featured-board"
                   x-data="{ cells: window.chessBoardCells(@js($featured->fen), { noCoords: true }), boardLabel: @js(__('Live board of :number', ['number' => $featured->number()])) }">
                    <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$featured->black" :size="24" class="rounded-tag" /><b class="truncate">{{ $featured->black->displayName() }}</b></span>
                    <span class="mx-auto block w-full max-w-72"><x-chess.board /></span>
                    <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$featured->white" :size="24" class="rounded-tag" /><b class="truncate">{{ $featured->white->displayName() }}</b></span>
                    <span class="flex items-center justify-between gap-3 text-xs text-ink-2">
                        <span>{{ $featured->rated ? __('Rated') : __('Casual') }}, {{ __('move :n', ['n' => intdiv($featured->ply, 2) + 1]) }}</span>
                        <span class="inline-flex h-9 items-center gap-1.5 rounded-control bg-raised px-3 font-bold text-ink"><x-icon name="eye" :size="14" />{{ __('Watch') }}</span>
                    </span>
                </a>
                @if ($others->isNotEmpty())
                    <ul class="m-0 list-none p-0">
                        @foreach ($others as $game)
                            <li class="border-t border-hairline">
                                <a href="{{ route('games.show', $game) }}" @navigate(route('games.show', $game)) class="flex min-h-11 items-center gap-1.5 py-1 text-[13px] text-ink hover:bg-row-hover hover:text-ink" data-test="live-board-row">
                                    <x-avatar :user="$game->white" :size="20" class="rounded-tag" /><span class="truncate">{{ $game->white->displayName() }}</span>
                                    <span class="shrink-0 text-ink-3">{{ __('vs') }}</span>
                                    <x-avatar :user="$game->black" :size="20" class="rounded-tag" /><span class="truncate">{{ $game->black->displayName() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="m-0 text-[13px] text-ink-2" data-test="live-empty">{{ __('No board is live right now. Start one.') }}</p>
                <a href="{{ route('chess.lobby') }}" @navigate(route('chess.lobby')) class="btn-s inline-flex h-11 items-center justify-center gap-2 rounded-md border border-edge text-[13px] text-ink hover:text-ink"><x-icon name="bolt" :size="16" />{{ __('Play blitz') }}</a>
            @endif
        </div>

        <div class="flex min-w-0 flex-col gap-4 lg:col-span-7 lg:gap-5">
            @if ($streamLive)
                {{-- The 24/7 stream (P20) while it is on air --}}
                <a href="{{ route('live') }}" @navigate(route('live')) class="flex min-h-14 items-center gap-3 rounded-card bg-card px-4 py-3 text-ink shadow-ring hover:text-ink" data-test="home-stream">
                    <x-live-badge as="span" class="flex" />
                    <b class="grow truncate text-[13px]">{{ $stream->title ?? __('The league stream is on air') }}</b>
                    <span class="text-xs text-ink-2">{{ __('Watch') }}</span>
                </a>
            @endif

            @foreach ($running as $tournament)
                <a href="{{ route('tournaments.show', $tournament) }}" @navigate(route('tournaments.show', $tournament)) class="grid grid-cols-[96px_minmax(0,1fr)_auto] items-center gap-3 rounded-card bg-btc-chip p-2 pr-4 text-ink shadow-ring-btc hover:text-ink" data-test="running-tournament">
                    <x-game-cover :game="$tournament->game" size="thumb" class="w-24 rounded-tag" />
                    <span class="flex min-w-0 flex-col">
                        <b class="truncate font-display text-sm">{{ $tournament->name }}</b>
                        <span class="flex items-center gap-1.5 text-xs text-btc-hi"><span class="size-1.5 animate-live rounded-full bg-btc" aria-hidden="true"></span>{{ __('Being played now') }}</span>
                    </span>
                    <span class="inline-flex items-center gap-1.5 text-xs font-bold"><x-icon name="eye" :size="14" />{{ __('Watch live') }}</span>
                </a>
            @endforeach

            {{-- The latest results, the winner's face first --}}
            <div class="flex flex-col gap-2 rounded-card bg-card p-4 lg:p-5" data-test="results">
                <h3 class="m-0 text-[13px] font-bold">{{ __('Latest results') }}</h3>
                @if ($results === [])
                    <p class="m-0 text-[13px] text-ink-2">{{ __('No results yet. The first win lands here.') }}</p>
                @else
                    <ul class="m-0 grid list-none grid-cols-1 gap-x-5 p-0 sm:grid-cols-2">
                        @foreach ($results as $result)
                            <li class="border-t border-hairline">
                                <a href="{{ $result['href'] }}" @navigate($result['href']) class="grid min-h-14 grid-cols-[36px_minmax(0,1fr)] items-center gap-3 py-2 text-ink hover:bg-row-hover hover:text-ink" data-test="result">
                                    @if ($result['face'])
                                        <x-avatar :user="$result['face']" :size="36" class="rounded-tag" />
                                    @elseif ($result['clan'])
                                        <x-clan-tag :clan="$result['clan']" :tile="36" class="size-9 rounded-tag" />
                                    @else
                                        <span class="flex size-9 items-center justify-center rounded-tag bg-raised text-ink-2" aria-hidden="true"><x-icon name="trophy" :size="16" /></span>
                                    @endif
                                    <span class="flex min-w-0 flex-col">
                                        <span class="truncate text-[13px]">
                                            @if ($result['draw'])
                                                {{ __(':a drew with :b', ['a' => $result['winner'], 'b' => $result['loser']]) }}
                                            @else
                                                <b>{{ $result['winner'] }}</b> {{ __('beat :name', ['name' => $result['loser']]) }}
                                            @endif
                                        </span>
                                        <span class="truncate text-xs text-ink-3">{{ $result['game'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Who joined: faces and clan logos --}}
            <div class="flex flex-col gap-3 rounded-card bg-card p-4 lg:p-5" data-test="newcomers">
                <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                    <h3 class="m-0 text-[13px] font-bold">{{ __('New in the league') }}</h3>
                    <span class="text-xs text-ink-2" data-test="newcomers-week">{{ __('This week: :players, :clans', ['players' => trans_choice(':count new player|:count new players', $newcomers['playersThisWeek']), 'clans' => trans_choice(':count new clan|:count new clans', $newcomers['clansThisWeek'])]) }}</span>
                </span>
                @if ($newcomers['players']->isNotEmpty())
                    <ul class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="{{ __('Newest players') }}">
                        @foreach ($newcomers['players'] as $newcomer)
                            <li>
                                <a href="{{ route('players.show', $newcomer->npub) }}" @navigate(route('players.show', $newcomer->npub)) class="block" title="{{ $newcomer->displayName() }}" data-test="newcomer">
                                    <x-avatar :user="$newcomer" :size="40" class="rounded-tag" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($newcomers['clans']->isNotEmpty())
                    <ul class="m-0 grid list-none grid-cols-1 gap-2 p-0 sm:grid-cols-2">
                        @foreach ($newcomers['clans'] as $clan)
                            <li>
                                <a href="{{ route('clans.show', $clan) }}" @navigate(route('clans.show', $clan)) class="grid min-h-11 grid-cols-[32px_minmax(0,1fr)] items-center gap-2.5 text-[13px] text-ink hover:text-ink" data-test="newcomer-clan">
                                    @if ($clan->localLogoUrl())
                                        <x-clan-tag :clan="$clan" :tile="32" class="size-8 rounded-tag" />
                                    @else
                                        <x-clan-tag :clan="$clan" size="sm" class="min-w-8 px-0.5" />
                                    @endif
                                    <span class="flex min-w-0 flex-col">
                                        <span class="truncate">{{ $clan->name }}</span>
                                        <span class="text-xs text-ink-3">{{ trans_choice(':count player|:count players', $clan->members_count, ['count' => $clan->members_count]) }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <span class="flex flex-wrap gap-2">
                    <a href="{{ route('clans.create') }}" @navigate(route('clans.create')) class="btn-s inline-flex h-11 items-center justify-center rounded-md border border-edge px-4 text-[13px] text-ink hover:text-ink">{{ __('Start a clan') }}</a>
                    <a href="{{ route('clans.index') }}" @navigate(route('clans.index')) class="btn-s inline-flex h-11 items-center justify-center rounded-md border border-edge px-4 text-[13px] text-ink hover:text-ink">{{ __('Join a clan') }}</a>
                </span>
            </div>
        </div>
    </div>
</section>
