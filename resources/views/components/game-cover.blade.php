@props(['game', 'size' => 'card', 'loading' => 'lazy'])

{{--
    The cover art of a registered game (GameRegistry::cover()), 16:9, as WebP
    with a JPEG fallback, lazy and with its own width and height so the page
    does not jump. `size` only tells the browser which file to pick:
    thumb (a list row), card (a card or picker), header (a page header),
    hero (full width). The width on screen comes from the caller's class.
    `loading="eager"` for a cover at the top of a page (it is then fetched first).
    An unknown game gets a neutral tile with its name, never a broken image.
--}}
@php
    $registry = app(\App\Games\GameRegistry::class);
    $cover = $registry->cover((string) $game);
    $name = \App\Support\GameNames::game((string) $game);
    $sizes = match ($size) {
        'thumb' => '96px',
        'header' => '(min-width: 1024px) 480px, 100vw',
        'hero' => '100vw',
        default => '(min-width: 1024px) 320px, 50vw',
    };
    $srcset = fn (string $format): string => $cover === null ? '' : implode(', ', array_map(fn (int $width): string => asset($cover->path($width, $format)).' '.$width.'w', $cover->widths));
@endphp

@if ($cover !== null)
    <picture {{ $attributes->class('block aspect-video shrink-0 overflow-hidden bg-well')->merge(['data-game-cover' => $game]) }}>
        <source type="image/webp" srcset="{{ $srcset('webp') }}" sizes="{{ $sizes }}">
        <img src="{{ asset($cover->path($cover->smallest(), 'jpg')) }}" srcset="{{ $srcset('jpg') }}" sizes="{{ $sizes }}"
             width="{{ $cover->smallest() }}" height="{{ (int) round($cover->smallest() * 9 / 16) }}"
             alt="{{ __(':game cover', ['game' => $name]) }}" loading="{{ $loading === 'eager' ? 'eager' : 'lazy' }}" decoding="async" @if ($loading === 'eager') fetchpriority="high" @endif
             class="block size-full object-cover">
    </picture>
@else
    <span role="img" aria-label="{{ __(':game cover', ['game' => $name]) }}" data-game-cover-fallback
          {{ $attributes->class('flex aspect-video shrink-0 items-center justify-center overflow-hidden bg-well text-ink-3') }}>
        @if ($size === 'thumb')
            <x-icon name="trophy" :size="16" />
        @else
            <span class="flex flex-col items-center gap-1 px-2 text-center"><x-icon name="trophy" :size="24" /><span class="truncate text-xs">{{ $name }}</span></span>
        @endif
    </span>
@endif
