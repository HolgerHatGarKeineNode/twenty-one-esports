@props(['taken' => 0, 'places' => 0])

{{--
    Seats as a segment bar (design canvas `.seg`, rule R9): one square per place, taken filled orange, free as an
    outline #63636A (3.14:1, WCAG 1.4.11). Never colour alone: the accessible name says the numbers, and the pages
    print "8/12" beside it. Above 16 places the bar keeps 16 squares, filled in proportion.
--}}
@php
    $places = max(0, (int) $places);
    $taken = min(max(0, (int) $taken), $places);
    $squares = min($places, 16);
    $filled = $places === 0 ? 0 : (int) round($taken * $squares / $places);
@endphp

@if ($squares > 0)
    <span role="img" aria-label="{{ __(':taken of :places places taken', ['taken' => $taken, 'places' => $places]) }}" {{ $attributes->class('rv-seg') }} data-test="seats">
        @for ($i = 0; $i < $squares; $i++)
            <i @class(['is-on' => $i < $filled])></i>
        @endfor
    </span>
@endif
