@props(['variant' => 'primary', 'href' => null, 'icon' => null])

{{--
    Buttons from the States/Main screens: primary = orange with dark ink,
    secondary = outlined on the ground, quiet = filled well with a hairline.
    Renders a link when `href` is given, otherwise a <button>.
--}}
@php
    $classes = match ($variant) {
        'primary' => 'btn-p bg-btc font-bold text-on-btc hover:text-on-btc',
        'secondary' => 'btn-s border border-edge text-ink hover:text-ink',
        'quiet' => 'btn-w border border-line bg-well text-ink hover:text-ink',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class(['inline-flex h-11 items-center justify-center gap-2 rounded-md px-[18px] text-[13px]', $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="18" />@endif
        {{ $slot }}
    </a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class(['inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-md px-[18px] text-[13px]', $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :size="18" />@endif
        {{ $slot }}
    </button>
@endif
