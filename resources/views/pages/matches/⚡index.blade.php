<?php

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Games\Blockfill;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Support\GameNames;
use App\Support\Matches\MempoolStrip;
use App\Support\Matches\ScoreAttempts;
use App\Support\PageMeta;
use App\Support\Series\SeriesPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Matches, from Matches.dc.html: the mempool strip over the latest matches of
 * every game, filters (game, clan, status) and the table. Series (Rocket
 * League, EA Sports FC), chess games (blitz and daily) and the board games
 * switched on (nine men's morris, checkers; plan "Mempool-Streifen", P2)
 * share the table; the Game filter narrows it. Chess and board games only
 * know "live" and "done" (they start when they are created). Series, chess
 * and rated board games carry their league match number, one sequence
 * (P7b); a casual board game has none. The Chain filter (`?chain=season`
 * or `casual`, P4 of plan "Mempool-Streifen") narrows strip and table to
 * the rated matches, whose wins mine the season chain, or the casual ones;
 * row 1 of the header links the casual view. The highscore attempts of the
 * score games (Blockfill and every other registered one) share strip and
 * table too (App\Support\Matches\ScoreAttempts): an unconfirmed run waits
 * "to confirm", a verified one is "done"; they are unrated, so the season
 * chain has none.
 */
new #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Matches'));
        app(PageMeta::class)->describe(__('Matches'), __('Every series and chess game of the TWENTY ONE esports league by match number: live, scheduled, waiting for confirmation and done.'));
        app(\App\Support\PageMeta::class)->card(fn () => \App\Support\Cards\PageCard::page('matches'));
    }

    use WithPagination;

    #[Url(except: 'all')]
    public string $game = 'all';

    #[Url(except: '')]
    public string $clan = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    #[Url(except: 'all')]
    public string $chain = 'all';

    public function updatedClan(): void
    {
        unset($this->selectedClan);
        $this->resetPage();
    }

    /** The clan of the filter, looked up once per request, and not at all without a filter (P5g: it was asked 16 times per page). */
    #[Computed]
    public function selectedClan(): ?Clan
    {
        return $this->clan === '' ? null : Clan::query()->where('slug', $this->clan)->first();
    }

    /**
     * A game filter from the address that the table cannot show (unknown, or
     * a board game switched off or without its route) shows all games.
     */
    public function mount(): void
    {
        $this->game = $this->listable($this->game) ? $this->game : 'all';
        $this->chain = array_key_exists($this->chain, MempoolStrip::CHAINS) ? $this->chain : 'all';
    }

    public function pickChain(string $chain): void
    {
        $this->chain = array_key_exists($chain, MempoolStrip::CHAINS) ? $chain : 'all';
        unset($this->strip);
        $this->resetPage();
        $this->dispatchFilter();
    }


    public function pickGame(string $game): void
    {
        $this->game = $this->listable($game) ? $game : 'all';
        $this->resetPage();
        $this->dispatchFilter();
    }

    /** The header's chain rail marks the view on screen (resources/js/shellNav.js `followMatchesFilter`). */
    private function dispatchFilter(): void
    {
        $this->dispatch('matches-filter', chain: $this->chain, game: $this->game);
    }

    /**
     * The games of the filter: every registered game that plays matches, the
     * board games only while their page is routed (App\Support\Matches\MempoolStrip::boardSlugs()).
     *
     * @return list<string>
     */
    private function gameFilters(): array
    {
        $registry = app(GameRegistry::class);
        $boards = MempoolStrip::boardSlugs();

        // The score games while their leaderboards are routed: their highscore attempts are listed with the matches.
        return [
            ...array_values(array_filter(array_keys($registry->versus()), fn (string $slug): bool => ! $registry->isBoard($slug) || in_array($slug, $boards, true))),
            ...ScoreAttempts::slugs(),
        ];
    }

    private function listable(string $game): bool
    {
        return $game === 'all' || in_array($game, $this->gameFilters(), true);
    }

    public function pickStatus(string $status): void
    {
        $this->status = array_key_exists($status, $this->statusFilters()) ? $status : 'all';
        $this->resetPage();
    }

    /**
     * @return array<string, list<SeriesStatus>>
     */
    private function statusFilters(): array
    {
        return [
            'all' => [],
            'waiting' => [SeriesStatus::Open],
            'scheduled' => [SeriesStatus::Accepted],
            'live' => [SeriesStatus::Accepted],
            'to_confirm' => [SeriesStatus::Reported],
            'disputed' => [SeriesStatus::Disputed],
            'done' => [SeriesStatus::Confirmed, SeriesStatus::Resolved],
        ];
    }

    /**
     * @param  Builder<SeriesMatch>  $query
     * @return Builder<SeriesMatch>
     */
    private function filtered(Builder $query, string $status): Builder
    {
        $statuses = $this->statusFilters()[$status] ?? [];
        $clan = $this->selectedClan;

        return MempoolStrip::onChain($query, $this->chain)
            ->when($this->game !== 'all', fn (Builder $query) => $query->where('game', $this->game))
            ->when($clan !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereIn('challenger_lineup_id', $clan->lineups()->select('id'))
                ->orWhereIn('challenged_lineup_id', $clan->lineups()->select('id'))))
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->when($status === 'scheduled', fn (Builder $query) => $query->where('start_at', '>', now()))
            ->when($status === 'live', fn (Builder $query) => $query->where('start_at', '<=', now()));
    }

    /**
     * @param  Builder<ChessGame>  $query
     * @return Builder<ChessGame>
     */
    private function filteredChess(Builder $query, string $status): Builder
    {
        $clan = $this->selectedClan;
        $statuses = $this->chessStatuses($status);

        return MempoolStrip::onChain($query, $this->chain)
            ->when($clan !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('white.clanMember', fn (Builder $query) => $query->where('clan_id', $clan->id))
                ->orWhereHas('black.clanMember', fn (Builder $query) => $query->where('clan_id', $clan->id))))
            ->when($statuses !== null, fn (Builder $query) => $query->whereIn('status', $statuses));
    }

    /**
     * The chess statuses a status filter stands for; null for all of them.
     *
     * @return list<ChessGameStatus>|null
     */
    private function chessStatuses(string $status): ?array
    {
        return match ($status) {
            'all' => null,
            'live' => [ChessGameStatus::Active],
            'done' => [ChessGameStatus::Finished, ChessGameStatus::Aborted],
            default => [],
        };
    }

    /**
     * Whether chess games can show under these filters at all. Waiting,
     * scheduled, to confirm and disputed are series states: no query for them.
     */
    private function listsChess(string $status): bool
    {
        return ($this->game === 'all' || app(GameRegistry::class)->find($this->game)?->kind() === GameKind::Chess) && $this->chessStatuses($status) !== [];
    }

    /**
     * The board games the table lists under the game filter: all routed ones,
     * the chosen one, or none.
     *
     * @return list<string>
     */
    private function listedBoards(): array
    {
        $boards = MempoolStrip::boardSlugs();

        return $this->game === 'all' ? $boards : array_values(array_intersect($boards, [$this->game]));
    }

    /**
     * Whether board games can show under these filters: as chess, they know
     * only "live" and "done".
     */
    private function listsBoards(string $status): bool
    {
        return $this->listedBoards() !== [] && $this->chessStatuses($status) !== [];
    }

    /**
     * @param  Builder<BoardGame>  $query
     * @return Builder<BoardGame>
     */
    private function filteredBoards(Builder $query, string $status): Builder
    {
        $clan = $this->selectedClan;
        $statuses = match ($status) {
            'live' => [BoardGameStatus::Active],
            'done' => [BoardGameStatus::Finished, BoardGameStatus::Aborted],
            default => null,
        };

        return MempoolStrip::onChain($query, $this->chain)
            ->whereIn('game', $this->listedBoards())
            ->when($clan !== null, fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereHas('white.clanMember', fn (Builder $query) => $query->where('clan_id', $clan->id))
                ->orWhereHas('black.clanMember', fn (Builder $query) => $query->where('clan_id', $clan->id))))
            ->when($statuses !== null, fn (Builder $query) => $query->whereIn('status', $statuses));
    }

    /**
     * The attempt state (ScoreAttempts) a status filter stands for; null when
     * no attempt shows under it: attempts only wait "to confirm" or are
     * "done", and they never sit on the season chain.
     */
    private function runState(string $status): ?string
    {
        if ($this->chain === 'season') {
            return null;
        }

        return match ($status) {
            'all' => 'all',
            'to_confirm' => 'waiting',
            'done' => 'done',
            default => null,
        };
    }

    /**
     * The score games read from score_runs under the game filter (every one but Blockfill).
     *
     * @return list<string>
     */
    private function listedScores(): array
    {
        return ScoreAttempts::scoreSlugs($this->game === 'all' ? ScoreAttempts::slugs() : [$this->game]);
    }

    private function listsStacker(string $status): bool
    {
        return $this->runState($status) !== null && ScoreAttempts::blockfill() && in_array($this->game, ['all', Blockfill::SLUG], true);
    }

    private function listsScores(string $status): bool
    {
        return $this->runState($status) !== null && $this->listedScores() !== [];
    }

    /**
     * How many attempts show under a status filter.
     */
    private function runCount(string $status): int
    {
        $state = (string) $this->runState($status);

        return ($this->listsStacker($status) ? ScoreAttempts::stacker($state, $this->selectedClan)->count() : 0)
            + ($this->listsScores($status) ? ScoreAttempts::scores($this->listedScores(), $state, $this->selectedClan)->count() : 0);
    }

    /**
     * Whether series (Rocket League, EA Sports FC) show under the game filter.
     */
    private function listsSeries(): bool
    {
        return $this->game === 'all' || app(GameRegistry::class)->isSeries($this->game);
    }

    /**
     * Rows of the table, newest first: `series` rows carry a SeriesMatch,
     * `chess` rows a ChessGame, `board` rows a BoardGame, `run` rows a
     * highscore attempt (a StackerRun of Blockfill or a ScoreRun) and its link.
     *
     * @return LengthAwarePaginator<int, array{type: 'series'|'chess'|'board'|'run', model: SeriesMatch|ChessGame|BoardGame|StackerRun|ScoreRun, href?: string}>
     */
    #[Computed]
    public function matches(): LengthAwarePaginator
    {
        $perPage = 20;
        $page = $this->getPage();
        $take = $page * $perPage;
        $series = ! $this->listsSeries() ? collect() : $this->filtered(SeriesMatch::query()->with(['latestReport', 'challengerLineup.clan', 'challengedLineup.clan']), $this->status)->latest()->limit($take)->get();
        $chess = $this->listsChess($this->status) ? $this->filteredChess(ChessGame::query()->with(['white', 'black']), $this->status)->latest()->limit($take)->get() : collect();
        $boards = $this->listsBoards($this->status) ? $this->filteredBoards(BoardGame::query()->with(['white', 'black']), $this->status)->latest()->limit($take)->get() : collect();
        $state = (string) $this->runState($this->status);
        $runs = collect([
            ...($this->listsStacker($this->status) ? ScoreAttempts::stacker($state, $this->selectedClan)->with('user')->latest()->limit($take)->get()->all() : []),
            ...($this->listsScores($this->status) ? ScoreAttempts::scores($this->listedScores(), $state, $this->selectedClan)->with('user')->latest()->limit($take)->get()->all() : []),
        ]);
        $total = (! $this->listsSeries() ? 0 : $this->filtered(SeriesMatch::query(), $this->status)->count())
            + ($this->listsChess($this->status) ? $this->filteredChess(ChessGame::query(), $this->status)->count() : 0)
            + ($this->listsBoards($this->status) ? $this->filteredBoards(BoardGame::query(), $this->status)->count() : 0)
            + $this->runCount($this->status);

        $rows = $series->map(fn (SeriesMatch $match) => ['type' => 'series', 'model' => $match])
            ->concat($chess->map(fn (ChessGame $game) => ['type' => 'chess', 'model' => $game]))
            ->concat($boards->map(fn (BoardGame $game) => ['type' => 'board', 'model' => $game]))
            ->concat($runs->map(fn (StackerRun|ScoreRun $run) => ['type' => 'run', 'model' => $run]))
            ->sortByDesc(fn (array $row) => $row['model']->created_at)
            ->values()
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

        // Where the attempts on this page link: one query for the week leaderboards of all of them.
        $attempts = $rows->where('type', 'run')->pluck('model')->all();
        $links = $attempts === [] ? [] : ScoreAttempts::links($attempts);
        $rows = $rows->map(fn (array $row) => $row['type'] === 'run' ? [...$row, 'href' => $links[ScoreAttempts::key($row['model'])]] : $row);

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $counts = [];

        foreach (array_keys($this->statusFilters()) as $status) {
            if ($status !== 'all') {
                $counts[$status] = (! $this->listsSeries() ? 0 : $this->filtered(SeriesMatch::query(), $status)->count())
                    + ($this->listsChess($status) ? $this->filteredChess(ChessGame::query(), $status)->count() : 0)
                    + ($this->listsBoards($status) ? $this->filteredBoards(BoardGame::query(), $status)->count() : 0)
                    + $this->runCount($status);
            }
        }

        return $counts;
    }

    /**
     * @return Collection<int, Clan>
     */
    #[Computed]
    public function clans(): Collection
    {
        return Clan::query()->whereHas('lineups', fn (Builder $query) => $query->whereIn('game', array_keys(app(GameRegistry::class)->series())))->orderBy('name')->get();
    }

    /**
     * The mempool strip: the matches of every game, played left (newest at
     * the divider), running and scheduled right (App\Support\Matches\MempoolStrip).
     *
     * @return array{finished: list<array<string, mixed>>, running: list<array<string, mixed>>, live: bool}
     */
    #[Computed]
    public function strip(): array
    {
        return MempoolStrip::build(auth()->user(), $this->chain, runs: true);
    }
}; ?>

