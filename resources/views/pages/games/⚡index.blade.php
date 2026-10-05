<?php

use App\Enums\ChessGameStatus;
use App\Models\ChessGame;
use App\Support\Chess\ChessModes;
use App\Support\PageMeta;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * Live games (P10, "Spectating"): every chess game running now, blitz and
 * daily, for anyone, guests included. Each links to the game page, which a
 * guest already watches live over the public `game.{id}.watch` channel (or
 * by polling without a websocket). The chess lobby shows three blitz boards;
 * this page is the full list it links to. Nothing here is private: the
 * players' chat stays on the game page, for the players only.
 *
 * Rapid and blitz (plan "Schach Rapid und Clan", P3): the live section lists
 * every live mode, each board names its own, and the mode filter (`?mode=`,
 * one chip per live mode of the registry) narrows it. The chips carry no
 * counts: that would be one more query on a hot route (PageQueryBudgetTest).
 */
new #[Layout('layouts::app', ['section' => 'chess', 'scripts' => ['resources/js/chess.js']])] class extends Component {
    public const LIMIT = 48;

    /** The live mode filter: '' = every live mode. */
    #[Url(as: 'mode', except: '')]
    public string $mode = '';

    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Live games'));
        app(PageMeta::class)->describe(__('Live games'), __('Watch the chess games of the TWENTY ONE esports league live: every rapid and blitz board and every daily game running now, no login needed.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('games'));
    }

    /**
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function blitz(): Collection
    {
        return ChessGame::query()->live()->where('status', ChessGameStatus::Active)
            ->when(ChessModes::isLive($this->mode), fn ($query) => $query->where('mode', $this->mode))
            ->with(['white', 'black'])->latest('id')->limit(self::LIMIT)->get();
    }


    /**
     * Daily games, the most recent move first.
     *
     * @return Collection<int, ChessGame>
     */
    #[Computed]
    public function daily(): Collection
    {
        return ChessGame::query()->daily()->where('status', ChessGameStatus::Active)->with(['white', 'black'])->latest('updated_at')->latest('id')->limit(self::LIMIT)->get();
    }
}; ?>

<div class="flex flex-col gap-5 px-4 pb-6 lg:px-12 lg:pb-8" data-test="live-games">
    <span class="flex flex-wrap items-baseline justify-between gap-3">
        <h1 class="m-0 font-display text-2xl font-bold lg:text-[28px]">{{ __('Live games') }}</h1>
        <span class="text-[13px] text-ink-2">{{ __('Watch any game, no login needed') }}</span>
    </span>

    <section aria-labelledby="live-blitz-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex items-baseline justify-between gap-3">
            <h2 id="live-blitz-h" class="m-0 flex items-center gap-2 text-[15px] font-bold">@if ($this->blitz->isNotEmpty())<span class="size-2 animate-live rounded-full bg-win" aria-hidden="true"></span>@endif{{ __('Live chess') }}</h2>
            <span class="text-xs text-ink-2">{{ trans_choice(':count live board|:count live boards', $this->blitz->count()) }}</span>
        </span>
        {{-- One chip per live mode of the registry (rapid first); links, so the filter is in the address. --}}
        <nav aria-label="{{ __('Mode') }}" class="flex flex-wrap gap-2" data-test="live-mode-filter">
            @foreach (['' => __('All'), ...collect(ChessModes::live())->mapWithKeys(fn (string $mode): array => [$mode => ChessModes::label($mode)])->all()] as $value => $label)
                <a href="{{ route('games.index', $value === '' ? [] : ['mode' => $value]) }}" aria-current="{{ $mode === $value ? 'page' : 'false' }}" data-test="live-mode-{{ $value === '' ? 'all' : $value }}"
                   @class(['inline-flex min-h-11 items-center gap-1.5 rounded-md px-3 text-[13px] shadow-ring', 'bg-raised font-bold text-btc-hi' => $mode === $value, 'text-ink hover:text-ink' => $mode !== $value])>
                    {{ $label }}
                </a>
            @endforeach
        </nav>
        @if ($this->blitz->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No live game right now.') }} <a href="{{ route('chess.lobby') }}">{{ __('Find an opponent') }}</a></p>
        @else
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->blitz as $game)
                    <li wire:key="blitz-{{ $game->id }}" class="flex min-w-0 flex-col gap-2.5 border-b border-hairline pb-3 sm:border-0 sm:pb-0" data-test="live-game" data-mode="{{ $game->mode }}">
                        <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$game->black" :size="18" class="rounded-sm" /><b class="truncate">{{ $game->black->displayName() }}</b></span>
                        {{-- The 64 squares are built when the board comes into view: a phone hides the boards and builds none (48 boards were 24 576 of the page's 25 996 DOM nodes, tests/Browser/RenderScopeTest.php). --}}
                        <div class="hidden justify-center py-1 sm:flex" x-data="{ cells: [], boardLabel: @js(__('Live board of :number', ['number' => $game->number()])) }"
                             x-intersect.once.margin.200px="cells = window.chessBoardCells(@js($game->fen), { noCoords: true })" data-test="live-board">
                            <x-chess.board class="mt-4 mr-4 max-w-40" />
                        </div>
                        <span class="flex min-w-0 items-center gap-2 text-[13px]"><x-avatar :user="$game->white" :size="18" class="rounded-sm" /><b class="truncate">{{ $game->white->displayName() }}</b></span>
                        <span class="text-xs text-ink-2"><span data-test="live-game-mode">{{ ChessModes::label($game->mode) }}</span> · {{ $game->rated ? __('Rated') : __('Casual') }} · {{ __('move :n', ['n' => intdiv($game->ply, 2) + 1]) }} · {{ $game->number() }}</span>
                        <x-button variant="quiet" icon="eye" :href="route('games.show', $game)" data-test="watch">{{ __('Watch') }}</x-button>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section aria-labelledby="live-daily-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6">
        <span class="flex items-baseline justify-between gap-3">
            <h2 id="live-daily-h" class="m-0 text-[15px] font-bold">{{ __('Daily chess') }}</h2>
            <span class="text-xs text-ink-2">{{ trans_choice(':count game running|:count games running', $this->daily->count()) }}</span>
        </span>
        @if ($this->daily->isEmpty())
            <p class="m-0 text-[13px] text-ink-2">{{ __('No daily game running.') }}</p>
        @else
            <ul class="m-0 list-none p-0">
                @foreach ($this->daily as $game)
                    <li wire:key="daily-{{ $game->id }}" class="border-t border-hairline first:border-0" data-test="live-game">
                        <a href="{{ route('games.show', $game) }}" class="flex min-h-12 flex-wrap items-center gap-x-3 gap-y-1 py-2 text-[13px] text-ink hover:bg-row-hover hover:text-ink">
                            <span class="flex min-w-0 grow items-center gap-1.5">
                                <x-avatar :user="$game->white" :size="18" class="rounded-sm" /><span class="truncate">{{ $game->white->displayName() }}</span>
                                <span class="shrink-0 text-ink-3">{{ __('vs') }}</span>
                                <x-avatar :user="$game->black" :size="18" class="rounded-sm" /><span class="truncate">{{ $game->black->displayName() }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-ink-2">{{ $game->rated ? __('Rated') : __('Casual') }} · {{ __('move :n', ['n' => intdiv($game->ply, 2) + 1]) }} · {{ $game->number() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
