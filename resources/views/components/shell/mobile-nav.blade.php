@props(['active', 'games' => [], 'community' => [], 'chain' => [], 'tournaments' => null, 'admin' => null, 'account' => [], 'section' => null, 'user' => null, 'onGamePage' => false])

{{--
    Phones and tablets (below lg), 1:1 from the design canvas (Header.dc.html "Handy", HomePhone.dc.html):
    the tab bar at the bottom edge, the same five places on every page: Spiele (the game hub), Turniere,
    Mempool, then Aktionen (the match dock) and Du for a player, Live and Anmelden for a guest. The More
    sheet opens from the menu button in the top bar (components/shell/header) and holds the account, the
    pages of every game (Clans, Mempool, Season, Casual, Watch live, Rules, Live stream, Admin), on a game
    page that game's own pages (its tabs move into the shared game template in P4) and the other games.
    The links are the header's own lists (App\Support\Navigation\ShellNavigation), so no page is
    desktop-only (P16). The tab bar reserves its height in --tabbar-h (app.css): the page, the match dock
    and bottom bars of a page sit above it, and the live player (P20) above whatever is highest (`data-live-floor`).
--}}
@php
    $current = request()->url();
    $mempoolLink = collect($chain)->firstWhere('key', 'mempool');
    $open = (int) ($tournaments['open'] ?? 0);
    $profile = $account[0] ?? null;
    $accountLinks = array_slice($account, 1);
    $chainTests = ['mempool' => 'mobile-mempool', 'mining' => 'mobile-season', 'casual' => 'mobile-casual'];
    $everywhere = [
        [...$community[0], 'icon' => 'clans', 'test' => 'mobile-clans'],
        // The chain rail of row 1 (plan "Mempool-Streifen", P4), in its order.
        ...array_map(fn (array $link): array => [...$link, 'test' => $chainTests[$link['key']]], $chain),
        // The strongest players across every game (P40): one page for all games, so here and not in the tab bar.
        ['key' => 'strongest', 'href' => route('ladder.strongest'), 'label' => __('Strongest players'), 'icon' => 'award', 'test' => 'mobile-strongest'],
        ['key' => 'watch', 'href' => route('games.index'), 'label' => __('Watch live'), 'icon' => 'eye', 'test' => 'mobile-live-games'],
        ['key' => 'rules', 'href' => route('rules'), 'label' => __('Rules'), 'icon' => 'list', 'test' => 'mobile-rules'],
        // The 24/7 stream (P20): on phones the header has no room for its badge, so its tally dot shows here.
        ['key' => 'live', 'href' => route('live'), 'label' => __('Live stream'), 'icon' => 'play', 'test' => 'mobile-live'],
    ];
    $liveStatus = \App\Support\TwentyOne\LiveStatus::current();
    $onAir = $liveStatus->live;
    $viewers = $onAir ? $liveStatus->viewers : null;
    $otherGames = array_values(array_filter($games, fn (array $game): bool => $game['slug'] !== $active['slug']));
    $row = 'flex min-h-11 min-w-0 items-center gap-2.5 rounded-md px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink';
@endphp