@php
    $viewer = auth()->user();
    $labels = SeriesPresenter::statusLabels();
    $strip = $this->strip;
    $filterBtn = 'h-[42px] shrink-0 cursor-pointer border-0 px-3.5 text-[13px] whitespace-nowrap';
    $gameFilters = array_intersect_key(app(GameRegistry::class)->all(), array_flip($this->gameFilters()));
    // The strip's one line says what the chosen chain is; casual matches never mine, so their line never promises it.
    $lead = match (true) {
        $chain === 'casual' => __('Casual matches of every game, played and waiting. They move the casual rating and mine no blocks.'),
        $chain === 'season' => $strip['live'] ? __('Rated matches of every game, played and waiting. A fair rated win mines a block of the season chain.') : __('Rated matches of every game, played and waiting. Rated wins mine blocks only while a season runs.'),
        $strip['live'] => __('Matches of every game, played and waiting. A fair rated win mines a block of the season chain.'),
        default => __('Matches of every game, played and waiting. Rated wins mine blocks only while a season runs.'),
    };
    $chains = ['all' => __('All'), 'season' => __('Season'), 'casual' => __('Casual')];
@endphp

<div class="flex grow flex-col gap-6 pb-10" data-test="matches">
    @if ($strip['finished'] !== [] || $strip['running'] !== [])
        {{-- The copy promises mining only while a season runs: before Block 0 and between seasons no win mines. --}}
        <x-block-strip :finished="$strip['finished']" :running="$strip['running']" :title="__('Mempool')" :chain-live="$strip['live']" :lead="$lead" />
    @endif

    <div class="flex flex-col gap-5 px-4 lg:px-12">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Matches') }}</h1>

            <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-3">
                <div class="flex items-center gap-2">
                    <label for="f-game-select" id="f-game" class="text-xs text-ink-3">{{ __('Game title') }}</label>
                    {{-- Below sm a select (the buttons do not fit a phone), from sm the buttons: short labels (RL, FC27, AoE2, Morris) below 2xl, cover and name from 2xl; seven buttons with covers overflowed 640 px (754 px document), five with full names 1280 px (1486 px document, Age of Empires II). The name drops its subtitle ("Age of Empires II"); the accessible name starts with the visible text (x-games.filter-label). --}}
                    <select id="f-game-select" wire:change="pickGame($event.target.value)" data-test="game-filter-select"
                            class="h-11 w-[200px] rounded-md border border-edge bg-ground px-3 text-[13px] text-ink sm:hidden">
                        <option value="all" @selected($game === 'all')>{{ __('All') }}</option>
                        @foreach ($gameFilters as $key => $option)
                            <option value="{{ $key }}" @selected($game === $key)>{{ GameNames::game($key) }}</option>
                        @endforeach
                    </select>
                    <div role="group" aria-labelledby="f-game" class="flex max-w-full overflow-hidden rounded-md border border-line max-sm:hidden">
                        <button type="button" wire:click="pickGame('all')" aria-pressed="{{ $game === 'all' ? 'true' : 'false' }}" data-test="game-all"
                                @class([$filterBtn, 'bg-btc font-bold text-on-btc' => $game === 'all', 'bg-ground text-ink-2 hover:text-ink' => $game !== 'all'])>{{ __('All') }}</button>
                        @foreach ($gameFilters as $key => $option)
                            <button type="button" wire:click="pickGame('{{ $key }}')" aria-pressed="{{ $game === $key ? 'true' : 'false' }}" data-test="game-{{ $key }}"
                                    @class([$filterBtn, 'inline-flex items-center gap-2 border-l border-line', 'bg-btc font-bold text-on-btc' => $game === $key, 'bg-ground text-ink-2 hover:text-ink' => $game !== $key])><x-games.filter-label :game="$key" :short="__($option->assets()->shortLabel)" /></button>
                        @endforeach
                    </div>
                </div>
                {{-- Season: the rated matches, whose wins mine the season chain; Casual: the ones that never mine. Narrows strip and table. --}}
                <div class="flex items-center gap-2">
                    <span id="f-chain" class="text-xs text-ink-3">{{ __('Chain') }}</span>
                    <div role="group" aria-labelledby="f-chain" class="flex max-w-full overflow-hidden rounded-md border border-line" data-test="chain-filter">
                        @foreach ($chains as $key => $option)
                            <button type="button" wire:click="pickChain('{{ $key }}')" aria-pressed="{{ $chain === $key ? 'true' : 'false' }}" data-test="chain-{{ $key }}"
                                    @class([$filterBtn, 'border-l border-line' => ! $loop->first, 'bg-btc font-bold text-on-btc' => $chain === $key, 'bg-ground text-ink-2 hover:text-ink' => $chain !== $key])>{{ $option }}</button>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <label for="f-clan" class="text-xs text-ink-3">{{ __('Clan') }}</label>
                    <select id="f-clan" wire:model.live="clan" data-test="clan-filter"
                            class="h-11 w-[200px] rounded-md border border-edge bg-ground px-3 text-[13px] text-ink">
                        <option value="">{{ __('All clans') }}</option>
                        @foreach ($this->clans as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2">
            <span id="f-status" class="hidden text-xs text-ink-3 sm:inline">{{ __('Status') }}</span>
            <div role="group" aria-labelledby="f-status" class="flex max-w-full overflow-x-auto rounded-md border border-line">
                <button type="button" wire:click="pickStatus('all')" aria-pressed="{{ $status === 'all' ? 'true' : 'false' }}"
                        @class([$filterBtn, 'bg-btc font-bold text-on-btc' => $status === 'all', 'bg-ground text-ink-2' => $status !== 'all'])>{{ __('All') }}</button>
                @foreach ($this->counts as $key => $count)
                    <button type="button" wire:click="pickStatus('{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" data-test="status-{{ $key }}"
                            @class([$filterBtn, 'border-l border-line', 'bg-btc font-bold text-on-btc' => $status === $key, 'bg-ground text-ink-2' => $status !== $key])>{{ $labels[$key] }} {{ $count }}</button>
                @endforeach
            </div>
        </div>

        <div class="flex flex-col rounded-lg bg-card px-2 pt-2 pb-4 lg:px-6">
            <div class="hidden h-11 grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] items-center gap-4 border-b border-hairline px-2 text-[13px] font-bold text-ink-2 lg:grid">
                <span>{{ __('Match') }}</span><span>{{ __('Sides') }}</span><span>{{ __('Score') }}</span><span>{{ __('Format') }}</span><span>{{ __('Status') }}</span><span>{{ __('Result') }}</span><span class="text-right">{{ __('When') }}</span>
            </div>
            @forelse ($this->matches as $row)
                @if ($row['type'] === 'chess')
                    @include('pages.matches.partials.chess-row', ['chessGame' => $row['model'], 'viewer' => $viewer])
                    @continue
                @endif
                @if ($row['type'] === 'board')
                    @include('pages.matches.partials.board-row', ['boardGame' => $row['model'], 'viewer' => $viewer])
                    @continue
                @endif
                @if ($row['type'] === 'run')
                    @include('pages.matches.partials.score-row', ['run' => $row['model'], 'href' => $row['href']])
                    @continue
                @endif
                @php($match = $row['model'])
                @php($chip = SeriesPresenter::chip($match))
                @php($result = SeriesPresenter::result($match, $viewer))
                @php($score = SeriesPresenter::score($match))
                <a href="{{ route('matches.show', $match) }}" wire:key="m-{{ $match->id }}" data-test="match-row"
                   class="tr grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm px-2 py-2 text-[13px] text-ink hover:text-ink lg:h-11 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] lg:gap-4 lg:py-0">
                    <span class="font-bold text-btc">{{ $match->label() }}</span>
                    {{-- Below lg one side per line, so each name keeps the full column; from lg one line. --}}
                    <span class="flex min-w-0 flex-col gap-1 whitespace-nowrap lg:flex-row lg:items-center lg:gap-2">
                        <span class="flex min-w-0 items-center gap-2">
                            <x-clan-tag :clan="$match->sideClan('challenger')" :tag="$match->challenger_tag" size="sm" compact />
                            <span @class(['truncate', 'font-bold' => $match->winner === 'challenger', 'text-ink-2' => $match->winner === 'challenged']) data-test="match-side-name">{{ $match->challenger_name }}</span>
                        </span>
                        <span class="flex min-w-0 items-center gap-2">
                            <span class="text-ink-3">vs</span>
                            <x-clan-tag :clan="$match->sideClan('challenged')" :tag="$match->challenged_tag" size="sm" compact />
                            <span @class(['truncate', 'font-bold' => $match->winner === 'challenged', 'text-ink-2' => $match->winner === 'challenger']) data-test="match-side-name">{{ $match->challenged_name }}</span>
                        </span>
                    </span>
                    <span class="flex flex-col leading-tight lg:order-none"><b>{{ $score['text'] }}</b>@if ($score['sub'] !== '')<span class="text-[11px] text-btc-hi">{{ $score['sub'] }}</span>@endif</span>
                    <span class="col-span-2 flex items-center gap-1.5 text-ink-2 max-lg:col-start-2 max-lg:text-xs lg:col-span-1"><x-game-cover :game="$match->game" size="thumb" class="w-8 rounded-xs" title="{{ GameNames::game($match->game) }}" data-test="match-row-cover" /><span class="sr-only">{{ GameNames::game($match->game) }}</span>{{ SeriesPresenter::format($match) }}@if (! $match->rated)<span class="ml-1 rounded-xs border border-line px-1 text-[10px] lg:hidden">{{ __('casual') }}</span>@endif</span>
                    <span class="max-lg:hidden">
                        <span class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-bold" style="background: {{ $chip['bg'] }}; color: {{ $chip['color'] }}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chip['icon'] }}"></path></svg>{{ $chip['label'] }}
                        </span>
                    </span>
                    <span class="relative block h-6 overflow-hidden rounded-sm bg-raised max-lg:col-span-3">
                        <span class="absolute inset-y-0 left-0" style="width: {{ $result['width'] }}; background: {{ $result['color'] }}"></span>
                        <span class="relative flex h-6 items-center justify-center text-xs font-bold">{{ $result['text'] }}</span>
                    </span>
                    <span class="text-right text-ink-2 max-lg:hidden">{{ SeriesPresenter::when($match, $viewer) }}</span>
                </a>
            @empty
                <div class="px-2 py-6">
                    @if ($game !== 'all' && app(GameRegistry::class)->isScore($game))
                        <x-empty-state :heading="__('No runs yet')" :text="__('The first run opens the list.')">
                            <x-button :href="GameNames::page($game)">{{ __('Play :game', ['game' => GameNames::game($game)]) }}</x-button>
                        </x-empty-state>
                    @elseif ($game !== 'all' && app(GameRegistry::class)->isBoard($game))
                        <x-empty-state :heading="__('No games yet')" :text="__('The first game opens the list.')">
                            <x-button :href="\App\Support\GameNames::page($game)">{{ __('Play :game', ['game' => GameNames::game($game)]) }}</x-button>
                        </x-empty-state>
                    @elseif ($game !== 'all' && ! app(GameRegistry::class)->isSeries($game))
                        <x-empty-state :heading="__('No games yet')" :text="__('The first chess game opens the list.')">
                            <x-button :href="route('chess.lobby')">{{ __('Play chess') }}</x-button>
                        </x-empty-state>
                    @else
                        <x-empty-state :heading="__('No matches yet')" :text="__('The first challenge opens the list.')">
                            @auth<x-button :href="route('challenges.create', $game === 'all' ? [] : ['game' => $game])">{{ __('Challenge a clan') }}</x-button>@endauth
                        </x-empty-state>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($this->matches->hasPages())
            @php($page = $this->matches->currentPage())
            @php($last = $this->matches->lastPage())
            <nav aria-label="{{ __('Pages') }}" class="flex justify-center">
                <div class="flex gap-0.5 overflow-hidden rounded-md">
                    @foreach ([['«', 1, __('First page')], ['‹', max(1, $page - 1), __('Previous page')]] as [$glyph, $target, $label])
                        <button type="button" wire:click="gotoPage({{ $target }})" aria-label="{{ $label }}" @disabled($page === 1) class="flex size-11 cursor-pointer items-center justify-center border-0 bg-card text-[13px] text-ink-2 disabled:cursor-default disabled:text-[#4A4A50]">{{ $glyph }}</button>
                    @endforeach
                    @foreach (range(max(1, $page - 2), min($last, $page + 2)) as $number)
                        <button type="button" wire:click="gotoPage({{ $number }})" @if ($number === $page) aria-current="page" @endif
                                @class(['flex size-11 cursor-pointer items-center justify-center border-0 text-[13px]', 'bg-btc font-bold text-on-btc' => $number === $page, 'bg-card text-ink-2' => $number !== $page])>{{ $number }}</button>
                    @endforeach
                    @foreach ([['›', min($last, $page + 1), __('Next page')], ['»', $last, __('Last page')]] as [$glyph, $target, $label])
                        <button type="button" wire:click="gotoPage({{ $target }})" aria-label="{{ $label }}" @disabled($page === $last) class="flex size-11 cursor-pointer items-center justify-center border-0 bg-card text-[13px] text-ink-2 disabled:cursor-default disabled:text-[#4A4A50]">{{ $glyph }}</button>
                    @endforeach
                </div>
            </nav>
        @endif
    </div>
</div>
