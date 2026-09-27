{{--
    The LIVE badge (P20): the stream's tally light in the header, shown only
    while the stream is on air (App\Support\TwentyOne\LiveStatus), linking
    to /live. A red dot with a ring that leaves it, the word LIVE and, when
    the stream shares it, how many watch (from 90rem: row 1 has no room for
    it below, measured in German at 1024 and 1280). Renders nothing off air.

    The caller sets the display: `flex`, or e.g. `hidden md:flex lg:hidden`.
    `off-air`: off air, a plain "Live" link in row 1's `.nav-link` style
    instead of nothing, so /live is one click away on desktop either way
    (54 px, the badge 64: row 1 has no room for more, see above).
--}}
@props(['offAir' => false])

@php
    $status = App\Support\TwentyOne\LiveStatus::current();
    $label = $status->viewers === null
        ? __('Live stream on air')
        : trans_choice('Live stream on air, :count watching|Live stream on air, :count watching', $status->viewers);
@endphp

@if ($status->live)
    <a href="{{ route('live') }}" aria-label="{{ $label }}" title="{{ $label }}"
       @if (request()->routeIs('live')) aria-current="page" @endif
       {{ $attributes->class('group min-h-11 shrink-0 items-center text-ink hover:text-ink') }} data-test="live-badge">
        <span class="flex h-7 items-center gap-1.5 rounded-control bg-live-tint px-2 shadow-[inset_0_0_0_1px_var(--color-live-ring)] transition-colors duration-150 group-hover:bg-[#2C1517]">
            <span class="on-air" aria-hidden="true"></span>
            <span class="font-display text-[11px] leading-none font-extrabold tracking-[0.06em]" aria-hidden="true">LIVE</span>
            @if ($status->viewers !== null)
                <span class="hidden pl-0.5 text-xs leading-none text-ink-2 tabular-nums min-[90rem]:inline" aria-hidden="true" data-test="live-badge-viewers">{{ $status->viewers }}</span>
            @endif
        </span>
    </a>
@elseif ($offAir)
    <a href="{{ route('live') }}" aria-label="{{ __('Live stream') }}" @if (request()->routeIs('live')) aria-current="page" @endif
       {{ $attributes->class('nav-link') }} data-test="nav-live">{{ __('Live') }}</a>
@endif
