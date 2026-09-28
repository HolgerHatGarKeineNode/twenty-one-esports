@props(['active' => null, 'groups' => null])

{{--
    The admin map (P17, App\Support\Navigation\AdminNavigation): four groups,
    League, Tournaments, Players & roles and System, the same words the status
    page uses. Wide screens show one row, each group its quiet label and its
    pages; narrower ones fold it into one disclosure that names where you are
    and opens the whole map as rows of 44 px. Admins only: an organizer's
    pages carry just the breadcrumbs (<x-admin.page>).
--}}
@php
    $groups ??= \App\Support\Navigation\AdminNavigation::forCurrentUser()->groups();
    $activeGroup = collect($groups)->first(fn (array $group): bool => collect($group['items'])->contains('key', $active));
    $activeItem = $activeGroup ? collect($activeGroup['items'])->firstWhere('key', $active) : null;
@endphp

@if ($groups !== [])
    <nav aria-label="{{ __('Admin') }}" data-test="admin-nav" {{ $attributes->class('border-b border-hairline') }}>
        {{-- Wide: one row. Group labels are text, not links; a hairline between groups. --}}
        <div class="hidden overflow-x-auto px-4 xl:block xl:px-12">
            <ul class="m-0 flex list-none items-stretch p-0">
                @foreach ($groups as $group)
                    <li class="flex items-stretch">
                        @if (! $loop->first)
                            <span class="mx-3 w-px self-center bg-line h-5" aria-hidden="true"></span>
                        @endif
                        <span id="admin-nav-{{ $group['key'] }}" @class(['inline-flex items-center pr-1 pl-3 text-xs whitespace-nowrap', 'text-ink-2' => $activeGroup && $activeGroup['key'] === $group['key'], 'text-ink-3' => ! $activeGroup || $activeGroup['key'] !== $group['key']])>{{ $group['label'] }}</span>
                        <ul class="m-0 flex list-none p-0" aria-labelledby="admin-nav-{{ $group['key'] }}">
                            @foreach ($group['items'] as $item)
                                <li>
                                    <a href="{{ $item['href'] }}" data-test="{{ $item['test'] }}" @if ($active === $item['key']) aria-current="page" @endif
                                       @class(['inline-flex h-12 items-center gap-2 border-b-2 px-3 text-[13px] whitespace-nowrap', 'border-btc font-bold text-ink hover:text-ink' => $active === $item['key'], 'border-transparent text-ink-2 hover:text-ink' => $active !== $item['key']])>
                                        {{ $item['label'] }}@if ($item['count'] > 0)<x-admin.count :count="$item['count']" />@endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Narrow: one disclosure that says where you are; open, the whole map. --}}
        <details class="group xl:hidden" data-test="admin-nav-menu">
            <summary class="flex h-12 cursor-pointer list-none items-center gap-2 px-4 text-[13px] lg:px-12 [&::-webkit-details-marker]:hidden">
                <span class="text-ink-3">{{ $activeGroup['label'] ?? __('Admin') }}</span>
                @if ($activeItem)
                    <span class="text-ink-3" aria-hidden="true">/</span>
                    <b class="text-ink">{{ $activeItem['label'] }}</b>
                @endif
                <span class="grow"></span>
                <span class="text-xs text-ink-2">{{ __('All admin pages') }}</span>
                <x-icon name="chevron-down" :size="16" class="text-ink-2 transition-transform duration-150 group-open:rotate-180 motion-reduce:transition-none" />
            </summary>
            <div class="grid grid-cols-1 gap-x-8 gap-y-4 border-t border-hairline px-4 pt-3 pb-4 sm:grid-cols-2 lg:grid-cols-4 lg:px-12">
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
    </nav>
@endif
