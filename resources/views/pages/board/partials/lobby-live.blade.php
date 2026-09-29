{{--
    "Live now" in a board game's lobby, as in the chess lobby
    (pages/chess/partials/lobby-live): the running blitz boards of this board
    game with both players' ratings, then who is online (presence channel
    `online`, components/lobby/online-now) with "Looking to play" for this
    board game and an invite for each player who looks for it.
--}}
@php
    $liveGames = $this->liveGames;
    $liveRatings = $this->liveRatings;
@endphp

<section aria-labelledby="live-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 lg:col-span-5 lg:px-5" data-test="lobby-live">
    <div class="flex flex-col gap-2" data-test="now-playing">
        <span class="flex items-baseline justify-between gap-3">
            <h2 id="live-h" class="m-0 flex items-center gap-2 text-[15px] font-bold"><span @class(['size-2 rounded-full', 'animate-live bg-btc' => $liveGames->isNotEmpty(), 'bg-edge' => $liveGames->isEmpty()]) aria-hidden="true"></span>{{ __('Live now') }} <b class="text-ink-2" data-test="live-count">{{ $this->liveCount }}</b></h2>
            <a href="{{ route('matches.index', ['game' => $slug]) }}" class="inline-flex min-h-11 items-center text-xs text-ink lg:min-h-6" data-test="all-matches">{{ __('All matches') }}</a>
        </span>
        @if ($liveGames->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No live game right now.') }}</p>
        @else
            <ul role="list" class="m-0 flex list-none flex-col p-0">
                @foreach ($liveGames as $live)
                    <li wire:key="live-{{ $live->id }}">
                        <a href="{{ route('board.show', $live) }}" class="grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="live-game">
                            <span class="flex min-w-0 flex-col gap-1 text-[13px]">
                                @foreach (['w' => $live->white, 'b' => $live->black] as $side => $player)
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span @class(['size-2.5 shrink-0 rounded-full shadow-ring', 'bg-ink' => $side === 'w', 'bg-ground' => $side === 'b']) aria-hidden="true"></span>
                                        <b class="truncate">{{ $player?->displayName() ?? __('Deleted player') }}</b>
                                        <span class="shrink-0 text-xs text-ink-2">{{ $liveRatings[$live->id][$side] }}</span>
                                    </span>
                                @endforeach
                            </span>
                            <span class="flex flex-col items-end gap-1 text-xs text-ink-2">
                                <span class="inline-flex items-center gap-1.5 text-ink"><x-icon name="eye" :size="14" />{{ __('Watch') }}</span>
                                <span>{{ __('move :n', ['n' => intdiv($live->ply, 2) + 1]) }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-lobby.online-now :user="$user" :looking-key="$slug.'/blitz'" :looking-tag="__('looking: :game', ['game' => $name])" :can-invite="! $active" :show-elo="false" />
</section>
