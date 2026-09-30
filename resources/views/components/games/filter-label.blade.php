@props(['game', 'short'])

{{--
    The label of a game filter button (/matches, the cup board): the short
    label (RL, FC27, AoE2) below 2xl, the cover and the name without its
    subtitle from 2xl. The accessible name always starts with the visible
    text (WCAG 2.5.3, label in name): "RL, Rocket League" below 2xl, "Age of
    Empires II: Definitive Edition" from 2xl. Where the two differ, the
    visible text is aria-hidden and an sr-only twin carries the full name
    (one text node, so no stray space: an absolutely positioned sr-only tail
    after the visible text read "Age of Empires II : Definitive Edition").
    sr-only is out of flow, so the button keeps its width to the pixel.
--}}
@php
    $gameName = \App\Support\GameNames::game((string) $game);
    $shownName = \Illuminate\Support\Str::before($gameName, ':');
@endphp
<x-game-cover :game="$game" size="thumb" aria-hidden="true" class="w-8 rounded-xs max-2xl:hidden" />@if ($short === $gameName)<span class="2xl:hidden">{{ $short }}</span>@else<span class="2xl:hidden" aria-hidden="true">{{ $short }}</span><span class="sr-only 2xl:hidden">{{ $short }}, {{ $gameName }}</span>@endif @if ($shownName === $gameName)<span class="max-2xl:hidden">{{ $shownName }}</span>@else<span class="max-2xl:hidden" aria-hidden="true">{{ $shownName }}</span><span class="sr-only max-2xl:hidden">{{ $gameName }}</span>@endif
