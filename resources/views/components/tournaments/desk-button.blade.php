@props(['desk' => null, 'drawer' => false])

{{--
    The "Tournament desk" button with its unread badge (App\Support\Tournaments\TournamentDesk; user, 2026-10-03:
    "auch prominent verfügbar für die Spieler"): in the "What to do now" and champion heroes, on a tournament game's
    banner and on the admin edit page. It links to the desk drawer on the tournament page (#desk); on that page a
    click opens the drawer (resources/js/deskButton.js).

    `$desk`: TournamentDesk::for() for the viewer; nothing renders for null (no member, or the desk is closed).
    `$drawer`: the page has the desk's drawer, so the button sends no chat config of its own (the drawer counts).
--}}
@if ($desk)
    <a href="{{ $desk['url'] }}" x-data="deskButton({ desk: {{ (int) $desk['desk'] }}, config: @js($drawer ? null : $desk) })" x-on:click="go($event)"
       :aria-label="unread > 0 ? @js(__('Tournament desk')) + ', ' + @js(__(':count new')).replace(':count', unread) : null"
       {{ $attributes->class('btn-w relative inline-flex h-11 shrink-0 items-center justify-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] font-bold whitespace-nowrap text-ink hover:text-ink') }}
       data-test="desk-button">
        <x-icon name="chat" :size="18" class="shrink-0 text-btc" />
        <span>{{ __('Tournament desk') }}</span>
        <span x-show="unread > 0" x-cloak x-text="unread > 99 ? '99+' : unread" aria-hidden="true"
              class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-btc px-1.5 text-[11px] leading-none font-bold text-on-btc" data-test="desk-unread"></span>
    </a>
@endif
