@props(['clan' => null, 'tag' => null, 'size' => 'md', 'tile' => null])

{{--
    A clan's mark wherever the clan appears.

    Chip (default): the orange tag chip from the clan screens. When the clan
    has an uploaded logo, the logo sits flush in front of the tag inside the
    same chip, so logo and tag read as one mark and list columns keep their
    rhythm. Without a logo the chip is exactly the plain tag.

    Tile (`tile` = side in px): a square slot that holds the logo, or the
    tag when there is none. The caller sizes and colours the square; the tag
    stays in the markup for screen readers when the logo covers it.

    Only logos the league stored itself are shown (Clan::localLogoUrl()); a
    foreign `picture` falls back to the tag and is never requested. `tag`
    overrides the clan's current tag, for matches that keep the tag they
    were played under.
--}}
@php
    $label = $tag ?? $clan?->clantag;
    $logo = $clan?->localLogoUrl();
    $side = $tile ?? ($size === 'sm' ? 20 : 24);
@endphp

@if ($tile !== null)
    <span {{ $attributes->class(['overflow-hidden' => $logo]) }}>@if ($logo)<img src="{{ $logo }}" alt="" width="{{ $side }}" height="{{ $side }}" loading="lazy" decoding="async" class="size-full bg-card object-cover" data-clan-logo><span class="sr-only">{{ $label }}</span>@else{{ $label }}@endif</span>
@elseif (filled($label) && $logo)
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-stretch overflow-hidden rounded-sm bg-btc-tint font-bold text-btc',
        'h-6 text-[11px]' => $size === 'md',
        'h-5 text-[10px]' => $size === 'sm',
    ]) }}><img src="{{ $logo }}" alt="" width="{{ $side }}" height="{{ $side }}" loading="lazy" decoding="async" class="aspect-square h-full w-auto shrink-0 bg-card object-cover" data-clan-logo><span @class(['inline-flex items-center justify-center px-1', 'min-w-9' => $size === 'md', 'min-w-8' => $size === 'sm'])>{{ $label }}</span></span>
@elseif (filled($label))
    <span {{ $attributes->class([
        'inline-flex shrink-0 items-center justify-center rounded-sm bg-btc-tint font-bold text-btc',
        'h-6 min-w-9 px-1 text-[11px]' => $size === 'md',
        'h-5 min-w-8 px-1 text-[10px]' => $size === 'sm',
    ]) }}>{{ $label }}</span>
@endif
