@props([
    'active' => null,
    'title',
    'lead' => null,
    'crumbs' => [],
    'notice' => null,
    'error' => null,
    'noticeTest' => null,
    'errorTest' => null,
    'flashTest' => 'admin-flash',
])

{{--
    The one frame of every admin page (P17): the admin map (<x-admin.nav>),
    breadcrumbs, the page title with an optional badge beside it, one lead
    line, the page's actions on the right (below on phones), then the
    outcome of the last action (the session `status`, or `notice`/`error`
    of the page) and the page itself.

    Breadcrumbs: Admin (the status page, for an organizer their tournaments),
    the group of the active page as text, then `crumbs`, a list of
    [label, href] pages above this one. The current page is the title, not
    a crumb.

    Slots: `badge` (beside the title), `actions`.
--}}
@php
    $nav = \App\Support\Navigation\AdminNavigation::forCurrentUser();
    $group = \App\Support\Navigation\AdminNavigation::PAGES[$active] ?? null;
    $flash = session('status');
@endphp

<div {{ $attributes->class('flex grow flex-col') }}>
    <x-admin.nav :active="$active" :groups="$nav->groups()" />

    <div class="flex flex-col gap-6 px-4 pt-6 pb-10 lg:px-12 lg:pt-8">
        <header class="flex flex-col gap-2">
            <nav aria-label="{{ __('Breadcrumb') }}" data-test="admin-crumbs">
                <ol class="m-0 flex list-none flex-wrap items-center gap-x-2 p-0 text-xs text-ink-3">
                    <li><a href="{{ $nav->homeHref() }}" class="inline-flex min-h-6 items-center text-ink-2 hover:text-ink">{{ __('Admin') }}</a></li>
                    @if ($group && $nav->isAdmin())
                        <li class="flex items-center gap-2"><span aria-hidden="true">/</span><span>{{ \App\Support\Navigation\AdminNavigation::groupLabel($group) }}</span></li>
                    @endif
                    @foreach ($crumbs as [$label, $href])
                        <li class="flex min-w-0 items-center gap-2"><span aria-hidden="true">/</span><a href="{{ $href }}" class="inline-flex min-h-6 min-w-0 items-center text-ink-2 [overflow-wrap:anywhere] hover:text-ink">{{ $label }}</a></li>
                    @endforeach
                </ol>
            </nav>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between lg:gap-8">
                <div class="flex min-w-0 flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <h1 class="m-0 min-w-0 font-display text-[28px] leading-[1.2] font-bold [overflow-wrap:anywhere] lg:text-[34px]">{{ $title }}</h1>
                        @isset($badge){{ $badge }}@endisset
                    </div>
                    @if ($lead)
                        <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2">{{ $lead }}</p>
                    @endif
                </div>
                @isset($actions)
                    <div class="flex flex-wrap items-center gap-3 lg:shrink-0 lg:justify-end" data-test="admin-actions">{{ $actions }}</div>
                @endisset
            </div>
        </header>

        @if (is_string($flash) && $flash !== '')
            <x-admin.flash :message="$flash" :data-test="$flashTest" />
        @endif
        @if ($notice)
            <x-admin.flash :message="$notice" :data-test="$noticeTest ?? 'admin-notice'" />
        @endif
        @if ($error)
            <x-admin.flash tone="error" :message="$error" :data-test="$errorTest ?? 'admin-error'" />
        @endif

        {{ $slot }}
    </div>
</div>
