{{--
    "Live now" in the chess lobby: the running live boards (rapid and blitz) with both
    players' ratings, then who is online (presence channel `online`) with
    "Looking to play" and an invite per player. The list and the switch are
    Alpine's (chessLobby in resources/js/chess.js); a Livewire render never
    touches the switch. The online block is components/lobby/online-now.
--}}
@php
    $liveGames = $this->liveGames;
    $liveRatings = $this->liveRatings;
@endphp

<section aria-labelledby="live-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 lg:col-span-5 lg:px-5 xl:max-2xl:col-span-7">
    <div class="flex flex-col gap-2" data-test="now-playing">
        <span class="flex items-baseline justify-between gap-3">
            <h2 id="live-h" class="m-0 flex items-center gap-2 text-[15px] font-bold"><span @class(['size-2 rounded-full', 'animate-live bg-btc' => $liveGames->isNotEmpty(), 'bg-edge' => $liveGames->isEmpty()]) aria-hidden="true"></span>{{ __('Live now') }} <b class="text-ink-2" data-test="live-count">{{ $this->liveCount }}</b></h2>
            <a href="{{ route('games.index') }}" class="inline-flex min-h-11 items-center text-xs text-ink lg:min-h-6" data-test="all-live-games">{{ __('All live games') }}</a>
        </span>
        @if ($liveGames->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No live game right now.') }}</p>
        @else
            <ul role="list" class="m-0 flex list-none flex-col p-0">
                @foreach ($liveGames as $live)
                    @php
                        $ratings = $liveRatings[$live->id];
                    @endphp
                    <li wire:key="live-{{ $live->id }}">
                        <a href="{{ route('games.show', $live) }}" class="grid min-h-14 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="live-game">
                            <span class="flex min-w-0 flex-col gap-1 text-[13px]">
                                @foreach (['w' => $live->white, 'b' => $live->black] as $side => $player)
                                    <span class="flex min-w-0 items-center gap-2">
                                        <span @class(['size-2.5 shrink-0 rounded-[2px] shadow-ring', 'bg-ink' => $side === 'w', 'bg-ground' => $side === 'b']) aria-hidden="true"></span>
                                        <b class="truncate">{{ $player?->displayName() }}</b>
                                        <span class="shrink-0 text-xs text-ink-2">{{ $ratings[$side]['rating'] }}</span>
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

    {{-- Online now: presence channel `online` (components/lobby/online-now, shared with the board game lobbies). --}}
    {{-- "Looking to play" here is live chess (stored as `chess/blitz`); an invite goes out in the mode the quick-play panel has open. --}}
    <x-lobby.online-now :user="$user" looking-key="chess/blitz" :looking-tag="__('looking: live chess')" :can-invite="! $active" invite-mode="liveMode" />
</section>
