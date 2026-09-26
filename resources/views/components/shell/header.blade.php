@props(['section' => null])

@php
    $items = [
        ['home', __('Home'), route('home')],
        ['chess', __('Chess'), route('chess.lobby')],
        ['matches', __('Matches'), route('matches.index')],
        ['tournaments', __('Tournaments'), route('tournaments.index')],
        ['ladder', __('Ladder'), route('ladder.show', ['chess', 'blitz'])],
        ['clans', __('Clans'), route('clans.index')],
    ];

    $user = auth()->user();
    // Asked once per page: the gate reads the admins table (P5g, it was asked three times).
    $isAdmin = (bool) $user?->can('admin');
    // Organizers an admin unlocked (P8) reach their tournaments from the menu; admins through Admin.
    $isOrganizer = ! $isAdmin && (bool) $user?->isTournamentOrganizer();

    if ($isAdmin) {
        $items[] = ['admin', __('Admin'), route('admin.status')];
    }

    $onLogin = request()->routeIs('login');
@endphp

{{-- An open notification panel lifts the header above the toast stack (z-50), so a toast never covers the list. --}}
<header class="relative z-30 shrink-0 border-b border-hairline bg-bar" x-data="{ menu: false, search: false, bell: false }" x-bind:style="bell ? 'z-index: 55' : ''" x-on:bell-toggle="bell = $event.detail" x-on:keydown.escape.window="menu = false; search = false">
    {{--
        One bar for both sizes, so the notification bell exists once: below lg it
        is the mobile bar (MobileHome.dc.html header, 56 px: logo, bell, search,
        menu), from lg on the desktop bar (Main.dc.html header, 64 px). Items of
        the other size are display:none and take no gap.
    --}}
    <div class="flex h-14 items-center gap-1 pr-2 pl-4 lg:h-16 lg:gap-7 lg:px-8">
        <a href="{{ route('home') }}" class="flex min-h-11 min-w-0 items-center gap-2.5 text-ink hover:text-ink lg:hidden" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="32" class="shadow-none" />
            <span class="flex items-baseline gap-1.5 whitespace-nowrap">
                <span class="font-display text-sm font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                <span class="text-xs text-ink-2">esports</span>
            </span>
        </a>

        <a href="{{ route('home') }}" class="hidden min-h-11 shrink-0 items-center gap-2.5 text-ink hover:text-ink lg:flex" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="36" />
            <span class="flex items-baseline gap-1.5 whitespace-nowrap">
                <span class="font-display text-base font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                <span class="text-xs text-ink-2">esports</span>
            </span>
        </a>

        {{-- Games menu: every page of a game, including the ones that have no nav icon. --}}
        <flux:dropdown position="bottom" align="start" class="hidden shrink-0 xl:block">
            <button type="button" class="flex h-7 cursor-pointer items-center whitespace-nowrap rounded-md bg-btc-chip px-2.5 text-xs text-btc-hi" data-test="games-menu">{{ $section === 'chess' ? __('Chess') : __('All games') }} ▾</button>

            <flux:menu>
                <flux:menu.group :heading="__('Chess')">
                    <flux:menu.item :href="route('chess.lobby')" icon="bolt">{{ __('Play blitz') }}</flux:menu.item>
                    @auth
                        <flux:menu.item :href="route('me.correspondence')" icon="calendar-days">{{ __('Your daily games') }}</flux:menu.item>
                        <flux:menu.item :href="route('chess.challenge')" icon="paper-airplane">{{ __('Challenge a player') }}</flux:menu.item>
                    @endauth
                    <flux:menu.item :href="route('games.index')" icon="eye" data-test="games-menu-live">{{ __('Watch live games') }}</flux:menu.item>
                    <flux:menu.item :href="route('ladder.show', ['chess', 'blitz'])" icon="chart-bar">{{ __('Chess ladder') }}</flux:menu.item>
                    @auth
                        <flux:menu.item :href="route('settings.chess')" icon="cog-6-tooth">{{ __('Chess settings') }}</flux:menu.item>
                    @endauth
                </flux:menu.group>
                <flux:menu.separator />
                <flux:menu.group heading="Rocket League">
                    <flux:menu.item :href="route('games.rocket-league')" icon="trophy">{{ __('Overview') }}</flux:menu.item>
                    <flux:menu.item :href="route('matches.index')" icon="list-bullet">{{ __('Matches') }}</flux:menu.item>
                    @auth
                        <flux:menu.item :href="route('challenges.create')" icon="paper-airplane">{{ __('Challenge a clan') }}</flux:menu.item>
                    @endauth
                </flux:menu.group>
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

        <label for="site-search" class="sr-only max-lg:hidden">{{ __('Search') }}</label>
        <input id="site-search" type="search" placeholder="{{ __('Search players, clans or match #') }}"
               class="hidden h-10 w-[420px] min-w-32 shrink rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3 lg:block">

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
                    <flux:menu.item :href="route('dashboard')" icon="user">{{ __('Your page') }}</flux:menu.item>
                    <flux:menu.item :href="route('me.correspondence')" icon="calendar-days">{{ __('Your daily games') }}</flux:menu.item>
                    <flux:menu.item :href="route('gaming.edit')" icon="cog-6-tooth">{{ __('Settings') }}</flux:menu.item>
                    <flux:menu.item :href="route('settings.chess').'#notifications'" icon="bell" data-test="account-menu-notifications">{{ __('Notifications') }}</flux:menu.item>
                    <flux:menu.item :href="route('settings.chess')" icon="adjustments-horizontal">{{ __('Chess settings') }}</flux:menu.item>
                    <flux:menu.item :href="route('settings.badges')" icon="trophy" data-test="account-badges">{{ __('Badges and sharing') }}</flux:menu.item>
                    @if ($isAdmin)
                        <flux:menu.item :href="route('admin.tournaments')" icon="trophy">{{ __('Your tournaments') }}</flux:menu.item>
                        <flux:menu.item :href="route('admin.admins')" icon="shield-check">{{ __('Admin') }}</flux:menu.item>
                    @elseif ($isOrganizer)
                        <flux:menu.item :href="route('admin.tournaments')" icon="trophy">{{ __('Your tournaments') }}</flux:menu.item>
                    @endif
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
        <label for="site-search-mobile" class="sr-only">{{ __('Search') }}</label>
        <input id="site-search-mobile" x-ref="mobileSearch" type="search" placeholder="{{ __('Search players, clans or match #') }}"
               class="h-11 w-full rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">
    </div>

    <x-shell.mobile-nav :items="$items" :section="$section" :user="$user" :is-admin="$isAdmin" :is-organizer="$isOrganizer" />
</header>
