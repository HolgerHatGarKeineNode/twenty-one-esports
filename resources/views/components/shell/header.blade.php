@props(['section' => null])

@php
    $user = auth()->user();
    // Asked once per page: the gate reads the admins table (P5g, it was asked three times).
    $isAdmin = (bool) $user?->can('admin');
    // Organizers an admin unlocked (P8) reach their tournaments from the menu; admins through Admin.
    $isOrganizer = ! $isAdmin && (bool) $user?->isTournamentOrganizer();

    /*
     * Every menu of the shell is built here, once (P16): the icon bar, the
     * games menu and the account menu from lg, and the mobile menu below lg
     * render these same lists, so a page linked in one is linked in all.
     * tests/Browser/NavigationCrawlTest.php compares the targets per role.
     */
    $items = [
        ['home', __('Home'), route('home')],
        ['chess', __('Chess'), route('chess.lobby')],
        ['matches', __('Matches'), route('matches.index')],
        ['tournaments', __('Tournaments'), route('tournaments.index')],
        ['ladder', __('Ladder'), route('ladder.show', ['chess', 'blitz'])],
        ['clans', __('Clans'), route('clans.index')],
        ['mining', __('Mining'), route('mining')],
    ];

    if ($isAdmin) {
        // Disputes: the admin page that has work waiting (Status is still a placeholder).
        $items[] = ['admin', __('Admin'), route('admin.disputes')];
    }

    $item = fn (string $href, string $label, string $icon, ?string $test = null, ?string $mobileTest = null): array => compact('href', 'label', 'icon', 'test', 'mobileTest');

    $games = [
        [__('Chess'), array_values(array_filter([
            $item(route('chess.lobby'), __('Play blitz'), 'bolt'),
            $user ? $item(route('me.correspondence'), __('Your daily games'), 'calendar-days') : null,
            $user ? $item(route('chess.challenge'), __('Challenge a player'), 'paper-airplane', 'games-menu-challenge', 'mobile-challenge-player') : null,
            $item(route('games.index'), __('Watch live games'), 'eye', 'games-menu-live', 'mobile-live-games'),
            $item(route('ladder.show', ['chess', 'blitz']), __('Chess ladder'), 'chart-bar'),
            $user ? $item(route('settings.chess'), __('Chess settings'), 'cog-6-tooth', null, 'mobile-chess-settings') : null,
        ])), 'chess'],
    ];

    // One group per series game of the registry (Rocket League, EA Sports FC 27, EA Sports FC 26); every group heading shows the game's cover.
    $seriesGames = array_keys(app(\App\Games\GameRegistry::class)->series());

    foreach ($seriesGames as $seriesIndex => $slug) {
        // The first series game keeps the hooks and links it had before FC joined (P16 tests, the unfiltered match list).
        $first = $seriesIndex === 0;
        $games[] = [\App\Support\GameNames::game($slug), array_values(array_filter([
            $item(\App\Support\GameNames::page($slug), __('Overview'), 'trophy', 'games-menu-'.$slug, 'mobile-'.$slug),
            $item($first ? route('matches.index') : route('matches.index', ['game' => $slug]), __('Matches'), 'list-bullet'),
            $user ? $item(route('challenges.create', $first ? [] : ['game' => $slug]), __('Challenge a clan'), 'paper-airplane', $first ? 'games-menu-challenge-clan' : 'games-menu-challenge-clan-'.$slug, $first ? 'mobile-challenge-clan' : 'mobile-challenge-clan-'.$slug) : null,
        ])), $slug];
    }

    $account = [];
    $clanLink = null;

    if ($user) {
        // The clan a player is in, else an invite waiting for their answer (P16: both were only a notification away).
        $clan = $user->clanMember?->clan;
        $invite = $clan === null
            ? \App\Models\ClanInvite::query()->where('invitee_id', $user->id)->where('status', \App\Enums\InviteStatus::Pending)->with('clan')->latest()->first()
            : null;
        $clanLink = match (true) {
            $clan !== null => $item(route('clans.show', $clan), __('Your clan'), 'user-group', 'account-clan', 'mobile-clan'),
            $invite !== null => $item(route('invites.show', $invite), __('Clan invite from :clan', ['clan' => $invite->clan->name]), 'envelope', 'account-clan-invite', 'mobile-clan-invite'),
            default => null,
        };

        $account = array_values(array_filter([
            $item(route('players.show', $user->npub), __('Your page'), 'user', 'account-page', 'mobile-page'),
            $clanLink,
            $item(route('me.correspondence'), __('Your daily games'), 'calendar-days'),
            $item(route('gaming.edit'), __('Settings'), 'cog-6-tooth', null, 'mobile-settings'),
            $item(route('settings.chess').'#notifications', __('Notifications'), 'bell', 'account-menu-notifications', 'mobile-notifications'),
            $item(route('settings.chess'), __('Chess settings'), 'adjustments-horizontal'),
            $item(route('settings.badges'), __('Badges and sharing'), 'trophy', 'account-badges', 'mobile-badges'),
            $isAdmin || $isOrganizer ? $item(route('admin.tournaments'), __('Your tournaments'), 'trophy', 'account-tournaments', 'mobile-tournaments') : null,
            $isAdmin ? $item(route('admin.disputes'), __('Admin'), 'shield-check', 'account-admin', 'mobile-admin') : null,
        ]));
    }

    $onLogin = request()->routeIs('login');
    // On the results page the field keeps what was searched.
    $searchTerm = request()->routeIs('search') ? (string) request()->string('q') : '';
