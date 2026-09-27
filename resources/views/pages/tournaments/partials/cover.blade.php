{{--
    The game's cover art as the tournament's poster (tournament page hero,
    sign-up page), through <x-game-cover> and the registry's covers. It is the
    first thing on the page, so it loads at once instead of lazily. A game
    without a cover gets the component's neutral tile.

    $tournament: the tournament
    $class: size and placement from the caller
--}}
<figure class="tl-poster m-0 {{ $class ?? '' }}" data-test="game-cover" data-cover="{{ app(\App\Games\GameRegistry::class)->cover($tournament->game) === null ? 'tile' : 'art' }}">
    <x-game-cover :game="$tournament->game" size="header" loading="eager" class="w-full rounded-card" />
</figure>