<div id="mobile-nav" class="lg:hidden" x-data="shellSheet" x-on:keydown.escape.window="close()">
    <div x-show="open" x-cloak aria-hidden="true" class="fixed inset-x-0 top-0 bottom-[var(--tabbar-h)] z-40 bg-ground/70" x-on:click="close()"
         x-transition:enter="transition-opacity duration-200 ease-out" x-transition:enter-start="opacity-0"
         x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-end="opacity-0"></div>

    <div id="more-sheet" tabindex="-1" data-nav-panel role="dialog" aria-modal="false" aria-labelledby="more-sheet-h" x-show="open" x-cloak x-trap.noautofocus.noreturn="open"
         class="sheet fixed inset-x-0 bottom-[var(--tabbar-h)] z-40 max-h-[calc(100svh-var(--tabbar-h)-4.5rem)] overflow-y-auto overscroll-contain rounded-t-xl border-t border-line bg-bar px-3 pt-2 pb-4" data-test="more-sheet">
        <span aria-hidden="true" class="mx-auto mb-1 block h-1 w-10 rounded-xs bg-edge"></span>
        <h2 id="more-sheet-h" class="sr-only">{{ __('More') }}</h2>

        @if ($user && $profile)
            <section aria-label="{{ __('You') }}">
                <h3 class="m-0 px-2 pt-2 pb-1 text-xs font-normal text-ink-3">{{ __('You') }}</h3>
                <div class="flex items-center gap-2">
                    <a href="{{ $profile['href'] }}" @navigate($profile['href']) class="{{ $row }} grow" data-test="{{ $profile['mobileTest'] }}">
                        <x-avatar :user="$user" :size="26" class="shrink-0" />
                        <span class="flex min-w-0 flex-col leading-tight">
                            <span class="truncate">{{ $user->displayName() }}</span>
                            <span class="text-[11px] text-ink-2">{{ $profile['label'] }}</span>
                        </span>
                    </a>
                    {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                    <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()" class="shrink-0">
                        @csrf
                        <button type="submit" class="btn-s flex h-11 cursor-pointer items-center gap-2 rounded-md border border-edge px-3 text-[13px] text-ink">
                            <x-icon name="logout" :size="16" />{{ __('Log out') }}
                        </button>
                    </form>
                </div>
                <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                    @foreach ($accountLinks as $link)
                        <li class="min-w-0">
                            <a href="{{ $link['href'] }}" @navigate($link['href']) class="{{ $row }}" @if ($link['mobileTest']) data-test="{{ $link['mobileTest'] }}" @endif>
                                <x-icon :name="$link['icon']" :size="18" class="text-ink-3" />
                                <span class="min-w-0 leading-tight break-words">{{ $link['label'] }}</span>
                                @isset($link['count'])<span class="nav-count ml-auto">{{ $link['count'] }}</span>@endisset
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            <div class="flex gap-2 px-1 pt-2 pb-1">
                <a href="{{ route('login') }}" class="btn-s flex h-11 grow items-center justify-center rounded-md border border-edge text-sm font-bold text-ink hover:text-ink">{{ __('Log in') }}</a>
                <a href="{{ route('login') }}" class="btn-p flex h-11 grow items-center justify-center rounded-md bg-btc text-sm font-bold text-on-btc hover:text-on-btc" data-test="mobile-start-playing">{{ __('Start playing') }}</a>
            </div>
        @endif

        <section aria-label="{{ __('Everywhere') }}">
            <h3 class="m-0 px-2 pt-3 pb-1 text-xs font-normal text-ink-3">{{ __('Everywhere') }}</h3>
            <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                @foreach ($everywhere as $link)
                    <li class="min-w-0">
                        {{-- The chain rail's links keep their glyph colours here (app.css `.chain-link--*`, `.chain-glyph`). --}}
                        <a href="{{ $link['href'] }}" @navigate($link['href']) @class([$row, 'chain-link--'.$link['key'] => isset($link['name'])]) @if ($link['current'] ?? $section === $link['key']) aria-current="page" @endif
                           @isset($link['name']) aria-label="{{ $link['name'] }}" data-chain-key="{{ $link['key'] }}" @endisset data-test="{{ $link['test'] }}">
                            <x-icon :name="$link['icon']" :size="18" :class="isset($link['name']) ? 'chain-glyph' : 'text-ink-3'" />
                            {{-- The Block 0 tag goes under the label: next to it, "Season" broke into "Sea son" at 375 px. --}}
                            <span class="flex min-w-0 flex-col items-start gap-1 leading-tight break-words">
                                {{ $link['label'] }}
                                @if (($link['tag'] ?? null) !== null)
                                    <span class="nav-tag">{{ $link['tag'] }}</span>
                                @endif
                                @if (($link['count'] ?? null) !== null)
                                    <span class="nav-count" aria-hidden="true" data-test="mobile-mempool-count">{{ $link['count'] }}</span>
                                @endif
                                @if ($link['key'] === 'live')
                                    {{-- On air or not follows the page's live feed (P20b), with the count when the stream shares one. --}}
                                    <span class="flex items-center gap-1.5" data-test="mobile-live-on-air" x-show="$store.live.live" @unless ($onAir) style="display: none" @endunless>
                                        <span class="on-air" aria-hidden="true"></span><span class="font-display text-[10px] leading-none font-extrabold tracking-[0.06em]">LIVE</span>
                                        <span class="inline-block min-w-[3ch] text-[11px] leading-none text-ink-2 tabular-nums" x-show="$store.live.viewers !== null" x-text="$store.live.viewers" x-effect="$store.live.tick($el)" @if ($viewers === null) style="display: none" @endif>{{ $viewers }}</span>
                                    </span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
                @if ($admin)
                    <li class="min-w-0">
                        <a href="{{ $admin['href'] }}" @navigate($admin['href']) class="{{ $row }}" data-test="mobile-admin">
                            <x-icon name="shield-check" :size="18" class="text-ink-3" />
                            <span class="min-w-0 leading-tight">{{ __('Admin') }}</span>
                            @if ($admin['count'] > 0)
                                <span class="nav-count ml-auto">{{ $admin['count'] }}<span class="sr-only"> {{ trans_choice('open case|open cases', $admin['count']) }}</span></span>
                            @endif
                        </a>
                    </li>
                @endif
            </ul>
        </section>

        @if ($onGamePage && $active['actions'] !== [])
            <section aria-label="{{ $active['name'] }}" data-test="more-game">
                <h3 class="m-0 px-2 pt-3 pb-1 text-xs font-normal text-ink-3">{{ $active['name'] }}</h3>
                <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                    @foreach ($active['actions'] as $link)
                        <li class="min-w-0">
                            <a href="{{ $link['href'] }}" @navigate($link['href']) class="{{ $row }}" @if (\App\Support\Navigation\ShellNavigation::isCurrent($link)) aria-current="page" @endif data-test="more-{{ $link['key'] }}">
                                <x-icon :name="$link['icon']" :size="18" class="text-ink-3" />
                                <span class="min-w-0 leading-tight break-words">{{ $link['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($otherGames !== [])
            <section aria-label="{{ __('Other games') }}">
                <h3 class="m-0 px-2 pt-3 pb-1 text-xs font-normal text-ink-3">{{ __('Other games') }}</h3>
                <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                    @foreach ($otherGames as $game)
                        <li class="min-w-0">
                            <a href="{{ $game['page'] }}" @navigate($game['page']) class="{{ $row }}">
                                <x-game-cover :game="$game['slug']" size="thumb" class="w-10 rounded-xs" />
                                <span class="flex min-w-0 flex-col"><span class="leading-tight break-words">{{ $game['name'] }}</span><x-game-credit :game="$game['slug']" :link="false" /></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- The tab bar: five places, labelled, 64 px plus the safe-area inset. --}}
    <nav class="tabbar fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-bar pb-[env(safe-area-inset-bottom)]" aria-label="{{ __('Main navigation') }}" data-test="tab-bar" data-live-floor>
        <div class="rv-pb">
            <button type="button" class="rv-pb-item" aria-controls="game-hub" aria-haspopup="dialog"
                    x-on:click="window.dispatchEvent(new CustomEvent('hub-toggle', { detail: $el }))" data-test="tab-games">
                <x-icon name="grid" :size="22" />
                <span>{{ __('Game list') }}</span>
            </button>
            <a href="{{ route('tournaments.index') }}" @navigate(route('tournaments.index')) class="rv-pb-item" @if ($section === 'tournaments') aria-current="page" @endif
               @if ($open > 0) aria-label="{{ __('Tournaments').', '.$open.' '.trans_choice('open for sign-up|open for sign-up', $open) }}" @endif data-test="tab-tournaments">
                <x-icon name="tournaments" :size="22" />
                <span>{{ __('Tournaments') }}</span>
            </a>
            <a href="{{ $mempoolLink['href'] }}" @navigate($mempoolLink['href']) class="rv-pb-item" @if ($mempoolLink['current']) aria-current="page" @endif aria-label="{{ $mempoolLink['name'] }}" data-test="tab-mempool">
                <x-icon name="matches" :size="22" />
                <span>{{ __('Mempool') }}</span>
            </a>
            @if ($user)
                <a href="{{ route('dashboard') }}" class="rv-pb-item" x-data="dockCount" x-on:click="openDock($event)"
                   x-bind:aria-label="dockOpen > 0 ? @js(__('Actions')).concat(', ', dockOpen, ' ', @js(__('open'))) : @js(__('Actions'))" data-test="tab-actions">
                    <x-icon name="bolt" :size="22" />
                    <span>{{ __('Actions') }}</span>
                    <span class="rv-n is-o" x-show="dockOpen > 0" x-text="dockOpen" x-cloak aria-hidden="true"></span>
                </a>
                <a href="{{ $profile['href'] ?? route('dashboard') }}" @navigate($profile['href'] ?? route('dashboard')) class="rv-pb-item" @if (request()->routeIs('dashboard')) aria-current="page" @endif data-test="tab-you">
                    <x-icon name="user" :size="22" />
                    <span>{{ __('You') }}</span>
                </a>
            @else
                <a href="{{ route('live') }}" class="rv-pb-item" @if (request()->routeIs('live')) aria-current="page" @endif data-test="tab-live">
                    <x-icon name="eye" :size="22" />
                    <span>{{ __('Live') }}</span>
                </a>
                <a href="{{ route('login') }}" class="rv-pb-item" @if (request()->routeIs('login')) aria-current="page" @endif data-test="tab-login">
                    <x-icon name="user" :size="22" />
                    <span>{{ __('Log in') }}</span>
                </a>
            @endif
        </div>
    </nav>
</div>
