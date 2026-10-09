{{--
    Hyperbitcoinization's season ladder (plan "Hyperbitcoinization", P5; HyperMatchController::ladder()), in the look of
    the league's ladder page (pages/ladder/⚡show): the live season, one primary action, a switch between the three
    tables (free-for-all points, 1v1 Elo, team Elo), the rows, and the scoring as chips. Before Block 0 and between
    seasons the empty state says when rated play starts.
--}}
@php
    $tab = 'inline-flex h-11 items-center border-0 px-4 text-[13px] whitespace-nowrap';
    $boards = ['ffa' => __('Free for all'), 'duel' => __('1v1'), 'team' => __('Teams')];
    $points = (array) config('esports.hyper.season_points', []);
    $elo = $board !== 'ffa';
    $grid = 'grid grid-cols-[28px_minmax(0,1fr)_56px] gap-3 lg:grid-cols-[40px_minmax(0,1fr)_96px_96px_64px]';
@endphp
<x-layouts::app :title="__('Hyperbitcoinization ladder')">
    <div class="flex grow flex-col gap-4 px-4 pb-8 lg:gap-6 lg:px-12" data-test="hyper-ladder">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
            <div class="flex min-w-0 flex-col gap-2">
                <h1 class="m-0 flex flex-wrap items-baseline gap-x-3 font-display text-[26px] font-bold lg:text-[34px]">
                    Hyperbitcoinization <span class="font-sans text-xs font-normal text-ink-2">{{ __('Ladder') }}</span>
                </h1>
                <ul class="m-0 flex list-none flex-wrap items-center gap-2 p-0 text-xs" aria-label="{{ __('Season') }}">
                    <li class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 shadow-ring" data-test="hyper-ladder-season">
                        @if ($season !== null)
                            <b>{{ \App\Support\Badges\BadgeCopy::season($season) }}</b>
                        @else
                            <x-icon name="lock" :size="14" />{{ __('Rated play starts at Block 0') }}
                        @endif
                    </li>
                    @if ($mine !== null)
                        <li class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 shadow-ring" data-test="hyper-ladder-mine">
                            <span class="text-ink-2">{{ __('You') }}</span>
                            <b class="tabular-nums">{{ $mine['ffa'] === null ? '–' : __(':points pts', ['points' => $mine['ffa']]) }}</b>
                            <span class="text-ink-3">·</span><span class="text-ink-2">1v1</span><b class="tabular-nums">{{ $mine['duel'] ?? '–' }}</b>
                            <span class="text-ink-3">·</span><span class="text-ink-2">{{ __('Team') }}</span><b class="tabular-nums">{{ $mine['team'] ?? '–' }}</b>
                        </li>
                    @endif
                </ul>
            </div>
            <a href="{{ route('hyper.index') }}" class="btn-p inline-flex min-h-12 items-center justify-center gap-2.5 rounded-md bg-btc px-6 font-display text-[15px] font-bold text-on-btc hover:text-on-btc" data-test="hyper-ladder-play">
                <x-icon name="play" :size="18" />{{ __('Play a season match') }}
            </a>
        </div>

        @if ($cup !== null)
            <a href="{{ route('tournaments.show', $cup) }}" class="flex min-h-14 flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-card px-4 py-2 text-ink shadow-ring hover:bg-row-hover hover:text-ink" data-test="hyper-ladder-cup">
                <x-icon name="trophy" :size="18" class="shrink-0 text-btc" />
                <b class="min-w-0 truncate text-sm">{{ $cup->name }}</b>
                <span class="text-xs text-ink-2">{{ $cup->status === \App\Enums\TournamentStatus::Signup ? __('Sign-up open until :time', ['time' => \App\Support\LeagueTime::stamp($cup->signup_closes_at)]) : __('Running') }}</span>
            </a>
        @endif

        <nav aria-label="{{ __('Table') }}" class="flex self-start overflow-hidden rounded-md border border-edge">
            @foreach ($boards as $key => $label)
                <a href="{{ route('hyper.ladder', $key === 'ffa' ? [] : ['board' => $key]) }}" @if ($key === $board) aria-current="page" @endif data-test="hyper-ladder-board-{{ $key }}"
                   @class([$tab, 'border-l border-edge' => ! $loop->first, 'bg-btc font-bold text-on-btc hover:text-on-btc' => $key === $board, 'bg-ground text-ink-2 hover:text-ink' => $key !== $board])>{{ $label }}</a>
            @endforeach
        </nav>

        @if ($season === null)
            <section class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-8 lg:py-7" data-test="hyper-ladder-preseason">
                <x-empty-state :heading="__('The season starts at Block 0')"
                               :text="__('Until then every match is casual. From Block 0 every match without bots counts: free-for-all for points, 1v1 and clan matches for Elo (start :elo).', ['elo' => $start])">
                    <a href="{{ route('hyper.index') }}" class="btn-p inline-flex h-11 items-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Play a casual match') }}</a>
                </x-empty-state>
            </section>
        @else
            <section aria-labelledby="hyper-ladder-h" class="flex flex-col rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5">
                <h2 id="hyper-ladder-h" class="m-0 px-2 pb-3 text-[15px] font-bold lg:px-0">{{ $boards[$board] }}</h2>
                <div class="{{ $grid }} h-8 items-center border-b border-hairline px-2 text-xs font-bold text-ink-2">
                    <span>#</span>
                    <span>{{ __('Player') }}</span>
                    <span class="text-right">{{ $elo ? __('Elo') : __('Points') }}</span>
                    <span class="hidden text-right lg:block">{{ $elo ? __('W / L') : __('Wins') }}</span>
                    <span class="hidden text-right lg:block">{{ $elo ? __('Results') : __('Matches') }}</span>
                </div>
                @forelse ($rows as $row)
                    @php $wins = $row['user'] === null ? 0 : ($cupWins[$row['user']->id] ?? 0); @endphp
                    <a href="{{ $row['user'] === null ? '#' : route('players.show', $row['user']->npub) }}" class="tr {{ $grid }} min-h-[52px] items-center rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink" data-test="hyper-ladder-row">
                        <span class="text-ink-3 tabular-nums">{{ $row['rank'] }}</span>
                        <span class="flex min-w-0 items-center gap-2">
                            @if ($row['user'] !== null)<x-avatar :user="$row['user']" :size="24" />@endif
                            <span class="truncate font-bold">{{ $row['user']?->displayName() ?? __('Deleted account') }}</span>
                            @if ($wins > 0)
                                <span class="inline-flex h-6 shrink-0 items-center gap-1 rounded-tag bg-btc-chip px-2 text-[11px] font-bold text-btc-hi" title="{{ __('Won the Hyperbitcoinization Weekend Cup') }}" data-test="hyper-cup-badge">
                                    <x-icon name="trophy" :size="12" />{{ $wins > 1 ? '×'.$wins : __('Cup') }}
                                </span>
                            @endif
                        </span>
                        <b class="text-right text-[15px] tabular-nums">{{ $elo ? $row['rating'] : $row['points'] }}</b>
                        <span class="hidden text-right text-ink-2 tabular-nums lg:block">{{ $elo ? $row['wins'].' / '.$row['losses'] : $row['wins'] }}</span>
                        <span class="hidden text-right text-ink-2 tabular-nums lg:block">{{ $elo ? $row['results'] : $row['matches'] }}</span>
                    </a>
                @empty
                    <p class="m-0 px-2 py-6 text-[13px] text-ink-2" data-test="hyper-ladder-empty">{{ __('No rated result this season yet. A match without bots counts.') }}</p>
                @endforelse
            </section>
        @endif

        {{-- How it counts, as chips: the points per table size, forfeits, bots. --}}
        <section aria-labelledby="hyper-ladder-how" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6">
            <h2 id="hyper-ladder-how" class="m-0 text-[15px] font-bold">{{ __('How it counts') }}</h2>
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0 text-xs" data-test="hyper-ladder-rules">
                @foreach ($points as $seats => $table)
                    <li class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 shadow-ring"><span class="text-ink-2">{{ __(':count players', ['count' => $seats]) }}</span><b class="tabular-nums">{{ implode(' · ', $table) }}</b></li>
                @endforeach
                <li class="inline-flex h-8 items-center rounded-md bg-ground px-3 shadow-ring">{{ __('1v1 and clan matches: Elo') }}</li>
                <li class="inline-flex h-8 items-center rounded-md bg-ground px-3 shadow-ring">{{ __('Only matches without bots') }}</li>
                <li class="inline-flex h-8 items-center rounded-md bg-ground px-3 shadow-ring">{{ __('Leaving = last place, 0 points') }}</li>
            </ul>
        </section>
    </div>
</x-layouts::app>
