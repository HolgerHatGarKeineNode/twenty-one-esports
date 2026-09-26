@props(['items', 'games' => [], 'account' => [], 'section' => null, 'user' => null])

{{--
    Mobile navigation behind the header's menu button. MobileHome.dc.html shows the
    closed state only; the open panel reuses the desktop vocabulary: 44 px targets,
    the active item on raised ground in orange, labels next to the icons.

    It renders the header's own lists (P16): the icon bar, then the games menu
    and the account menu, each target once. A link the desktop menus carry is
    here too, so no page is desktop-only. Two columns keep the whole menu of an
    admin inside a phone screen; the panel scrolls if it still does not fit.
--}}
@php
    $seen = array_map(fn (array $entry): string => $entry[2], $items);
    $once = function (array $links) use (&$seen): array {
        $kept = array_values(array_filter($links, fn (array $link): bool => ! in_array($link['href'], $seen, true)));
        array_push($seen, ...array_column($kept, 'href'));

        return $kept;
    };
    $gameGroups = array_values(array_filter(array_map(fn (array $group): array => [$group[0], $once($group[1])], $games), fn (array $group): bool => $group[1] !== []));
    $profile = $account[0] ?? null;
    $accountLinks = $once(array_slice($account, 1));
    $row = 'flex min-h-11 min-w-0 items-center gap-2.5 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink';
@endphp
<div class="lg:hidden" x-show="menu" x-cloak>
    <div class="fixed inset-x-0 top-14 bottom-0 z-30 bg-ground/70" x-on:click="menu = false" aria-hidden="true"
         x-transition:enter="transition-opacity duration-200 ease-out" x-transition:enter-start="opacity-0"
         x-transition:leave="transition-opacity duration-150 ease-in" x-transition:leave-end="opacity-0"></div>

    <div id="mobile-nav" class="absolute inset-x-0 top-full z-40 max-h-[calc(100svh-3.5rem)] overflow-y-auto overscroll-contain border-b border-hairline bg-bar px-2 pt-2 pb-4"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0">
        <nav aria-label="{{ __('Main navigation') }}">
            <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                @foreach ($items as [$key, $label, $href])
                    <li class="min-w-0">
                        <a href="{{ $href }}"
                           @if ($section === $key) aria-current="page" @endif
                           @class([
                               'flex min-h-11 min-w-0 items-center gap-3 rounded-lg px-3 text-sm',
                               'bg-raised font-bold text-btc hover:text-btc' => $section === $key,
                               'text-ink hover:bg-row-hover hover:text-ink' => $section !== $key,
                           ])>
                            <x-icon :name="$key" @class(['shrink-0', 'text-ink-2' => $section !== $key]) />
                            <span class="truncate">{{ $label }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        @foreach ($gameGroups as [$heading, $links])
            <section class="mt-2 border-t border-hairline px-1 pt-2" aria-label="{{ $heading }}">
                <h2 class="m-0 px-2 pb-1 text-xs font-normal text-ink-3">{{ $heading }}</h2>
                <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                    @foreach ($links as $link)
                        <li class="min-w-0">
                            <a href="{{ $link['href'] }}" class="{{ $row }}" @if ($link['mobileTest']) data-test="{{ $link['mobileTest'] }}" @endif>
                                <flux:icon :icon="$link['icon']" variant="mini" class="size-[18px] shrink-0 text-ink-2" />
                                <span class="min-w-0 leading-tight break-words">{{ $link['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach

        <div class="mt-2 flex flex-col gap-2 border-t border-hairline px-1 pt-3">
            @if ($user)
                {{-- The player and "Log out" share one row, so an admin's whole menu fits a 667 px phone. --}}
                <div class="flex items-center gap-2">
                    <a href="{{ $profile['href'] }}" class="flex min-h-11 min-w-0 grow items-center gap-2 rounded-lg px-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink" data-test="{{ $profile['mobileTest'] }}">
                        <x-avatar :user="$user" :size="26" class="shrink-0" />
                        <span class="flex min-w-0 flex-col leading-tight">
                            <span class="truncate">{{ $user->displayName() }}</span>
                            <span class="text-[11px] text-ink-2">{{ $profile['label'] }}</span>
                        </span>
                    </a>
                    {{-- Forget a mill remote signer first, so the next person on this browser does not inherit it. --}}
                    <form method="POST" action="{{ route('logout') }}" x-on:submit="window.forgetNostrSigner?.()" class="shrink-0">
                        @csrf
                        <button type="submit" class="btn-s flex h-11 items-center justify-center gap-2 rounded-lg border border-edge px-3 text-[13px] text-ink">
                            <x-icon name="logout" :size="16" />
                            {{ __('Log out') }}
                        </button>
                    </form>
                </div>
                <ul class="m-0 grid list-none grid-cols-2 gap-1 p-0">
                    @foreach ($accountLinks as $link)
                        <li class="min-w-0">
                            <a href="{{ $link['href'] }}" class="{{ $row }}" @if ($link['mobileTest']) data-test="{{ $link['mobileTest'] }}" @endif>
                                <flux:icon :icon="$link['icon']" variant="mini" class="size-[18px] shrink-0 text-ink-2" />
                                <span class="min-w-0 leading-tight break-words">{{ $link['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <a href="{{ route('login') }}" class="btn-p flex h-11 items-center justify-center rounded-lg bg-btc text-sm font-bold text-on-btc hover:text-on-btc">{{ __('Log in') }}</a>
            @endif
        </div>
    </div>
</div>
