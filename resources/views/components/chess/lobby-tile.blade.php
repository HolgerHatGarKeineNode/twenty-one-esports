@props(['label', 'icon' => null, 'href' => null, 'variant' => 'default', 'count' => 0, 'countLabel' => null])

{{--
    One way to play in the chess lobby's tile row (pages/chess/⚡lobby).
    A link when `href` is given, a <div> for the muted "soon" tile, else a
    button (the tiles that open their panel under the row pass
    aria-expanded/aria-controls). Below lg one row: glyph, label, meta; from
    lg a column with the glyph on top.

    Slots: `glyph` replaces the icon (Blitz shows its clock, "5+3"), `meta`
    is the one line under the label (counts in <b>, states as tags),
    `detail` a second line from lg (for screen readers below lg too).
    Also the tile row of a board game's lobby (pages/board/partials/
    lobby-play). Below lg 10 px at the sides, and below 360 px the glyph
    sits above the label as from lg: beside it, the text had 88 px at 320,
    and German cut "1 Zug pro Tag" (94 px) and broke "herausford|ern"
    (P5 of plan mempool-streifen, review 2026-09-30).
    `count` > 0 sits on the icon's corner as a number, like an app badge:
    it never takes the label's width. `countLabel` says what it counts.

    variant: default | primary (Blitz: the lobby's one orange tile) | muted
    (not playable yet: dashed outline, no hover, not focusable).
--}}
@php
    $classes = match ($variant) {
        'primary' => 'bg-btc-chip text-ink shadow-ring-btc hover:bg-btc-press hover:text-ink',
        'muted' => 'border border-dashed border-dash text-ink-2',
        default => 'bg-card text-ink shadow-ring hover:bg-row-hover hover:text-ink',
    };
    $base = 'relative flex h-full min-h-16 w-full items-center gap-2 rounded-lg px-2.5 py-2.5 text-left transition-shadow duration-150 motion-reduce:transition-none '
        .'max-[359px]:flex-col max-[359px]:items-start max-[359px]:gap-1.5 aria-expanded:shadow-[inset_0_0_0_2px_var(--color-btc)] lg:min-h-[8.25rem] lg:flex-col lg:items-start lg:justify-start lg:gap-4 lg:p-4';
    $tag = $href !== null ? 'a' : ($variant === 'muted' ? 'div' : 'button');
@endphp

<{{ $tag }} @if ($tag === 'a') href="{{ $href }}" @elseif ($tag === 'button') type="button" @else aria-disabled="true" @endif
    {{ $attributes->class([$base, $classes, 'cursor-pointer' => $tag !== 'div']) }}>
    <span @class(['relative flex min-h-6 min-w-6 shrink-0 items-center lg:min-h-8', 'text-btc' => $variant === 'primary', 'text-ink-2' => $variant === 'default', 'text-ink-3' => $variant === 'muted'])>
        @isset($glyph){{ $glyph }}@else<x-icon :name="$icon" :size="22" />@endisset
        @if ($count > 0)
            <span class="absolute -top-2 -right-1.5 inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-btc px-1 text-[11px] leading-none font-bold text-on-btc shadow-[0_0_0_2px_var(--color-card)]" data-test="{{ $attributes->get('data-test') }}-count">{{ $count }}<span class="sr-only"> {{ $countLabel }}</span></span>
        @endif
    </span>
    <span class="flex min-w-0 grow flex-col gap-0.5 max-[359px]:self-stretch lg:grow-0 lg:self-stretch">
        <b class="text-sm leading-5 break-words">{{ $label }}</b>
        @isset($meta)<span class="truncate text-xs leading-4 text-ink-2">{{ $meta }}</span>@endisset
        @isset($detail)<span class="truncate text-xs leading-4 text-ink-2 max-lg:sr-only">{{ $detail }}</span>@endisset
    </span>
</{{ $tag }}>