@endphp

{{-- An open notification panel lifts the header above the toast stack (z-50), so a toast never covers the list. --}}
<header class="relative z-30 shrink-0 border-b border-hairline bg-bar" x-data="{ menu: false, search: false, bell: false }" x-bind:style="bell ? 'z-index: 55' : ''" x-on:bell-toggle="bell = $event.detail" x-on:keydown.escape.window="menu = false; search = false">
    {{--
        One bar for both sizes, so the notification bell exists once: below lg it
        is the mobile bar (MobileHome.dc.html header, 56 px: logo, bell, search,
        menu), from lg on the desktop bar (Main.dc.html header, 64 px). Items of
        the other size are display:none and take no gap.
    --}}
    <div class="flex h-14 items-center gap-1 pr-2 pl-4 lg:h-16 lg:gap-5 lg:px-8 xl:gap-7">
        <a href="{{ route('home') }}" class="flex min-h-11 min-w-0 items-center gap-2.5 text-ink hover:text-ink lg:hidden" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="32" class="shadow-none" />
            <span class="flex items-baseline gap-1.5 whitespace-nowrap">
                <span class="font-display text-sm font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                <span class="text-xs text-ink-2">esports</span>
            </span>
        </a>

        <a href="{{ route('home') }}" class="hidden min-h-11 shrink-0 items-center gap-2.5 text-ink hover:text-ink lg:flex" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="36" />
            {{-- Between lg and xl the word mark gives its room to the games menu; the logo and the label stay. --}}
            <span class="flex items-baseline gap-1.5 whitespace-nowrap max-xl:sr-only">
                <span class="font-display text-base font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                <span class="text-xs text-ink-2">esports</span>
            </span>
        </a>

        {{-- Games menu: every page of a game, including the ones that have no nav icon. From lg, like the icon bar. --}}
        <flux:dropdown position="bottom" align="start" class="hidden shrink-0 lg:block">
            <button type="button" class="flex h-7 cursor-pointer items-center whitespace-nowrap rounded-md bg-btc-chip px-2.5 text-xs text-btc-hi" data-test="games-menu">{{ $section === 'chess' ? __('Chess') : __('All games') }} ▾</button>

            <flux:menu>
                @foreach ($games as $index => [$heading, $links, $cover])
                    @if ($index > 0)
                        <flux:menu.separator />
                    @endif
                    <flux:menu.group aria-label="{{ $heading }}">
                        <flux:menu.heading><span class="flex items-center gap-2.5"><x-game-cover :game="$cover" size="thumb" class="w-12 rounded-xs" />{{ $heading }}</span></flux:menu.heading>
                        @foreach ($links as $link)
                            <flux:menu.item :href="$link['href']" :icon="$link['icon']" :data-test="$link['test']">{{ $link['label'] }}</flux:menu.item>
                        @endforeach
                    </flux:menu.group>
                @endforeach
            </flux:menu>
        </flux:dropdown>

        <nav class="hidden gap-1 lg:flex" aria-label="{{ __('Main navigation') }}">
            @foreach ($items as [$key, $label, $href])
                <a href="{{ $href }}"
                   aria-label="{{ $label }}"
                   @if ($section === $key) aria-current="page" @endif
                   @class([
                       'group relative flex size-11 items-center justify-center rounded-lg',
                       'bg-raised text-btc hover:text-btc' => $section === $key,
                       'text-ink-2 hover:bg-row-hover hover:text-ink' => $section !== $key,
                   ])>
                    <x-icon :name="$key" />
                    <span aria-hidden="true" class="pointer-events-none absolute top-full left-1/2 z-30 mt-1 -translate-x-1/2 whitespace-nowrap rounded-sm bg-raised px-2 py-1 text-xs text-ink opacity-0 shadow-ring transition-opacity duration-150 group-hover:opacity-100 group-focus-visible:opacity-100">{{ $label }}</span>
                </a>
            @endforeach
        </nav>

        <span class="grow"></span>

        {{-- The site search (P16, SearchController): Enter opens the results, a match number the match. --}}
        <form method="GET" action="{{ route('search') }}" role="search" class="hidden w-[420px] min-w-32 shrink lg:block" data-test="site-search-form">
            <label for="site-search" class="sr-only">{{ __('Search') }}</label>
            <input id="site-search" name="q" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ __('Search players, clans or match #') }}"
                   class="h-10 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
        </form>

        @if ($user)
            <livewire:notification-bell />

            {{-- Between lg and xl the bar has no room for the name (1024 px overflowed by up to 145 px): the chip shows the avatar, the name stays for screen readers. --}}
            <flux:dropdown position="bottom" align="end" class="hidden lg:block">
                {{-- A long name never widens the chip: the chip is capped, the name ends in "…" inside it, and the full name is in the tooltip and at the top of the menu. --}}
                <button type="button" title="{{ $user->displayName() }} · {{ $user->shortNpub() }}" class="flex h-11 max-w-56 min-w-0 shrink-0 items-center gap-2 rounded-lg border border-line bg-well px-2 text-[13px] text-ink xl:pr-3" data-test="account-chip">
                    <x-avatar :user="$user" :size="26" class="shrink-0" />
                    <span class="flex min-w-0 flex-col items-start leading-tight max-xl:sr-only">
                        <span class="block w-full truncate" data-test="account-chip-name">{{ $user->displayName() }}</span>
                        <span class="block w-full truncate text-[11px] text-ink-3">{{ $user->shortNpub() }}</span>
                    </span>
                </button>

                <flux:menu class="max-w-72">
                    <div class="flex items-center gap-2.5 px-2 pt-1.5 pb-2" data-test="account-menu-name">
                        <x-avatar :user="$user" :size="32" class="shrink-0" />
                        <span class="flex min-w-0 flex-col leading-tight">
                            <span class="text-sm font-bold break-words text-ink">{{ $user->displayName() }}</span>
                            <span class="text-[11px] text-ink-3">{{ $user->shortNpub() }}</span>
                        </span>
                    </div>
                    <flux:menu.separator />
                    @foreach ($account as $link)
                        <flux:menu.item :href="$link['href']" :icon="$link['icon']" :data-test="$link['test']">{{ $link['label'] }}</flux:menu.item>
                    @endforeach
                    <flux:menu.separator />
                    {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                    <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">{{ __('Log out') }}</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        @else
            <a href="{{ route('login') }}"
               @if ($onLogin) aria-current="page" @endif
               @class([
                   'hidden shrink-0 items-center rounded-lg border border-edge text-[13px] font-bold text-ink hover:text-ink lg:flex',
                   'h-10 bg-well px-3.5' => $onLogin,
                   'btn-s h-11 px-4' => ! $onLogin,
               ])>{{ __('Log in') }}</a>
        @endif

        <button type="button" class="flex size-11 items-center justify-center rounded-lg text-ink-2 hover:text-ink lg:hidden"
                aria-controls="mobile-search" x-bind:aria-expanded="search.toString()"
                x-on:click="search = ! search; menu = false; search && $nextTick(() => $refs.mobileSearch.focus())">
            <span class="sr-only" x-text="search ? @js(__('Close search')) : @js(__('Open search'))">{{ __('Open search') }}</span>
            <x-icon name="search" />
        </button>

        <button type="button" class="flex size-11 items-center justify-center rounded-lg text-ink lg:hidden"
                aria-controls="mobile-nav" x-bind:aria-expanded="menu.toString()"
                x-on:click="menu = ! menu; search = false">
            <span class="sr-only" x-text="menu ? @js(__('Close menu')) : @js(__('Open menu'))">{{ __('Open menu') }}</span>
            <x-icon name="menu" :size="22" x-show="! menu" />
            <x-icon name="close" :size="22" x-show="menu" x-cloak />
        </button>
    </div>

    <div id="mobile-search" class="border-t border-hairline px-4 py-3 lg:hidden" x-show="search" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
        <form method="GET" action="{{ route('search') }}" role="search">
            <label for="site-search-mobile" class="sr-only">{{ __('Search') }}</label>
            <input id="site-search-mobile" name="q" x-ref="mobileSearch" type="search" value="{{ $searchTerm }}" maxlength="200" enterkeyhint="search" placeholder="{{ __('Search players, clans or match #') }}"
                   class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
        </form>
    </div>

    <x-shell.mobile-nav :items="$items" :games="$games" :account="$account" :section="$section" :user="$user" />
</header>
