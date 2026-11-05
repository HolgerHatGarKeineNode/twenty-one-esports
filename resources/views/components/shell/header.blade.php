@props(['section' => null])

@php
    /*
     * The shell header, 1:1 from the design canvas (plan "Refactor und Design-Revamp", Header.dc.html, rule R4):
     * one row for every page. Desktop: 21, Spiele (the game hub), Turniere, Mempool, Season, Clans, LIVE; on the
     * right the search field, then for a player Aktionen (the match dock), the bell (history), Admin (admins
     * only) and the account; a guest gets "Anmelden" alone. Phones: 21, LIVE, search, the bell or "Anmelden",
     * and the menu (the More sheet); the tab bar at the bottom (components/shell/mobile-nav).
     * Every list is built once (App\Support\Navigation\ShellNavigation) and rendered here, in the game hub,
     * the account menu and on phones in the tab bar and the More sheet: a page linked in one is linked in all.
     * On a game page the game's context bar stays under the row (its tabs move into the shared game template in P4).
     */
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $nav->remember();
    $user = $nav->user;
    $games = $nav->playOrder();
    $active = $nav->activeGame();
    $onGamePage = $nav->onGamePage();
    // A page drawn with the game templates (x-game.subnav, revamp P4) carries its own game bar: row 2 stays off there.
    $contextBar = $onGamePage && ! request()->attributes->get('game-subnav', false);
    $community = $nav->community();
    $chain = $nav->chain($section);
    $mempool = collect($chain)->firstWhere('key', 'mempool');
    $season = collect($chain)->firstWhere('key', 'mining');
    $tournaments = $nav->tournaments();
    $admin = $nav->admin();
    $account = $nav->account();
    $upcoming = collect($account)->firstWhere('key', 'upcoming');
    $onLogin = request()->routeIs('login');
    // On the results page the field keeps what was searched.
    $searchTerm = request()->routeIs('search') ? (string) request()->string('q') : '';
    $searchLabel = __('Search players, clans or match #');
    $allGames = trans_choice('All :count game|All :count games', count($games));
    // A tournament game's page (its game-banner, rendered before the header): the chip below, and the page the cup badge may stand for.
    $tournamentBanner = request()->attributes->get('tournament-banner');
    // The path, not the URL: route() writes the app URL, the request its own host. Only this page: a badge for the tournament page
    // ("your match", waiting) says the player is up, which the chip does not, so it stays (PR #20 review, 2026-11-05).
    $bannerLeadsTo = $tournamentBanner ? ['/'.ltrim(request()->path(), '/')] : [];
@endphp

