@props(['game', 'active' => 'play', 'mode' => null])

{{--
    The switch between the games of one group (Go: 9×9, 13×13, 19×19; App\Games\Contracts\Grouped), on a game page of
    any of them, right under the game bar, in the same place on the lobby and the ladder (R5). One control of its own
    (R11), not a filter row: the members are a fixed choice in the registry's order, one of them is the page's own. They
    stand as equal segments in one track of 44 px, one line, no label wraps (revamp-games.css `.gs-sizes`); the page's
    own is the raised one with the light edge and the bold white name, as Go's "Show area" when it is on, never the
    orange of an action. A phone gives the track the page's width, a third each; from lg the group's name stands
    before it ("Go" 9×9, 13×13, 19×19). A segment leads to the same tab of the other game where it has one, else to
    its page (ShellNavigation::groupSwitch()). Nothing for a game on its own, or while one member alone is switched on.
--}}
@php
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $items = $nav->groupSwitch((string) $game, (string) $active, $mode);
    $group = \App\Support\GameNames::listed((string) $game);
@endphp

@if ($items !== [])
    <nav {{ $attributes->class('gs-sizes') }} aria-label="{{ $group }}" data-test="group-switch">
        <span class="gs-sizes-name" aria-hidden="true">{{ $group }}</span>
        <div class="gs-sizes-track" style="--members: {{ count($items) }}">
            @foreach ($items as $item)
                <a href="{{ $item['href'] }}" @navigate($item['href']) @if ($item['active']) aria-current="page" @endif class="gs-size" data-test="group-switch-{{ $item['key'] }}">{{ $item['label'] }}</a>
            @endforeach
        </div>
    </nav>
@endif
