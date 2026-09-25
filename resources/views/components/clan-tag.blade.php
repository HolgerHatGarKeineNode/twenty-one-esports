@props(['tag', 'size' => 'md'])

{{-- Clan tag chip from the clan screens: orange on the dark orange tint. --}}
<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center rounded-sm bg-btc-tint font-bold text-btc',
    'h-6 min-w-9 px-1 text-[11px]' => $size === 'md',
    'h-5 min-w-8 px-1 text-[10px]' => $size === 'sm',
]) }}>{{ $tag }}</span>
