{{--
    "Your games" in a board game's lobby, as in the chess lobby
    (pages/chess/partials/lobby-games): the live game this player is in,
    the correspondence challenges to answer, then the correspondence games
    of this board game, those waiting for this player's move first. A guest
    is asked to log in.
--}}
@php
    use App\Games\GameRegistry;
    use App\Models\BoardGame;
    use App\Support\GameNames;

    $correspondenceOn = app(GameRegistry::class)->mode($slug, BoardGame::CORRESPONDENCE) !== null;
    $games = $this->correspondenceGames;
    $opponentRatings = $this->correspondenceRatings;
    $challenges = $this->correspondence['challenges'];
    $nowMs = (int) now()->getTimestampMs();
    $tag = 'shrink-0 rounded-xs px-1.5 py-0.5 text-[11px] leading-4 font-bold';
@endphp

<section id="your-games" aria-labelledby="games-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:col-span-4 lg:px-5 xl:max-2xl:col-span-5" data-test="lobby-games">
    <span class="flex items-baseline justify-between gap-3">
        <h2 id="games-h" class="m-0 text-[15px] font-bold">{{ __('Your games') }}</h2>
        @if ($user && $correspondenceOn)
            <a href="{{ route('board.correspondence', $slug) }}" class="inline-flex min-h-11 items-center text-xs text-ink lg:min-h-6" data-test="lobby-games-all">{{ __('All :count', ['count' => $games->count()]) }}</a>
        @endif
    </span>

    @guest
        <p class="m-0 text-[13px] leading-normal text-ink-2">{{ __('Log in to see your games.') }}</p>
        <x-button variant="quiet" :href="route('login')" class="self-start">{{ __('Log in') }}</x-button>
    @else
        <ul role="list" class="m-0 flex list-none flex-col p-0 empty:hidden">
            @if ($active)
                @php
                    $opponent = $active->opponentOf($user);
                @endphp
                <li>
                    <a href="{{ route('board.show', $active) }}" class="flex min-h-14 items-center gap-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="lobby-active">
                        <x-avatar :user="$opponent" :size="28" class="rounded-sm" />
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <b class="truncate text-[13px]">{{ $opponent?->displayName() ?? __('Deleted player') }}</b>
                            <span class="text-xs text-ink-2">{{ GameNames::game($active->game) }}, {{ __('Blitz 5+3') }}</span>
                        </span>
                        <span class="{{ $tag }} flex items-center gap-1.5 bg-btc text-on-btc"><span class="size-1.5 animate-live rounded-full bg-on-btc" aria-hidden="true"></span>{{ __('Live') }}</span>
                    </a>
                </li>
            @endif

            @if ($challenges > 0)
                <li>
                    <a href="{{ route('board.correspondence', $slug) }}" class="flex min-h-14 items-center gap-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="correspondence-waiting">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-sm bg-btc-press text-btc-hi" aria-hidden="true"><x-icon name="send" :size="14" /></span>
                        <b class="min-w-0 grow truncate text-[13px]">{{ trans_choice(':count challenge to answer|:count challenges to answer', $challenges) }}</b>
                        <span class="{{ $tag }} bg-btc-press text-btc-hi">{{ __('challenges you') }}</span>
                    </a>
                </li>
            @endif

            @foreach ($games->take(5) as $game)
                @php
                    $opponent = $game->opponentOf($user);
                    $mine = $game->turn === $game->colorOf($user);
                    $left = intdiv(max(0, (int) $game->deadline_ms - $nowMs), 60_000);
                @endphp
                <li wire:key="cg-{{ $game->id }}">
                    <a href="{{ route('board.show', $game) }}" class="flex min-h-14 items-center gap-3 border-b border-hairline py-2 text-ink hover:text-ink" data-test="lobby-correspondence-game" data-mine="{{ $mine ? 'true' : 'false' }}">
                        <x-avatar :user="$opponent" :size="28" class="rounded-sm" />
                        <span class="flex min-w-0 grow flex-col gap-0.5">
                            <span class="flex min-w-0 items-baseline gap-2 text-[13px]"><b class="truncate">{{ $opponent?->displayName() ?? __('Deleted player') }}</b>@if ($opponent && isset($opponentRatings[$opponent->id]))<span class="shrink-0 text-xs text-ink-2">{{ $opponentRatings[$opponent->id] }}</span>@endif</span>
                            <span class="text-xs text-ink-2">{{ $mine ? __(':h h :m min left', ['h' => intdiv($left, 60), 'm' => str_pad((string) ($left % 60), 2, '0', STR_PAD_LEFT)]) : __('move :n', ['n' => intdiv($game->ply, 2) + 1]) }}</span>
                        </span>
                        @if ($mine)
                            <span class="{{ $tag }} bg-btc text-on-btc">{{ __('Your move') }}</span>
                        @else
                            <span class="{{ $tag }} font-normal text-ink-2 shadow-ring">{{ __('Their move') }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>

        @if (! $active && $challenges === 0 && $games->isEmpty())
            <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="lobby-games-empty">{{ __('No game running. Start one with a tile above.') }}</p>
        @endif
    @endguest
</section>
