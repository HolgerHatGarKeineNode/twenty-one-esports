{{--
    A board of a clan team match (plan "Schach Rapid und Clan", P6): which
    board of which team match this game is, and the way back to all boards.
    Above the game, live or finished, as the tournament banner is.

    $game: the board's ChessGame; $url: the team match page.
--}}
<a href="{{ $url }}" @navigate($url) class="mx-4 mb-4 flex min-h-11 min-w-0 items-center gap-3 rounded-lg bg-card px-4 py-2 text-[13px] text-ink shadow-ring hover:text-ink lg:mx-12 lg:mb-5" data-test="team-board-banner">
    <x-icon name="clans" :size="18" class="shrink-0 text-btc" />
    <span class="min-w-0 grow truncate">{{ __('Board :board of team match :number', ['board' => $game->board, 'number' => '#'.$game->matchNumber()]) }}</span>
    <b class="shrink-0">{{ __('All boards') }}</b>
</a>
