@props(['items', 'section' => null, 'user' => null, 'isAdmin' => false, 'isOrganizer' => false])

{{--
    Mobile navigation behind the header's menu button. MobileHome.dc.html shows the
    closed state only; the open panel reuses the desktop vocabulary: 44 px targets,
    the active item on raised ground in orange, labels next to the icons.
--}}
<div class="lg:hidden" x-show="menu" x-cloak>
    <div class="fixed inset-x-0 top-14 bottom-0 z-30 bg-ground/70" x-on:click="menu = false" aria-hidden="true"
         x-transition:enter="transition-opacity duration-200 ease-out" x-transition:enter-start="opacity-0"
         x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-end="opacity-0"></div>

    <div id="mobile-nav" class="absolute inset-x-0 top-full z-40 border-b border-hairline bg-bar px-2 pt-2 pb-4"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
        <nav aria-label="{{ __('Main navigation') }}">
            <ul class="flex flex-col gap-1">
                @foreach ($items as [$key, $label, $href])
                    <li>
                        <a href="{{ $href }}"
                           @if ($section === $key) aria-current="page" @endif
                           @class([
                               'flex min-h-12 items-center gap-3 rounded-lg px-3 text-sm',
                               'bg-raised font-bold text-btc hover:text-btc' => $section === $key,
                               'text-ink hover:bg-row-hover hover:text-ink' => $section !== $key,
                           ])>
                            <x-icon :name="$key" @class(['text-ink-2' => $section !== $key]) />
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="mt-2 flex flex-col gap-2 border-t border-hairline px-1 pt-3">
            <a href="{{ route('games.index') }}" class="flex min-h-11 items-center gap-3 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink" data-test="mobile-live-games">
                <x-icon name="eye" :size="18" class="text-ink-2" />
                {{ __('Watch live games') }}
            </a>
            @if ($user)
                <a href="{{ route('dashboard') }}" class="flex min-h-11 items-center gap-2 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                    <x-avatar :user="$user" :size="26" />
                    <span class="flex min-w-0 flex-col leading-tight">
                        <span class="truncate">{{ $user->displayName() }}</span>
                        <span class="text-[11px] text-ink-3">{{ $user->shortNpub() }}</span>
                    </span>
                    <span class="grow"></span>
                    <span class="text-xs text-ink-2">{{ __('Your page') }}</span>
                </a>
                <a href="{{ route('me.correspondence') }}" class="flex min-h-11 items-center gap-3 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                    <x-icon name="clock" :size="18" class="text-ink-2" />
                    {{ __('Your daily games') }}
                </a>
                <a href="{{ route('gaming.edit') }}" class="flex min-h-11 items-center gap-3 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                    <x-icon name="user" :size="18" class="text-ink-2" />
                    {{ __('Settings') }}
                </a>
                <a href="{{ route('settings.chess') }}" class="flex min-h-11 items-center gap-3 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink" data-test="mobile-chess-settings">
                    <x-icon name="chess" :size="18" class="text-ink-2" />
                    {{ __('Chess settings') }}
                </a>
                @if ($isAdmin || $isOrganizer)
                    <a href="{{ route('admin.tournaments') }}" class="flex min-h-11 items-center gap-3 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                        <x-icon name="trophy" :size="18" class="text-ink-2" />
                        {{ __('Your tournaments') }}
                    </a>
                @endif
                @if ($isAdmin)
                    <a href="{{ route('admin.admins') }}" class="flex min-h-11 items-center gap-3 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                        <x-icon name="shield-check" :size="18" class="text-ink-2" />
                        {{ __('Admin') }}
                    </a>
                @endif
                {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()">
                    @csrf
                    <button type="submit" class="btn-s flex h-11 w-full items-center justify-center gap-2 rounded-lg border border-edge text-[13px] text-ink">
                        <x-icon name="logout" :size="16" />
                        {{ __('Log out') }}
                    </button>
                </form>
            @else
                <a href="{{ route('login') }}" class="btn-p flex h-11 items-center justify-center rounded-lg bg-btc text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Log in') }}</a>
            @endif
        </div>
    </div>
</div>