{{--
    Sticky at every width (--shell-h, revamp.css/app.css): the page scrolls under it and anchors and focus land
    below it. An open notification panel, game hub or account menu lifts it above the toast stack and the dock.
--}}
<header class="shell-header sticky top-0 z-30 shrink-0 bg-bar" style="--shell-game: {{ $active['colour'] }}" x-data="shellHeader" x-bind:style="(bell || hub || account) ? { zIndex: 55 } : {}"
        x-on:bell-toggle="bell = $event.detail" x-on:keydown.escape.window="closeSearch(); closeHub()" @if ($contextBar) data-game-page @endif>
    <span class="shell-edge" aria-hidden="true" data-test="shell-edge"></span>

    <div class="flex h-[52px] items-center gap-1 border-b border-hairline pr-2 pl-3 lg:h-14 lg:px-6">
        <a href="{{ route('home') }}" @navigate(route('home')) class="flex h-11 min-w-11 shrink-0 items-center lg:mr-3 lg:min-w-0" aria-label="{{ __('TWENTY ONE esports, home') }}" data-test="shell-home"><span class="rv-logo size-8 lg:size-9" aria-hidden="true">21</span></a>

        <nav class="hidden shrink-0 items-center lg:flex" aria-label="{{ __('Main navigation') }}" data-test="main-nav">
            <button type="button" class="rv-nv" aria-controls="game-hub" x-bind:aria-expanded="hub.toString()" aria-expanded="false" aria-haspopup="dialog"
                    x-on:click="toggleHub($el)" title="{{ $allGames }}" data-test="games-menu">
                {{ __('Game list') }}<span class="sr-only">, {{ $allGames }}</span>
                <x-icon name="chevron-down" :size="14" />
            </button>
            <a href="{{ $tournaments['href'] }}" @navigate($tournaments['href']) @if ($section === 'tournaments') aria-current="page" @endif class="rv-nv" data-test="nav-tournaments">
                {{ $tournaments['label'] }}
                @if ($tournaments['open'] > 0)
                    <span class="rv-n" data-test="tournaments-open"><span class="sr-only">, </span>{{ $tournaments['open'] }}<span class="sr-only"> {{ trans_choice('open for sign-up|open for sign-up', $tournaments['open']) }}</span></span>
                @endif
            </a>
            <a href="{{ $mempool['href'] }}" @navigate($mempool['href']) @if ($mempool['current']) aria-current="page" @endif aria-label="{{ $mempool['name'] }}" class="rv-nv" data-chain-key="mempool" data-test="nav-mempool">
                <x-icon name="matches" :size="14" class="max-xl:hidden" />
                <span>{{ $mempool['label'] }}</span>
                @if ($mempool['count'] !== null)
                    <span class="rv-n" aria-hidden="true" data-test="mempool-count">{{ $mempool['count'] }}</span>
                @endif
            </a>
            <a href="{{ $season['href'] }}" @navigate($season['href']) @if ($season['current']) aria-current="page" @endif aria-label="{{ $season['name'] }}" class="rv-nv" data-test="nav-mining">
                <span>{{ $season['label'] }}</span>
                @if ($season['tag'] !== null)
                    <span class="rv-tag max-xl:hidden" data-test="season-tag">{{ $season['tag'] }}</span>
                @endif
            </a>
            @foreach ($community as $link)
                <a href="{{ $link['href'] }}" @navigate($link['href']) @if ($section === $link['key']) aria-current="page" @endif class="rv-nv" data-test="nav-{{ $link['key'] }}">{{ $link['label'] }}</a>
            @endforeach
            {{-- The stream (P20): its LIVE mark with the viewers on air, a quiet "Live" link like its neighbours off air. --}}
            <x-live-badge class="rv-nv" off-air />
        </nav>

        {{-- Phones: the stream's LIVE mark next to the logo (Header.dc.html "Handy"). --}}
        <x-live-badge class="flex lg:hidden" compact />

        {{-- Phones on a tournament game's page (the page's game-banner tells): a chip with the tournament and its round, always at
             the top, since the header stays (people, 2026-10-15: "eine kleine Anzeige immer oben, ob es sich um ein Turnier-Match
             handelt"). It costs the board nothing; while a banner of the page is in view it gives way, so nothing stands twice. A tap
             opens the whole banner over the page, with the tournament page in it (components/tournaments/game-banner-panel).
             Narrower than its content (fit(), measured on load, on resize and when the row changes) it steps down, one step at a
             time: the name shrinks with "…" and goes when under 40 px or when the round would be cut; then the round goes, the
             trophy alone stays on a 44 px target; where that does not fit either, or the trophy would stand beside the cup badge's
             trophy, the chip steps aside. The round itself is never cut: "R…" or "Runde …" (DerCaddy's 412 px phone and the
             review of PR #20, 2026-11-04/05) says nothing. Steps aside in practice: on air under about 400 px beside a cup badge,
             and for a guest on air up to 390 px, where the sign-in button takes the room. It comes back only when the room it
             needs is there, so a viewer count does not make it flicker. --}}
        @if ($tournamentBanner)
            <button type="button" class="flex h-11 min-w-[34px] shrink cursor-pointer items-center min-[360px]:ml-1 min-[360px]:min-w-11 lg:hidden" x-cloak x-show="shown && ! squeezed" x-on:resize.window.debounce.150ms="fit()"
               x-on:tournament-banner-state.window="open = $event.detail" x-bind:aria-expanded="open.toString()" aria-expanded="false" aria-haspopup="dialog" aria-controls="tournament-banner-sheet"
               x-on:click="$dispatch('tournament-banner', { opener: $el })"
               x-data="{ shown: true, squeezed: false, why: null, need: 0, bare: false, roundless: false, roundW: 0, open: false, io: null, mo: null, init() { this.$watch('shown', () => this.fit()); this.mo = new MutationObserver((list) => { if (list.some((m) => ! this.$el.contains(m.target))) this.fit(); }); this.mo.observe(this.$el.parentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'style'] }); this.fit(); document.fonts?.ready.then(() => this.fit()); const inline = [...document.querySelectorAll('[data-tournament-inline]')]; if (! inline.length) return; this.shown = false; const seen = new Set(); this.io = new IntersectionObserver((entries) => { entries.forEach((e) => e.isIntersecting ? seen.add(e.target) : seen.delete(e.target)); this.shown = seen.size === 0; }, { rootMargin: '-52px 0px 0px 0px' }); inline.forEach((el) => this.io.observe(el)); }, badge() { return this.$el.parentElement.querySelector('[data-test=cup-badge]')?.checkVisibility() ?? false; }, least() { const css = getComputedStyle(this.$el); return Math.max(44, parseFloat(css.minWidth)) + parseFloat(css.marginLeft); }, fit() { if (! this.shown) { this.squeezed = false; return; } const spare = this.$el.nextElementSibling?.getBoundingClientRect().width ?? 0; if (this.squeezed) { if (spare < this.need && ! (this.why === 'badge' && ! this.badge())) return; this.squeezed = false; if (this.why === 'badge') this.roundless = false; } else if (this.roundless && spare >= this.roundW) { this.roundless = false; } else if (this.bare && ! this.roundless && spare >= 40) { this.bare = false; } this.settle(); }, settle() { this.$nextTick(() => requestAnimationFrame(() => { if (! this.shown || this.squeezed) return; const row = this.$el.parentElement; const pill = this.$el.firstElementChild; const name = this.$refs.name; const cut = pill.scrollWidth > pill.clientWidth; if (row.scrollWidth > row.clientWidth + 1) { this.why = 'row'; this.need = this.least(); this.squeezed = true; return; } if (! this.bare && name.scrollWidth > name.clientWidth && (name.clientWidth < 40 || cut)) { this.bare = true; this.settle(); return; } if (! this.roundless && cut) { this.roundW = this.$refs.round.getBoundingClientRect().width + 6; this.roundless = true; this.settle(); return; } if (this.roundless && this.badge()) { this.why = 'badge'; this.need = this.least() + this.roundW; this.squeezed = true; } })); }, destroy() { this.io?.disconnect(); this.mo?.disconnect(); } }" x-bind:style="{ minWidth: roundless ? '44px' : null }"
               aria-label="{{ ($tournamentBanner['cup'] ? __('Cup game') : __('Tournament game')).': '.$tournamentBanner['tournament'].', '.$tournamentBanner['round'] }}" data-test="shell-tournament">
                <span class="flex h-[30px] min-w-0 items-center gap-1.5 overflow-hidden rounded-md bg-btc-tint px-2 text-xs shadow-[inset_0_0_0_1px_var(--color-btc)]" aria-hidden="true">
                    <x-icon name="trophy" :size="14" class="shrink-0 text-btc" />
                    <b class="min-w-0 shrink-[999] truncate text-ink" x-ref="name" x-bind:class="bare && 'hidden'" data-test="shell-tournament-name">{{ $tournamentBanner['tournament'] }}</b>
                    <span class="shrink-0 font-bold whitespace-nowrap text-btc-hi" x-ref="round" x-bind:class="roundless && 'hidden'" data-test="shell-tournament-round">{{ $tournamentBanner['round'] }}</span>
                </span>
            </button>
        @endif

        <span class="grow"></span>

        {{-- The site search (P16, SearchController): from lg a field in the row, on phones a button that opens the field under it. "/" focuses it.
             Row 1 leaves the field what it does not need (21 px of text at 1024 in English, on air with a live cup game): a placeholder
             that does not fit whole is not shown, rather than cut to "Searc" (E3, shellHeader.watchSearchFit). --}}
        <form method="GET" action="{{ route('search') }}" role="search" class="rv-srch mr-2 hidden lg:flex" data-test="search-inline">
            <x-icon name="search" :size="14" class="shrink-0" />
            <label for="site-search-inline" class="sr-only">{{ $searchLabel }}</label>
            <input id="site-search-inline" name="q" x-ref="searchInline" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ __('Search the site') }}"
                   title="{{ $searchLabel }} (/)" aria-keyshortcuts="/">
        </form>
        <button type="button" class="rv-ib lg:hidden" aria-controls="mobile-search" x-bind:aria-expanded="search.toString()" aria-expanded="false" aria-keyshortcuts="/" title="{{ __('Search') }} (/)"
                x-ref="searchToggle" x-on:click="search = ! search; closeHub(false); search && $nextTick(() => $refs.searchField.focus())" data-test="mobile-search-toggle">
            <x-icon name="search" :size="20" />
            <span class="sr-only">{{ __('Search') }}</span>
        </button>

        @if ($user)
            {{-- Aktionen: the match dock's entries (its own count, components/⚡match-dock), the dock opens on click; empty, the player's page. --}}
            {{-- An entry under its limit turns it red with its label and time (ActionDock.dc.html "Zu, Frist unter 15 Min"). --}}
            {{-- The board's 36 px button inside a 44 px target (the header row is 56 px; WCAG 2.5.8 and the house 44 px rule). --}}
            <a href="{{ route('dashboard') }}" class="hidden h-11 shrink-0 items-center lg:inline-flex" x-data="dockCount" x-on:click="openDock($event)" x-bind:title="urgentLabel || null"
               x-bind:aria-label="dockOpen > 0 ? @js(__('Actions')).concat(', ', dockOpen, ' ', @js(__('open'))) : @js(__('Actions'))" data-test="nav-actions">
                <span class="rv-act" x-bind:class="urgentLabel && 'is-hot'">
                    <x-icon name="bolt" :size="14" class="text-btc" x-show="! urgentLabel" />
                    <span class="rv-live h-5 w-6 justify-center px-0" x-show="urgentLabel" x-cloak aria-hidden="true"><i></i></span>
                    <span class="max-xl:hidden" x-show="! urgentLabel">{{ __('Actions') }}</span>
                    {{-- The urgent label shows from 2xl; below, the red edge, the live mark and the time say it (the label stays for screen readers and as the title): with a live cup match its badge stands beside, and the two labels pushed row 1 144 px past 1280 (V1, measured as an admin in German). --}}
                    <span class="whitespace-nowrap" x-show="urgentLabel" x-cloak data-test="actions-urgent"><span class="max-2xl:sr-only" x-text="urgentLabel" data-test="actions-urgent-label"></span> <b class="tabular-nums" x-text="urgentTime"></b></span>
                    <span class="rv-n is-o" x-show="dockOpen > 0" x-text="dockOpen" x-cloak data-test="actions-count"></span>
                </span>
            </a>

            {{-- An open cup match on every page (CupMatchNow; user, 2026-10-03): pulsing while the game is live. On a phone it
                 steps aside only on the page it leads to, the own game, where the header's chip stands for it (DerCaddy,
                 2026-11-04). Anywhere else it stays, "your match" on a game page of its own tournament too: it says the player
                 is up, the chip does not (review of PR #20, 2026-11-05). --}}
            <livewire:cup-match variant="badge" :covered="$bannerLeadsTo" />

            <livewire:notification-bell />

            @if ($admin)
                <a href="{{ $admin['href'] }}" @navigate($admin['href']) @if ($section === 'admin') aria-current="page" @endif class="rv-nv hidden h-11 lg:inline-flex" title="{{ __('Admin') }}" data-test="account-admin">
                    <x-icon name="shield-check" :size="14" />
                    {{-- The word from 90rem: at 1280 its 60 px were the search field's (V1, with a live cup match the field shrank to its icon). --}}
                    <span class="max-[90rem]:sr-only">{{ __('Admin') }}</span>
                    @if ($admin['count'] > 0)
                        <span class="rv-n is-r" data-test="admin-count"><span class="sr-only">, </span>{{ $admin['count'] }}<span class="sr-only"> {{ trans_choice('open case|open cases', $admin['count']) }}</span></span>
                    @endif
                </a>
            @endif

            {{--
                The account menu: a menu button (WAI-ARIA pattern, shellHeader in resources/js/shellNav.js). Enter, Space or
                ArrowDown open it on the first item, ArrowUp on the last; arrows, Home and End move; Escape, Tab or a click outside close it.
            --}}
            <div class="relative ml-1 hidden lg:block" x-on:click.outside="closeAccount(false)" x-on:keydown.escape="if (account) { $event.stopPropagation(); closeAccount() }" x-on:focusout="accountFocusOut($event)">
                <button type="button" title="{{ $user->displayName() }} · {{ $user->shortNpub() }}" class="flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-[4px] px-1 text-ink-3 hover:text-ink"
                        aria-haspopup="menu" aria-controls="account-menu" aria-expanded="false" x-bind:aria-expanded="account.toString()" x-ref="accountChip"
                        x-on:click="toggleAccount()" x-on:keydown.down.prevent="openAccount('first')" x-on:keydown.up.prevent="openAccount('last')" data-test="account-chip">
                    <x-avatar :user="$user" :size="32" class="shrink-0 rounded-full" />
                    <span class="sr-only">{{ __('You') }}</span><span class="sr-only" data-test="account-chip-name">, {{ $user->displayName() }}</span>
                    @if ($upcoming)
                        {{-- Open match rooms and registered tournaments (UpcomingEvents), listed first in the menu. --}}
                        <span class="rv-n" data-test="upcoming-count"><span class="sr-only">, </span>{{ $upcoming['count'] }}<span class="sr-only"> {{ trans_choice('upcoming match or event|upcoming matches and events', $upcoming['count']) }}</span></span>
                    @endif
                    {{-- Below 80rem the avatar alone opens the menu (aria-haspopup says so): its chevron's 22 px keep the search usable beside a live cup match at 1024 (V1). --}}
                    <x-icon name="chevron-down" :size="14" class="max-xl:hidden" />
                </button>

                <div id="account-menu" role="menu" aria-label="{{ __('Account') }}" tabindex="-1" x-ref="accountMenu" x-show="account" x-cloak data-nav-panel
                     x-on:keydown="accountKey($event)" data-test="account-menu"
                     class="absolute top-full right-0 z-50 mt-[5px] w-max max-w-72 min-w-48 rounded-[4px] border border-line bg-row-hover p-1 shadow-[0_8px_24px_rgba(0,0,0,.5)] focus:outline-hidden">
                    {{-- Profile.dc.html (P7): the name leads to the player's own page, then the links in three groups and the language. --}}
                    @php
                        $page = collect($account)->firstWhere('key', 'page');
                        $groups = [
                            'play' => [__('Play'), ['upcoming', 'chats', 'daily', 'tournaments']],
                            'community' => [__('Community'), ['clan', 'invite', 'invite-friend', 'badges']],
                            'account' => [__('Account'), ['settings', 'notifications', 'chess-settings']],
                        ];
                        $grouped = collect($groups)->map(fn (array $group): array => [$group[0], array_values(array_filter($account, fn (array $link): bool => in_array($link['key'], $group[1], true)))]);
                        $loose = array_values(array_filter($account, fn (array $link): bool => $link['key'] !== 'page' && ! collect($groups)->pluck(1)->flatten()->contains($link['key'])));
                    @endphp
                    <div data-test="account-menu-name">
                        <a href="{{ $page['href'] ?? route('dashboard') }}" @navigate($page['href'] ?? route('dashboard')) role="menuitem" tabindex="-1" class="account-item h-auto! gap-2.5 py-2" data-test="account-page">
                            <x-avatar :user="$user" :size="32" class="shrink-0 rounded-full" />
                            <span class="flex min-w-0 flex-col leading-tight">
                                <span class="text-sm font-bold break-words text-ink">{{ $user->displayName() }}</span>
                                <span class="text-[11px] text-ink-3">{{ __('Your page') }}</span>
                            </span>
                        </a>
                    </div>
                    @foreach ($grouped as $key => [$heading, $links])
                        @if ($links !== [] || $key === 'account')
                            <div class="-mx-1 my-1 h-px bg-line" role="separator"></div>
                            <span class="block px-2 pt-1 pb-0.5 text-[11px] text-ink-3" aria-hidden="true" data-test="account-group-{{ $key }}">{{ $heading }}</span>
                            @foreach ($links as $link)
                                <a href="{{ $link['href'] }}" @navigate($link['href']) role="menuitem" tabindex="-1" class="account-item" @if ($link['test']) data-test="{{ $link['test'] }}" @endif><x-icon :name="$link['icon']" :size="16" class="me-2 text-ink-3" />{{ $link['label'] }}@isset($link['count'])<span class="rv-n ms-auto">{{ $link['count'] }}</span>@endisset</a>
                            @endforeach
                            @if ($key === 'account')
                                @foreach ($loose as $link)
                                    <a href="{{ $link['href'] }}" @navigate($link['href']) role="menuitem" tabindex="-1" class="account-item" @if ($link['test']) data-test="{{ $link['test'] }}" @endif><x-icon :name="$link['icon']" :size="16" class="me-2 text-ink-3" />{{ $link['label'] }}</a>
                                @endforeach
                                {{-- The language, two links (the footer's switch as well). --}}
                                <span class="flex items-center gap-2 px-2 py-1 text-[13px] text-ink-2" data-test="account-language">
                                    <x-icon name="globe" :size="16" class="text-ink-3" />{{ __('Language') }}
                                    <span class="grow"></span>
                                    {{-- The language in use is a mark, not a link: only the other one switches (as the footer does). --}}
                                    @foreach (['de' => 'DE', 'en' => 'EN'] as $locale => $code)
                                        @if (app()->getLocale() === $locale)
                                            <span class="flex h-9 min-w-9 items-center justify-center rounded-[4px] border border-btc bg-btc px-2 text-[12px] font-bold text-on-btc" aria-current="true" lang="{{ $locale }}">{{ $code }}</span>
                                        @else
                                            <a href="{{ route('locale.switch', $locale) }}" role="menuitem" tabindex="-1" class="flex h-9 min-w-9 items-center justify-center rounded-[4px] border border-line px-2 text-[12px] font-bold text-ink-2 hover:text-ink" lang="{{ $locale }}">{{ $code }}</a>
                                        @endif
                                    @endforeach
                                </span>
                            @endif
                        @endif
                    @endforeach
                    <div class="-mx-1 my-1 h-px bg-line" role="separator"></div>
                    {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                    <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()">
                        @csrf
                        <button type="submit" role="menuitem" tabindex="-1" class="account-item cursor-pointer"><x-icon name="logout" :size="16" class="me-2 text-ink-3" />{{ __('Log out') }}</button>
                    </form>
                </div>
            </div>
        @else
            <a href="{{ route('login') }}" @if ($onLogin) aria-current="page" @endif class="group flex h-11 shrink-0 items-center" data-test="mobile-login"><span class="rv-b is-sm h-9 group-hover:border-btc-hi group-hover:bg-btc-hi">{{ __('Log in') }}</span></a>
        @endif

        {{-- Phones: the More sheet (Season, Clans, rules, account, Admin) opens from here (Header.dc.html "Handy"). --}}
        <button type="button" class="rv-ib lg:hidden" aria-controls="more-sheet" x-bind:aria-expanded="more.toString()" aria-expanded="false" aria-haspopup="dialog"
                x-on:click="window.dispatchEvent(new CustomEvent('more-toggle', { detail: $el }))" data-test="tab-more">
            <x-icon name="menu" :size="20" />
            <span class="sr-only">{{ __('More') }}@if ($upcoming), {{ trans_choice(':count upcoming match or event|:count upcoming matches and events', $upcoming['count']) }}@endif</span>
            @if ($upcoming)
                <span class="tab-dot" aria-hidden="true" data-test="tab-more-dot"></span>
            @endif
        </button>
    </div>

    @if ($contextBar)
        {{-- Row 2 (from lg, game pages not yet drawn with the game templates): the context bar of the active game. --}}
        <nav class="ctx hidden h-12 items-center gap-1 border-b border-hairline px-6 lg:flex" style="--game: {{ $active['colour'] }}" aria-label="{{ $active['name'] }}" data-test="context-bar" data-game="{{ $active['slug'] }}">
            <span class="ctx-name">{{ $active['name'] }}</span>
            <x-game-credit :game="$active['slug']" class="-ms-2 me-3 whitespace-nowrap" />
            @foreach ($active['actions'] as $link)
                <a href="{{ $link['href'] }}" @navigate($link['href']) @if (\App\Support\Navigation\ShellNavigation::isCurrent($link)) aria-current="page" @endif @class(['ctx-link', 'ctx-link-accent' => $link['accent'] ?? false]) data-test="ctx-{{ $link['key'] }}">
                    <x-icon :name="$link['icon']" :size="16" class="max-[90rem]:hidden" />
                    <span class="min-[90rem]:hidden">{{ $link['short'] }}</span><span class="max-[90rem]:hidden">{{ $link['label'] }}</span>
                </a>
            @endforeach
        </nav>
    @endif

    {{-- The search row, phones only: from lg the field stands in row 1. --}}
    <div id="mobile-search" class="border-b border-hairline px-4 py-3 lg:hidden" x-show="search" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
        <form method="GET" action="{{ route('search') }}" role="search">
            <label for="site-search" class="sr-only">{{ __('Search') }}</label>
            <input id="site-search" name="q" x-ref="searchField" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ $searchLabel }}"
                   aria-keyshortcuts="/" class="h-11 w-full rounded-[4px] border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
        </form>
    </div>

    <x-shell.game-hub :games="$games" :recent="$nav->recent()" :live="$mempool['count'] ?? null" />
</header>

{{--
    Guests: "New here?" right under the header, outside it, on pages the revamp has not redrawn yet. The start page
    and the login page carry their own way in (Mitspielen, "Danach"), so the strip stays off there.
--}}
@guest
    @unless (request()->routeIs('home', 'login'))
        <x-shell.first-steps />
    @endunless
@endguest

<x-shell.mobile-nav :active="$active" :games="$games" :community="$community" :chain="$chain" :tournaments="$tournaments" :admin="$admin" :account="$account" :section="$section" :user="$user" :on-game-page="$onGamePage" />
