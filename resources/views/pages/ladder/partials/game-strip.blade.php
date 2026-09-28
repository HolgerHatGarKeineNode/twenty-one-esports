{{--
    Where a Global Rating comes from (P40): one segment per game, its width
    the player's rated results in that game, in the game's colour, with a
    2 px gap so two games of the same colour (the FC editions) stay apart.
    Decorative for assistive tech: the legend beside it says the same.

    $games:  game slug => rated results, in registry order
    $colour: fn (string $game): string, the game's colour (a CSS value)
    $share:  the player's results against the most on the list (0..1]: the
             strip's length, so a longer strip is more rated play
--}}
<span class="flex h-2 gap-0.5" style="width: {{ round($share * 100, 1) }}%" aria-hidden="true" data-test="game-strip">
    @foreach ($games as $stripGame => $stripWeight)
        <span class="h-2 min-w-1 rounded-[2px]" style="flex: {{ $stripWeight }} 1 0; background: {{ $colour($stripGame) }}" data-game="{{ $stripGame }}"></span>
    @endforeach
</span>
