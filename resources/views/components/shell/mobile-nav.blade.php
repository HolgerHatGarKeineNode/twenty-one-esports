@props(['active', 'games' => [], 'community' => [], 'tournaments' => null,'seasonTag' => null, 'admin' => null, 'account' => [], 'section' => null, 'user' => null])

{{--
    Phones and tablets (below lg, header concept B): the tab bar of the active
    game at the bottom edge (Play, Matches, Ladder, then Tournaments and More)
    and the More sheet above it. More holds the account, the pages of every
    game (Clans, Season, Watch live, Rules, Live stream, Admin) and the other games. The
    links are the header's own lists (App\Support\Navigation\ShellNavigation),
    so no page is desktop-only (P16). The tab bar reserves its height in
    --tabbar-h (app.css): the page, the match dock and bottom bars of a page
    sit above it, and the live player (P20) above whatever is highest
    (`data-live-floor`).
--}}
@php
    $current = request()->fullUrl();
    $tabs = [];
    foreach (['play' => 'bolt', 'matches' => 'matches', 'ladder' => 'ladder'] as $role => $icon) {
        foreach ($active['actions'] as $link) {
            if ($link['tab'] === $role) {
                $tabs[] = ['href' => $link['href'], 'label' => $role === 'play' ? __('Play') : $link['label'], 'icon' => $icon, 'test' => 'tab-'.$role];
            }
        }
    }
    // Tournaments carries a dot while any is open for sign-up; its accessible name says how many (the visible label stays one word).
    $open = (int) ($tournaments['open'] ?? 0);
    $tabs[] = ['href' => route('tournaments.index'), 'label' => __('Tournaments'), 'icon' => 'tournaments', 'test' => 'tab-tournaments',
        'dot' => $open > 0, 'name' => $open > 0 ? __('Tournaments').', '.$open.' '.trans_choice('open for sign-up|open for sign-up', $open) : null];
    $profile = $account[0] ?? null;
    $accountLinks = array_slice($account, 1);
    $everywhere = [
        [...$community[0], 'icon' => 'clans', 'test' => 'mobile-clans'],
        [...$community[1], 'icon' => 'mining', 'test' => 'mobile-season'],
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
                    <a href="{{ $profile['href'] }}" class="{{ $row }} grow" data-test="{{ $profile['mobileTest'] }}">
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
                            <a href="{{ $link['href'] }}" class="{{ $row }}" @if ($link['mobileTest']) data-test="{{ $link['mobileTest'] }}" @endif>
                                <x-icon :name="$link['icon']" :size="18" class="text-ink-3" />
                                <span class="min-w-0 leading-tight break-words">{{ $link['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @else
            <div class="flex gap-2 px-1 pt-2 pb-1">
                <a href="{{ route('login') }}" class="btn-s flex h-11 grow items-center justify-center rounded-md border border-edge text-sm font-bold text-ink hover:text-ink">{{ __('Log in') }}</a>
                <a href="{{ route('login', ['then' => 'play']) }}" class="btn-p flex h-11 grow items-center justify-center rounded-md bg-btc text-sm font-bold text-on-btc hover:text-on-btc" data-test="mobile-start-playing">{{ __('Start playing') }}</a>
            </div>
        @endif

        <section aria-label="{{ __('Everywhere') }}">
            <h3 class="m-0 px-2 pt-3 pb-1 text-xs font-normal text-ink-3">{{ __('Everywhere') }}</h3>
            <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                @foreach ($everywhere as $link)
                    <li class="min-w-0">
                        <a href="{{ $link['href'] }}" class="{{ $row }}" @if ($section === $link['key']) aria-current="page" @endif data-test="{{ $link['test'] }}">
                            <x-icon :name="$link['icon']" :size="18" class="text-ink-3" />
                            {{-- The Block 0 tag goes under the label: next to it, "Season" broke into "Sea son" at 375 px. --}}
                            <span class="flex min-w-0 flex-col items-start gap-1 leading-tight break-words">
                                {{ $link['label'] }}
                                @if ($link['key'] === 'mining' && $seasonTag)
                                    <span class="nav-tag">{{ $seasonTag }}</span>
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
                        <a href="{{ $admin['href'] }}" class="{{ $row }}" data-test="mobile-admin">
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

        @if ($otherGames !== [])
            <section aria-label="{{ __('Other games') }}">
                <h3 class="m-0 px-2 pt-3 pb-1 text-xs font-normal text-ink-3">{{ __('Other games') }}</h3>
                <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                    @foreach ($otherGames as $game)
                        <li class="min-w-0">
                            <a href="{{ $game['page'] }}" class="{{ $row }}">
                                <x-game-cover :game="$game['slug']" size="thumb" class="w-10 rounded-xs" />
                                <span class="min-w-0 leading-tight break-words">{{ $game['name'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- The tab bar: the active game's own tabs, labelled, 64 px plus the safe-area inset. --}}
    <nav class="tabbar fixed inset-x-0 bottom-0 z-40 border-t border-hairline bg-bar pb-[env(safe-area-inset-bottom)]" style="--game: {{ $active['colour'] }}" aria-label="{{ __('Main navigation') }}" data-test="tab-bar" data-live-floor>
        <ul class="m-0 flex list-none p-0">
            @foreach ($tabs as $tab)
                <li class="flex-auto">
                    <a href="{{ $tab['href'] }}" class="tab" @if ($tab['href'] === $current) aria-current="page" @endif @if ($tab['name'] ?? null) aria-label="{{ $tab['name'] }}" @endif data-test="{{ $tab['test'] }}">
                        @if ($tab['dot'] ?? false)
                            <span class="relative flex"><x-icon :name="$tab['icon']" :size="22" /><span class="tab-dot" aria-hidden="true" data-test="tab-tournaments-dot"></span></span>
                        @else
                            <x-icon :name="$tab['icon']" :size="22" />
                        @endif
                        <span>{{ $tab['label'] }}</span>
                    </a>
                </li>
            @endforeach
            <li class="flex-auto">
                <button type="button" class="tab w-full cursor-pointer" aria-controls="more-sheet" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-haspopup="dialog"
                        x-on:click="toggle($el)" data-test="tab-more">
                    <x-icon name="menu" :size="22" />
                    <span>{{ __('More') }}</span>
                </button>
            </li>
        </ul>
    </nav>
</div>
