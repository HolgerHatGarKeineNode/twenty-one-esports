@props(['games' => [], 'size' => 'sm'])

{{--
    The games of a board game's pairing on its page, to switch between them (DerCaddy 2026-10-30): game 1 stays one tap
    away from game 2, without the bracket. This game is marked and no link; the others open their board.
    `size` lg (the phone's panel) keeps every target 44 px high, sm (the wide banner) a mouse's 32 px. One line each: a
    long name ends in "…" instead of wrapping (DerCaddy 2026-10-31), the name stands last so only it is cut; the
    whole text stays in the title and for screen readers.
    $games: TournamentGameEnd::banner()['games'].
--}}
@if (count($games) > 1)
    <nav aria-label="{{ __('Games of this pairing') }}" {{ $attributes->class('flex flex-wrap gap-2') }} data-test="pairing-games">
        @foreach ($games as $game)
            @if ($game['current'])
                <span aria-current="page" title="{{ $game['text'] }}" @class(['inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-md bg-btc px-3 text-[13px] font-bold text-on-btc', 'min-h-11' => $size === 'lg', 'min-h-8' => $size !== 'lg']) data-test="pairing-game" data-current>
                    <span class="min-w-0 truncate">{{ $game['text'] }}</span>
                </span>
            @else
                <a href="{{ $game['href'] }}" title="{{ $game['text'] }}" @class(['inline-flex max-w-full min-w-0 items-center gap-1.5 rounded-md border border-btc px-3 text-[13px] font-bold text-ink hover:bg-btc-chip', 'min-h-11' => $size === 'lg', 'min-h-8' => $size !== 'lg']) data-test="pairing-game">
                    @if ($game['live'])
                        <span class="size-1.5 shrink-0 rounded-full bg-btc" aria-hidden="true"></span>
                    @else
                        <x-icon name="eye" :size="14" class="shrink-0" />
                    @endif
                    <span class="min-w-0 truncate">{{ $game['text'] }}</span>
                </a>
            @endif
        @endforeach
    </nav>
@endif
