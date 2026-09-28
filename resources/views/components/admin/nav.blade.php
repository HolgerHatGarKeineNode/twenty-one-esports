@props(['active' => null, 'groups' => null])

{{--
    The admin map (P17, App\Support\Navigation\AdminNavigation): four groups,
    League, Tournaments, Players & roles and System, the same words the status
    page uses.

    From 768 px it has two rows. Row 1: the groups as tabs (each opens its
    first page) and "All admin pages" at the end. Row 2: only the pages of the
    active group, on the same surface as the active group tab, so the tab
    visibly opens into its pages. A group label is never inline with page
    links: the width is the widest single group, not the sum of all eleven.
    A count waiting in another group (open cases, payouts) shows on that
    group's tab.

    "All admin pages" opens the whole map, rows of 44 px: below 768 px it is
    the only row and names where you are, above it drops over the page.
    It keeps every admin page one click from any other (the P16 crawl, at
    most two from the chrome). Admins only: an organizer's pages carry just
    the breadcrumbs (<x-admin.page>).
--}}
@php
    $groups ??= \App\Support\Navigation\AdminNavigation::forCurrentUser()->groups();
    $activeGroup = collect($groups)->first(fn (array $group): bool => collect($group['items'])->contains('key', $active));
    $activeItem = $activeGroup ? collect($activeGroup['items'])->firstWhere('key', $active) : null;
@endphp

@if ($groups !== [])
    <nav aria-label="{{ __('Admin') }}" data-test="admin-nav" {{ $attributes->class('relative z-20 border-b border-hairline') }}>
        {{-- Row 1: the groups, then the whole map. --}}
        <div class="flex items-start px-4 lg:px-12">
            <ul class="m-0 hidden list-none p-0 md:flex" data-test="admin-nav-groups">
                @foreach ($groups as $group)
                    @php
                        $isActiveGroup = $activeGroup && $activeGroup['key'] === $group['key'];
                        $waiting = array_sum(array_column($group['items'], 'count'));
                    @endphp
                    <li>
                        <a href="{{ $group['items'][0]['href'] }}" id="admin-nav-{{ $group['key'] }}" data-test="admin-nav-group-{{ $group['key'] }}" @if ($isActiveGroup) aria-current="true" @endif
                           @class(['flex h-11 items-center gap-2 rounded-t-control px-3.5 text-[13px] whitespace-nowrap hover:text-ink', 'bg-well font-bold text-ink' => $isActiveGroup, 'text-ink-2' => ! $isActiveGroup])>
                            {{ $group['label'] }}@if (! $isActiveGroup && $waiting > 0)<x-admin.count :count="$waiting" />@endif
                        </a>
                    </li>
                @endforeach
            </ul>

            {{-- min-w-0: a flex item, it would otherwise grow to its text and push the chevron off a 320 px screen. --}}
            <details class="group min-w-0 grow md:ml-auto md:grow-0" data-test="admin-nav-menu"
                     x-data x-on:click.outside="$el.open = false" x-on:keydown.escape="if ($el.open) { $el.open = false; $el.querySelector('summary').focus() }">
                <summary class="flex h-12 cursor-pointer list-none items-center gap-2 text-[13px] md:h-11 md:px-2 [&::-webkit-details-marker]:hidden">
                    {{-- Phones name the page; the group joins from 640 px ("Tournaments / All tournaments" does not fit at 320), the breadcrumbs below carry it anyway. --}}
                    @if ($activeItem)
                        <span class="hidden shrink-0 text-ink-3 sm:inline md:hidden">{{ $activeGroup['label'] }}</span>
                        <span class="hidden text-ink-3 sm:inline md:hidden" aria-hidden="true">/</span>
                        <b class="min-w-0 truncate text-ink md:hidden">{{ $activeItem['label'] }}</b>
                    @else
                        <span class="text-ink-3 md:hidden">{{ __('Admin') }}</span>
                    @endif
                    <span class="grow md:hidden"></span>
                    <span class="shrink-0 text-xs whitespace-nowrap text-ink-2">{{ __('All admin pages') }}</span>
                    <x-icon name="chevron-down" :size="16" class="shrink-0 text-ink-2 transition-transform duration-150 group-open:rotate-180 motion-reduce:transition-none" />
                </summary>
                <div class="-mx-4 grid grid-cols-1 gap-x-8 gap-y-4 border-t border-hairline px-4 pt-3 pb-4 sm:grid-cols-2 md:absolute md:inset-x-0 md:top-11 md:mx-0 md:grid-cols-4 md:border-y md:border-line md:bg-bar lg:px-12">
                    @foreach ($groups as $group)
                        <div class="flex flex-col">
                            <span id="admin-menu-{{ $group['key'] }}" class="py-1 text-xs text-ink-3">{{ $group['label'] }}</span>
                            <ul class="m-0 flex list-none flex-col p-0" aria-labelledby="admin-menu-{{ $group['key'] }}">
                                @foreach ($group['items'] as $item)
                                    <li>
                                        <a href="{{ $item['href'] }}" data-test="{{ $item['test'] }}-menu" @if ($active === $item['key']) aria-current="page" @endif
                                           @class(['flex min-h-11 items-center gap-2 border-l-2 pl-3 text-[13px]', 'border-btc font-bold text-ink hover:text-ink' => $active === $item['key'], 'border-transparent text-ink-2 hover:text-ink' => $active !== $item['key']])>
                                            {{ $item['label'] }}@if ($item['count'] > 0)<x-admin.count :count="$item['count']" />@endif
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </details>
        </div>

        {{-- Row 2: the pages of the active group, on the surface its tab opens into. --}}
        @if ($activeGroup)
            <div class="hidden bg-well px-4 md:block lg:px-12" data-test="admin-nav-pages">
                <ul class="m-0 flex list-none p-0" aria-labelledby="admin-nav-{{ $activeGroup['key'] }}">
                    @foreach ($activeGroup['items'] as $item)
                        <li>
                            <a href="{{ $item['href'] }}" data-test="{{ $item['test'] }}" @if ($active === $item['key']) aria-current="page" @endif
                               @class(['flex h-11 items-center gap-2 px-3.5 text-[13px] whitespace-nowrap hover:text-ink', 'font-bold text-ink shadow-[inset_0_-2px_0_var(--color-btc)]' => $active === $item['key'], 'text-ink-2' => $active !== $item['key']])>
                                {{ $item['label'] }}@if ($item['count'] > 0)<x-admin.count :count="$item['count']" />@endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </nav>
@endif
