@props(['rating', 'label' => null, 'delta' => null, 'compact' => false])

{{--
    One rating for a player strip or a list (P7b), from App\Support\Rating\Ratings.

    Rated: "<label> 1016 · <rank badge>" (Provisional below 5 rated results).
    Casual: "Casual 1016", plus "provisional" below 5 games. A casual rating
    never shows a rank badge: it counts for no rank.
    delta: this game's change, shown as "+16" / "−16" after the value.
    compact: below sm the word before the value ("Casual", the label) is left out.
--}}
@php
    $casual = ($rating['pool'] ?? 'casual') === 'casual';
    $badge = \App\Support\Rating\Ratings::badge($rating['tier'] ?? null);
@endphp

<span {{ $attributes->class('inline-flex min-w-0 flex-wrap items-center gap-x-1 whitespace-nowrap') }} data-test="rating" data-pool="{{ $casual ? 'casual' : 'rated' }}">
    @if ($casual)
        <span @class(['max-sm:hidden' => $compact])>{{ __('Casual') }}</span>
    @elseif ($label)
        <span @class(['max-sm:hidden' => $compact])>{{ $label }}</span>
    @endif
    <b class="text-ink" data-test="rating-value">{{ $rating['rating'] }}</b>
    @if ($delta !== null)
        <b @class(['text-win' => $delta > 0, 'text-loss' => $delta < 0, 'text-ink-2' => $delta === 0]) data-test="rating-delta">{{ $delta > 0 ? '+'.$delta : ($delta < 0 ? '−'.abs($delta) : '±0') }}</b>
    @endif
    @if ($casual)
        @if ($rating['provisional'])<span class="text-ink-3">· {{ __('provisional') }}</span>@endif
    @else
        <span>·</span> <x-rank-badge :tier="$badge['tier']" :level="$badge['level']" size="sm" />
    @endif
</span>
