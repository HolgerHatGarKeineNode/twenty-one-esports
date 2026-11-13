@props(['games' => [], 'recent' => [], 'live' => null])

{{--
    The games menu (GamesMenu.dc.html, GamesMenu20.dc.html, GamesMenuPhone.dc.html; rules R4, R19): every registered
    game in the registry's order (browser games first, Proof of Pong second, own-copy games after them), split into
    "Im Browser spielen" (played right here) and "Mit eigenem Spiel" (own copy and account). One row per game: the
    16:9 cover as a thumbnail, the name, one data line (HomeBoard::menuLine()); the row opens the game page, whose game
    bar carries its tabs. From 13 games a search field, "Zuletzt gespielt" and the kind chips (Priority+, R8) sit
    above the lists (GamesMenu20); below that the menu is short enough to scan. From lg it drops below the header as a
    panel, below lg it is a bottom sheet above the tab bar: one element for both, so both widths reach the same pages.
--}}
@php
    $browser = array_values(array_filter($games, fn (array $game): bool => $game['browser']));
    $own = array_values(array_filter($games, fn (array $game): bool => ! $game['browser']));
    $count = count($games);
    $filters = $count >= 13;
    // The kind chips by GameKind, in the order of the board; chess is a board game to a player.
    $kindOf = fn (array $game): string => match ($game['kind']) {
        'chess', 'board' => 'board',
        'arcade' => 'arcade',
        'strategy' => 'strategy',
        'score' => 'score',
        default => 'series',
    };
    $kinds = ['board' => __('Tabletop'), 'arcade' => __('Arcade'), 'strategy' => __('Strategy'), 'score' => __('Best time'), 'series' => __('Series')];
    $kindCounts = array_count_values(array_map($kindOf, $games));
    $row = function (array $game) use ($kindOf): array {
        return ['kind' => $kindOf($game), 'name' => mb_strtolower($game['name'].' '.$game['short'].' '.$game['slug'])];
    };
@endphp

<div x-show="hub" x-cloak class="lg:hidden" aria-hidden="true">
    <div class="fixed inset-0 z-40 bg-ground/70" x-on:click="closeHub()"></div>
</div>

