@props(['current'])

{{--
    The head of every settings page: one rail of tabs in the same place on each
    page (a left column from lg, a sideways strip below), then the page's own
    heading, which is always the active tab's name
    (P51: the tab once said "General" over a page called "Gaming profile").
    A new settings page adds its tab here and nowhere else.

    The strip scrolls sideways on a narrow screen instead of widening the
    page, and starts scrolled so the active tab is in view. Tabs switch with
    wire:navigate when App\Support\Navigation\Navigate allows it (P6b); the
    pages' own script entries, e.g. push.js, register their Alpine components
    at once when Alpine already runs (resources/js/registerAlpine.js; on
    2026-10-05 the push toggle went dead after a tab switch). The slot sits
    beside the heading (the chess page's "Saved" status).

    The heading steps down below 360 and 640 px so the longest one, the
    German „Benachrichtigungen“, stays one word on one line at 320 px (the
    test browser has no hyphenation dictionary; a break inside the word read
    as „Benachrichtigu/ngen“).
--}}
@php
    // Settings.dc.html (P7, template T-Settings): Account first, the order of the board's rail.
    $tabs = [
        'account' => [route('settings.account'), __('Account'), 'settings-account-tab', 'user'],
        'profile' => [route('settings.profile'), __('Name and Lightning'), 'settings-profile-tab', 'bolt'],
        'gaming' => [route('gaming.edit'), __('Gamer tags'), 'settings-gamer-tags-tab', 'home'],
        'chess' => [route('settings.chess'), __('Board and sounds'), 'settings-chess-tab', 'settings'],
        'notifications' => [route('settings.notifications'), __('Notifications'), 'settings-notifications-tab', 'bell'],
        'opponents' => [route('settings.opponents'), __('Opponents'), 'settings-opponents-tab', 'clans'],
        'badges' => [route('settings.badges'), __('Badges and sharing'), 'settings-badges-tab', 'award'],
        'nip05' => [route('settings.nip05'), __('Nostr address'), 'settings-nostr-address-tab', 'matches'],
    ];
    $viewer = auth()->user();
@endphp

{{--
    From xl the page is two columns (revamp-league.css `.settings-page`): this header's rail on the left, all
    the way down, the heading and the page's own cards on the right. Below xl the rail is the sideways strip.
--}}
<div class="contents" data-test="settings-header">
    <div class="settings-rail flex min-w-0 flex-col gap-4">
        @if ($viewer)
            <a href="{{ route('dashboard') }}" @navigate(route('dashboard')) class="hidden items-center gap-2.5 rounded-[4px] border border-line bg-[linear-gradient(135deg,#14110C,var(--color-card)_70%)] p-3 text-ink hover:text-ink xl:flex" data-test="settings-identity">
                <x-avatar :user="$viewer" :size="32" class="shrink-0 rounded-full" />
                <span class="flex min-w-0 flex-col leading-tight">
                    <b class="truncate text-[14px]">{{ $viewer->displayName() }}</b>
                    <span class="text-[11px] text-ink-3">{{ __('Your page') }}</span>
                </span>
            </a>
            <h2 class="m-0 hidden px-3 text-[15px] font-bold xl:block">{{ __('Settings') }}</h2>
        @endif
        <nav aria-label="{{ __('Settings sections') }}" data-test="settings-tabs"
             x-data x-init="const active = $el.querySelector('[aria-current=page]'); if (active && $el.scrollWidth > $el.clientWidth) { $el.scrollLeft = active.offsetLeft - ($el.clientWidth - active.offsetWidth) / 2 }"
             class="relative flex max-w-full scroll-px-12 overflow-x-auto border-b border-hairline [scrollbar-width:thin] max-xl:pr-8 max-xl:[mask-image:linear-gradient(90deg,#000_calc(100%-40px),transparent)] xl:flex-col xl:gap-1 xl:overflow-visible xl:border-0">
            @foreach ($tabs as $key => [$href, $label, $test, $icon])
                <a href="{{ $href }}" @navigate($href) data-test="{{ $test }}" @if ($key === $current) aria-current="page" @endif
                   @class([
                       'settings-tab flex h-11 shrink-0 items-center gap-2.5 px-3.5 text-[13px] whitespace-nowrap hover:text-ink xl:rounded-[4px] xl:px-3',
                       'font-bold text-ink shadow-[inset_0_-2px_0_var(--color-btc)] xl:bg-btc-tint xl:shadow-[inset_3px_0_0_var(--color-btc)]' => $key === $current,
                       'text-ink-2 xl:hover:bg-row-hover' => $key !== $current,
                   ])><x-icon :name="$icon" :size="16" class="hidden shrink-0 text-ink-3 xl:block" />{{ $label }}</a>
            @endforeach
        </nav>
    </div>

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
        <h1 class="m-0 font-display text-[22px] font-bold min-[360px]:text-[26px] sm:text-[28px] xl:text-[24px]" data-test="settings-heading">{{ $tabs[$current][1] }}</h1>
        {{ $slot }}
    </div>
</div>
