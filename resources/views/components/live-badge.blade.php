{{--
    The LIVE badge (P20): the stream's tally light in the header, linking to
    /live. A red dot with a ring that leaves it, the word LIVE and, when the
    stream shares it, how many watch (from 90rem: at 1280 px a guest's and an
    admin's row 1 kept 5 to 26 px with it, Tournaments included; from 1440 at
    least 72, tests/Browser/LiveCountTest.php).

    It follows the stream while the page is open (P20b): on air, off air and
    the count come from Alpine.store('live'), which resources/js/liveFeed.js
    keeps current; the server's state is the first paint. The count keeps
    room for three digits in tabular figures and ticks when it changes, so
    nothing around it moves.

    The caller sets the display: `flex`, or e.g. `hidden md:flex lg:hidden`.
    `off-air`: off air, a plain "Live" link in row 1's `.nav-link` style
    instead of nothing, so /live is one click away on desktop either way.
    `as="span"`: the badge alone, not a link, for use inside a card that is
    already a link (a nested <a> is split apart by every HTML parser).
    The mark itself is the revamp's `.rv-live` (Header.dc.html): the word LIVE with a pulsing dot, the
    viewers as a count beside it; `compact` (the phone's top bar) puts the count inside the mark, 28 px high.
--}}
@props(['offAir' => false, 'as' => 'a', 'compact' => false])

@php
    $status = App\Support\TwentyOne\LiveStatus::current();
    $labels = [
        'plain' => __('Live stream on air'),
        'one' => trans_choice('Live stream on air, :count watching|Live stream on air, :count watching', 1, ['count' => '#']),
        'many' => trans_choice('Live stream on air, :count watching|Live stream on air, :count watching', 2, ['count' => '#']),
    ];
    $label = $status->viewers === null ? $labels['plain'] : str_replace('#', (string) $status->viewers, $labels[$status->viewers === 1 ? 'one' : 'many']);
    $current = request()->routeIs('live');
@endphp

<{{ $as === 'span' ? 'span' : 'a' }} @if ($as !== 'span') href="{{ route('live') }}" @if ($current) aria-current="page" @endif @endif aria-label="{{ $label }}" title="{{ $label }}"
   x-data="{ labels: @js($labels), get label() { const n = $store.live.viewers; return n === null ? this.labels.plain : this.labels[n === 1 ? 'one' : 'many'].replace('#', n); } }"
   x-show="$store.live.live" x-bind:aria-label="label" x-bind:title="label" @unless ($status->live) style="display: none" @endunless
   {{ $attributes->class('group min-h-11 shrink-0 items-center text-ink hover:text-ink') }} data-test="live-badge">
    <span @class(['rv-live', 'h-7' => $compact])>
        <i aria-hidden="true"></i>
        <span aria-hidden="true">LIVE</span>
        @if ($compact)
            <span class="min-w-[3ch] tabular-nums max-[359px]:hidden" aria-hidden="true" data-test="live-badge-viewers"
                  x-show="$store.live.viewers !== null" x-text="$store.live.viewers" x-effect="$store.live.tick($el)"
                  @if ($status->viewers === null) style="display: none" @endif>{{ $status->viewers }}</span>
        @endif
    </span>
    @unless ($compact)
        <span class="rv-n ml-1.5 hidden min-w-[calc(3ch+12px)] min-[90rem]:inline-grid" aria-hidden="true" data-test="live-badge-viewers"
              x-show="$store.live.viewers !== null" x-text="$store.live.viewers" x-effect="$store.live.tick($el)"
              @if ($status->viewers === null) style="display: none" @endif>{{ $status->viewers }}</span>
    @endunless
</{{ $as === 'span' ? 'span' : 'a' }}>
@if ($offAir)
    <a href="{{ route('live') }}" aria-label="{{ __('Live stream') }}" @if ($current) aria-current="page" @endif
       x-data x-show="! $store.live.live" @if ($status->live) style="display: none" @endif
       {{ $attributes }} data-test="nav-live">{{ __('Live') }}</a>
@endif