<div id="game-hub" tabindex="-1" data-nav-panel role="dialog" aria-modal="false" aria-labelledby="game-hub-h" x-show="hub" x-cloak x-trap.noautofocus.noreturn="hub"
     x-on:click.outside="$event.target.closest('[aria-controls=game-hub]') || closeHub(false)"
     class="gm-panel fixed inset-x-0 bottom-[var(--tabbar-h)] z-50 flex max-h-[calc(100svh-var(--tabbar-h)-4.5rem)] flex-col rounded-t-xl border-x-0 border-b-0 lg:absolute lg:top-[64px] lg:bottom-auto lg:left-[72px] lg:max-h-[calc(100svh-5.5rem)] lg:w-[min(1100px,calc(100vw-144px))] lg:rounded-[4px] lg:border-x lg:border-b"
     data-test="game-hub">
    <h2 id="game-hub-h" class="sr-only">{{ trans_choice('All :count game|All :count games', $count) }}</h2>
    <span aria-hidden="true" class="mx-auto mt-2 h-1 w-10 shrink-0 rounded-xs bg-edge lg:hidden"></span>

    @if ($filters)
        <div class="flex shrink-0 flex-col gap-3 px-4 pt-3 lg:px-6 lg:pt-6" data-test="hub-filters">
            <div class="flex flex-wrap items-center gap-3">
                <label for="hub-filter" class="sr-only">{{ __('Filter games') }}</label>
                <span class="rv-srch h-11 w-full lg:w-80" data-test="hub-search">
                    <x-icon name="search" :size="14" class="shrink-0" />
                    <input id="hub-filter" type="search" x-ref="hubFilter" x-model="filter" autocomplete="off" placeholder="{{ __('Seek') }}" data-test="hub-filter">
                </span>
                @if ($recent !== [])
                    <span class="rv-m max-lg:hidden">{{ __('Recently played') }}</span>
                    <span class="flex gap-2 max-lg:hidden" data-test="hub-recent">
                        @foreach ($recent as $game)
                            <a href="{{ $game['page'] }}" @navigate($game['page']) class="gm-chip gap-2 text-ink" data-test="hub-recent-{{ $game['slug'] }}">
                                <x-game-cover :game="$game['slug']" listed size="thumb" class="h-4 w-7 rounded-[2px] [&_img]:object-cover" />{{ $game['name'] }}
                            </a>
                        @endforeach
                    </span>
                @endif
            </div>
            <div role="group" aria-label="{{ __('Game type') }}" class="flex items-center gap-2 overflow-x-auto" data-test="hub-kinds">
                <span class="rv-m max-lg:hidden">{{ __('Kind') }}</span>
                <button type="button" class="gm-chip" x-on:click="kind = 'all'" x-bind:aria-pressed="(kind === 'all').toString()" aria-pressed="true" data-test="hub-kind-all">{{ __('All') }} {{ $count }}</button>
                @foreach ($kinds as $key => $label)
                    <button type="button" @class(['gm-chip', 'is-dim' => ($kindCounts[$key] ?? 0) === 0]) x-on:click="kind = @js($key)" x-bind:aria-pressed="(kind === @js($key)).toString()" aria-pressed="false" data-test="hub-kind-{{ $key }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="min-h-0 overflow-y-auto overscroll-contain px-4 pt-3 pb-4 lg:p-6" x-ref="hubTiles">
        <div class="gm-cols">
            <section class="flex min-w-0 flex-col gap-2" aria-labelledby="hub-browser-h" data-test="hub-browser">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 id="hub-browser-h" class="rv-h3">{{ __('Play in the browser') }}</h3>
                    <span class="rv-m">{{ __(':count, right away', ['count' => count($browser)]) }}</span>
                    @if ($live !== null && $live > 0)
                        <a href="{{ route('games.index') }}" @navigate(route('games.index')) class="ms-auto flex min-h-6 items-center gap-2 text-ink hover:text-ink max-lg:hidden" data-test="hub-live">
                            <span class="rv-live h-5"><i></i>{{ __('live') }}</span>
                            <b class="font-display text-lg leading-none">{{ $live }}</b>
                            <span class="rv-m">{{ trans_choice('game running right now|games running right now', $live) }}</span>
                        </a>
                    @endif
                </div>
                <ul class="gm-list is-two">
                    @foreach ($browser as $game)
                        @php($meta = $row($game))
                        <li data-name="{{ $meta['name'] }}" data-kinds="{{ $meta['kind'] }}" x-show="shows($el)" data-hub-game="{{ $game['slug'] }}" data-test="hub-game-{{ $game['slug'] }}">
                            <a href="{{ $game['page'] }}" @navigate($game['page']) class="gm-row" data-test="games-menu-{{ $game['slug'] }}">
                                <x-game-cover :game="$game['slug']" listed size="thumb" class="gm-thumb" />
                                <span class="flex min-w-0 flex-col">
                                    <b class="text-sm" title="{{ $game['name'] }}">{{ $game['name'] }}</b>
                                    <x-game-credit :game="$game['slug']" :link="false" />
                                    <span class="rv-m" title="{{ $game['meta'] }}">{{ $game['meta'] }}</span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>

            @if ($own !== [])
                <span class="gm-rule max-lg:hidden" aria-hidden="true"></span>
                <section class="flex min-w-0 flex-col gap-2" aria-labelledby="hub-own-h" data-test="hub-own">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 id="hub-own-h" class="rv-h3">{{ __('With your own copy') }}</h3>
                        <span class="rv-m">{{ __(':count, your own account needed', ['count' => count($own)]) }}</span>
                    </div>
                    <ul class="gm-list">
                        @foreach ($own as $game)
                            @php($meta = $row($game))
                            <li data-name="{{ $meta['name'] }}" data-kinds="{{ $meta['kind'] }}" x-show="shows($el)" data-hub-game="{{ $game['slug'] }}" data-test="hub-game-{{ $game['slug'] }}">
                                <a href="{{ $game['page'] }}" @navigate($game['page']) class="gm-row" data-test="games-menu-{{ $game['slug'] }}">
                                    <x-game-cover :game="$game['slug']" listed size="thumb" class="gm-thumb" />
                                    <span class="flex min-w-0 flex-col">
                                        <b class="text-sm" title="{{ $game['name'] }}">{{ $game['name'] }}</b>
                                        <span class="rv-m" title="{{ $game['meta'] }}">{{ $game['meta'] }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
        <p x-show="! anyShown()" x-cloak class="m-0 py-6 text-sm text-ink-2" data-test="hub-empty">{{ __('No game matches this filter.') }}</p>

        {{-- The pages every game shares, one quiet line: they were links of the old game cards (P16 navigation). --}}
        <div class="mt-4 flex flex-wrap items-center gap-x-6 border-t border-hairline pt-3 text-[13px]" data-test="hub-links">
            <a href="{{ route('play') }}" @navigate(route('play')) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="hub-all-games">{{ __('All games and modes') }}</a>
            <a href="{{ route('games.index') }}" @navigate(route('games.index')) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="games-menu-live">{{ __('Watch live') }}</a>
            {{-- One click from every page (P16 rule, NavigationCrawlTest): the ladders, and for a player the two challenges. --}}
            <a href="{{ route('ladder.show', ['chess', 'rapid']) }}" @navigate(route('ladder.show', ['chess', 'rapid'])) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="hub-ladders">{{ __('Ladders') }}</a>
            <a href="{{ route('ladder.strongest') }}" @navigate(route('ladder.strongest')) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="hub-strongest">{{ __('Strongest players') }}</a>
            @auth
                <a href="{{ route('challenge') }}" @navigate(route('challenge')) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="hub-challenge-player">{{ __('Challenge a player') }}</a>
                <a href="{{ route('challenges.create') }}" @navigate(route('challenges.create')) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="hub-challenge-clan">{{ __('Challenge a clan') }}</a>
            @endauth
            <a href="{{ route('matches.index', ['chain' => 'casual']) }}" @navigate(route('matches.index', ['chain' => 'casual'])) class="flex min-h-11 items-center text-ink-2 hover:text-ink" data-test="hub-casual">{{ __('Casual chain') }}</a>
        </div>
    </div>
</div>
