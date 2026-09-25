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

    if (auth()->user()?->can('admin')) {
        $items[] = ['admin', __('Admin'), route('admin.status')];
    }

    $user = auth()->user();
    $onLogin = request()->routeIs('login');
@endphp

<header class="relative z-30 shrink-0 border-b border-hairline bg-bar" x-data="{ menu: false, search: false }" x-on:keydown.escape.window="menu = false; search = false">
    {{-- Desktop bar (Main.dc.html header, 64 px) --}}
    <div class="hidden h-16 items-center gap-7 px-8 lg:flex">
        <a href="{{ route('home') }}" class="flex min-h-11 shrink-0 items-center gap-2.5 text-ink hover:text-ink" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="36" />
            <span class="flex items-baseline gap-1.5 whitespace-nowrap">
                <span class="font-display text-base font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                <span class="text-xs text-ink-2">esports</span>
            </span>
        </a>

        <span class="hidden h-7 shrink-0 items-center whitespace-nowrap rounded-md bg-btc-chip px-2.5 text-xs text-btc-hi xl:flex">{{ $section === 'chess' ? __('Chess') : __('All games') }} ▾</span>

        <nav class="flex gap-1" aria-label="{{ __('Main navigation') }}">
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

        <label for="site-search" class="sr-only">{{ __('Search') }}</label>
        <input id="site-search" type="search" placeholder="{{ __('Search players, clans or match #') }}"
               class="h-10 w-[420px] min-w-40 shrink rounded-lg border border-edge bg-ground px-3.5 text-[13px] text-ink placeholder:text-ink-3">

        @if ($user)
            <flux:dropdown position="bottom" align="end">
                <button type="button" class="flex h-11 shrink-0 items-center gap-2 rounded-lg border border-line bg-well pr-3 pl-2 text-[13px] text-ink" data-test="account-chip">
                    <x-avatar :name="$user->displayName()" :src="$user->avatarUrl()" :size="26" />
                    <span class="flex min-w-0 flex-col items-start leading-tight">
                        <span class="max-w-40 truncate">{{ $user->displayName() }}</span>
                        <span class="text-[11px] text-ink-3">{{ $user->shortNpub() }}</span>
                    </span>
                </button>

                <flux:menu>
                    <flux:menu.item :href="route('dashboard')" icon="user">{{ __('Your page') }}</flux:menu.item>
                    <flux:menu.item :href="route('gaming.edit')" icon="cog-6-tooth">{{ __('Settings') }}</flux:menu.item>
                    @can('admin')
                        <flux:menu.item :href="route('admin.admins')" icon="shield-check">{{ __('Admin') }}</flux:menu.item>
                    @endcan
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
                   'flex shrink-0 items-center rounded-lg border border-edge text-[13px] font-bold text-ink hover:text-ink',
                   'h-10 bg-well px-3.5' => $onLogin,
                   'btn-s h-11 px-4' => ! $onLogin,
               ])>{{ __('Log in') }}</a>
        @endif
    </div>

    {{-- Mobile bar (MobileHome.dc.html header, 56 px) --}}
    <div class="flex h-14 items-center gap-1 pr-2 pl-4 lg:hidden">
        <a href="{{ route('home') }}" class="flex min-h-11 min-w-0 items-center gap-2.5 text-ink hover:text-ink" aria-label="{{ __('TWENTY ONE esports, home') }}">
            <x-logo :size="32" class="shadow-none" />
            <span class="flex items-baseline gap-1.5 whitespace-nowrap">
                <span class="font-display text-sm font-extrabold tracking-[0.02em]">TWENTY ONE</span>
                <span class="text-xs text-ink-2">esports</span>
            </span>
        </a>

        <span class="grow"></span>

        <button type="button" class="flex size-11 items-center justify-center rounded-lg text-ink-2 hover:text-ink"
                aria-controls="mobile-search" x-bind:aria-expanded="search.toString()"
                x-on:click="search = ! search; menu = false; search && $nextTick(() => $refs.mobileSearch.focus())">
            <span class="sr-only" x-text="search ? @js(__('Close search')) : @js(__('Open search'))">{{ __('Open search') }}</span>
            <x-icon name="search" />
        </button>

        <button type="button" class="flex size-11 items-center justify-center rounded-lg text-ink"
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

    <x-shell.mobile-nav :items="$items" :section="$section" :user="$user" />
</header>
