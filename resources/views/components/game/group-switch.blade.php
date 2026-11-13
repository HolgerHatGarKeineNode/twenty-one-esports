@props(['game', 'active' => 'play', 'mode' => null])

{{--
    The switch between the games of one group (Go: 9×9, 13×13, 19×19; App\Games\Contracts\Grouped), on a game page of
    any of them, right under the game bar: the chip row of the ladder template (x-ui.chips, rules R8 and R11: one line,
    the page's own game current). A chip leads to the same tab of the other game where it has one, else to its page
    (ShellNavigation::groupSwitch()). Nothing for a game on its own, or while one member alone is switched on.
--}}
@php
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $items = $nav->groupSwitch((string) $game, (string) $active, $mode);
@endphp

@if ($items !== [])
    <x-ui.chips :items="$items" :label="\App\Support\GameNames::listed((string) $game)" test="group-switch" size="lg" navigate {{ $attributes }} />
@endif
