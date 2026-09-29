{{--
    All games and modes (/play, header concept B): every registered game
    (GameRegistry) with its cover, its modes and what a player does in it,
    the viewer's games first, the board games as one group next to chess.
    The game hub links here. Public: a guest sees
    the pages anyone can open, and "Log in to play" instead of the actions
    that need an account. The links are the navigation's own
    (App\Support\Navigation\ShellNavigation), so this page never promises
    something the menus do not reach.
--}}
@php
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $games = $nav->playOrder();
    $user = $nav->user;
    $registry = app(\App\Games\GameRegistry::class);
    $boards = array_values(array_filter($games, fn (array $game): bool => $registry->isBoard($game['slug'])));
    app(\App\Support\PageMeta::class)
        ->describe(__('All games and modes'), __('Every game of the TWENTY ONE esports league with its modes: :games. What each one is, how it is rated and where to play it.', ['games' => implode(', ', array_map(fn (string $game): string => \App\Support\GameNames::game($game), array_keys($registry->all())))]))
        ->card(fn () => \App\Support\Cards\PageCard::page('play'));
@endphp
<x-layouts::app :title="__('All games and modes')">
    <div class="flex flex-col gap-6 px-4 pb-10 lg:gap-8 lg:px-12 lg:pb-12" data-test="play-page">
        <div class="flex max-w-[60ch] flex-col gap-2">
            <h1 class="m-0 font-display text-[28px] leading-[1.1] font-bold lg:text-4xl">{{ __('All games and modes') }}</h1>
            <p class="m-0 text-[13px] leading-normal text-ink-2">
                {{-- With board games the order is playOrder(): by what the player played, the board games moved next to chess. --}}
                {{ $user ? ($boards === [] ? __('Your games come first, the one you played last on top.') : __('Sorted by what you played, the latest on top; the board games always stand next to chess.')) : __('Every game of the league. Log in with Nostr or Google to play, challenge and climb a ladder.') }}
            </p>
        </div>

        {{-- Casual 1v1 (P23 S3): the quickest way into a Rocket League or EA FC match, the game picked here. --}}
        @if (\App\Support\Series\CasualLobby::games() !== [])
            <livewire:casual-play />
        @endif

        {{--
            The board games (plan "Mühle und Dame", P7) as one group right after chess (ShellNavigation::playOrder()),
            not behind every series game at the end.
        --}}
        <ul class="m-0 flex list-none flex-col gap-4 p-0">
            @foreach ($games as $game)
                @if (! $registry->isBoard($game['slug']))
                    @include('pages.play.game', ['game' => $game, 'level' => 2, 'eager' => $loop->first])
                @elseif ($game['slug'] === $boards[0]['slug'])
                    <li id="board-games" class="flex scroll-mt-24 flex-col gap-3" data-test="play-board-games">
                        <div class="flex max-w-[68ch] flex-col gap-1">
                            <h2 class="m-0 font-display text-xl font-bold">{{ __('Board games') }}</h2>
                            <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Blitz 5+3 on a board right here in the browser: find an opponent in seconds and climb a casual ladder.') }}</p>
                        </div>
                        <ul class="m-0 flex list-none flex-col gap-4 p-0">
                            @foreach ($boards as $board)
                                @include('pages.play.game', ['game' => $board, 'level' => 3, 'eager' => $loop->parent->first && $loop->first])
                            @endforeach
                        </ul>
                    </li>
                @endif
            @endforeach
        </ul>
    </div>
</x-layouts::app>
