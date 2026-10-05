@props(['current'])

{{--
    The head of every settings page: one tab strip in the same place on each
    page, then the page's own heading, which is always the active tab's name
    (P51: the tab once said "General" over a page called "Gaming profile").
    A new settings page adds its tab here and nowhere else.

    The strip scrolls sideways on a narrow screen instead of widening the
    page, and starts scrolled so the active tab is in view. Tabs are plain
    links (a full load): the pages' own script entries, e.g. push.js, register
    their Alpine components on alpine:init, which a wire:navigate swap never
    fires again (2026-10-05: the push toggle went dead). The slot sits beside the heading (the chess page's
    "Saved" status).

    The heading steps down below 360 and 640 px so the longest one, the
    German „Benachrichtigungen“, stays one word on one line at 320 px (the
    test browser has no hyphenation dictionary; a break inside the word read
    as „Benachrichtigu/ngen“).
--}}
@php
    $tabs = [
        'gaming' => [route('gaming.edit'), __('Gamer tags'), 'settings-gamer-tags-tab'],
        'account' => [route('settings.account'), __('Account'), 'settings-account-tab'],
        'notifications' => [route('settings.notifications'), __('Notifications'), 'settings-notifications-tab'],
        'chess' => [route('settings.chess'), __('Chess'), 'settings-chess-tab'],
        'opponents' => [route('settings.opponents'), __('Opponents'), 'settings-opponents-tab'],
        'badges' => [route('settings.badges'), __('Badges and sharing'), 'settings-badges-tab'],
        'nip05' => [route('settings.nip05'), __('Nostr address'), 'settings-nostr-address-tab'],
    ];
@endphp

<div class="flex min-w-0 flex-col gap-4" data-test="settings-header">
    <nav aria-label="{{ __('Settings sections') }}" data-test="settings-tabs"
         x-data x-init="const active = $el.querySelector('[aria-current=page]'); if (active && $el.scrollWidth > $el.clientWidth) { $el.scrollLeft = active.offsetLeft - ($el.clientWidth - active.offsetWidth) / 2 }"
         class="relative flex max-w-full overflow-x-auto border-b border-hairline [scrollbar-width:thin]">
        @foreach ($tabs as $key => [$href, $label, $test])
            <a href="{{ $href }}" data-test="{{ $test }}" @if ($key === $current) aria-current="page" @endif
               @class([
                   'flex h-11 shrink-0 items-center px-3.5 text-[13px] whitespace-nowrap hover:text-ink',
                   'font-bold text-ink shadow-[inset_0_-2px_0_var(--color-btc)]' => $key === $current,
                   'text-ink-2' => $key !== $current,
               ])>{{ $label }}</a>
        @endforeach
    </nav>

    <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
        <h1 class="m-0 font-display text-[22px] font-bold min-[360px]:text-[26px] sm:text-[28px] lg:text-[32px]" data-test="settings-heading">{{ $tabs[$current][1] }}</h1>
        {{ $slot }}
    </div>
</div>
