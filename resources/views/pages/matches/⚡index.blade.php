<?php

use App\Enums\ChessGameStatus;
use App\Enums\SeriesStatus;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\SeriesMatch;
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
 * Matches, 1:1 from Matches.dc.html: the block strip over the latest series,
 * filters (game, clan, status) and the table. Rocket League series and chess
 * games (blitz and daily) share the table; the Game filter narrows it. Chess
 * games only know "live" and "done" (a chess game starts when it is created);
 * every row carries its league match number, one sequence for both (P7b).
 */
new #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Matches'));
        app(PageMeta::class)->describe(__('Matches'), __('Every Rocket League series and chess game of the TWENTY ONE esports league by match number: live, scheduled, waiting for confirmation and done.'));
    }

    use WithPagination;

    #[Url(except: 'all')]
    public string $game = 'all';

    #[Url(except: '')]
    public string $clan = '';

    #[Url(except: 'all')]
    public string $status = 'all';

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

    public function pickGame(string $game): void
    {
        $this->game = in_array($game, ['all', 'chess', 'rocket-league'], true) ? $game : 'all';
        $this->resetPage();
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

        return $query
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

        return $query
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
        return $this->game !== 'rocket-league' && $this->chessStatuses($status) !== [];
    }

    /**
     * Rows of the table, newest first: `series` rows carry a SeriesMatch,
     * `chess` rows a ChessGame.
     *
     * @return LengthAwarePaginator<int, array{type: 'series'|'chess', model: SeriesMatch|ChessGame}>
     */
    #[Computed]
    public function matches(): LengthAwarePaginator
    {
        $perPage = 20;
        $page = $this->getPage();
        $take = $page * $perPage;
        $series = $this->game === 'chess' ? collect() : $this->filtered(SeriesMatch::query()->with('latestReport'), $this->status)->latest()->limit($take)->get();
        $chess = $this->listsChess($this->status) ? $this->filteredChess(ChessGame::query()->with(['white', 'black']), $this->status)->latest()->limit($take)->get() : collect();
        $total = ($this->game === 'chess' ? 0 : $this->filtered(SeriesMatch::query(), $this->status)->count())
            + ($this->listsChess($this->status) ? $this->filteredChess(ChessGame::query(), $this->status)->count() : 0);

        $rows = $series->map(fn (SeriesMatch $match) => ['type' => 'series', 'model' => $match])
            ->concat($chess->map(fn (ChessGame $game) => ['type' => 'chess', 'model' => $game]))
            ->sortByDesc(fn (array $row) => $row['model']->created_at)
            ->values()
            ->slice(($page - 1) * $perPage, $perPage)
            ->values();

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
                $counts[$status] = ($this->game === 'chess' ? 0 : $this->filtered(SeriesMatch::query(), $status)->count())
                    + ($this->listsChess($status) ? $this->filteredChess(ChessGame::query(), $status)->count() : 0);
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
        return Clan::query()->whereHas('lineups', fn (Builder $query) => $query->where('game', 'rocket-league'))->orderBy('name')->get();
    }

    /**
     * The block strip: finished series left (newest at the divider), running
     * and scheduled right (next first).
     *
     * @return array{finished: list<array<string, mixed>>, running: list<array<string, mixed>>}
     */
    #[Computed]
    public function strip(): array
    {
        $viewer = auth()->user();
        $finished = SeriesMatch::query()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->orderByDesc('finished_at')->limit(5)->get()->reverse()->values();
        $running = SeriesMatch::query()->with('latestReport')->whereIn('status', [SeriesStatus::Accepted, SeriesStatus::Reported, SeriesStatus::Disputed])->orderBy('start_at')->limit(5)->get();

        return [
            'finished' => $finished->map(fn (SeriesMatch $match, int $index) => SeriesPresenter::block($match, $index === $finished->count() - 1, $viewer))->all(),
            'running' => $running->map(fn (SeriesMatch $match) => SeriesPresenter::block($match, false, $viewer))->all(),
        ];
    }
}; ?>

@php
    $viewer = auth()->user();
    $labels = SeriesPresenter::statusLabels();
    $strip = $this->strip;
    $filterBtn = 'h-[42px] shrink-0 cursor-pointer border-0 px-3.5 text-[13px] whitespace-nowrap';
@endphp

<div class="flex grow flex-col gap-6 pb-10" data-test="matches">
    @if ($strip['finished'] !== [] || $strip['running'] !== [])
        <x-block-strip :finished="$strip['finished']" :running="$strip['running']" focus="rl" />
    @endif

    <div class="flex flex-col gap-5 px-4 lg:px-12">
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4">
            <h1 class="m-0 font-display text-[28px] font-bold lg:text-[34px]">{{ __('Matches') }}</h1>

            <div class="flex flex-wrap items-center justify-end gap-x-6 gap-y-3">
                <div class="flex items-center gap-2">
                    <span id="f-game" class="text-xs text-ink-3">{{ __('Game title') }}</span>
                    <div role="group" aria-labelledby="f-game" class="flex overflow-hidden rounded-md border border-line">
                        @foreach (['all' => __('All'), 'chess' => __('Chess'), 'rocket-league' => 'Rocket League'] as $key => $label)
                            <button type="button" wire:click="pickGame('{{ $key }}')" aria-pressed="{{ $game === $key ? 'true' : 'false' }}" data-test="game-{{ $key }}"
                                    @class([$filterBtn, 'border-l border-line' => ! $loop->first, 'bg-btc font-bold text-on-btc' => $game === $key, 'bg-ground text-ink-2 hover:text-ink' => $game !== $key])>{{ $label }}</button>
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
                @php($match = $row['model'])
                @php($chip = SeriesPresenter::chip($match))
                @php($result = SeriesPresenter::result($match, $viewer))
                @php($score = SeriesPresenter::score($match))
                <a href="{{ route('matches.show', $match) }}" wire:key="m-{{ $match->id }}" data-test="match-row"
                   class="tr grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm px-2 py-2 text-[13px] text-ink hover:text-ink lg:h-11 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] lg:gap-4 lg:py-0">
                    <span class="font-bold text-btc">{{ $match->label() }}</span>
                    <span class="flex min-w-0 items-center gap-2 whitespace-nowrap">
                        <x-clan-tag :tag="$match->challenger_tag" size="sm" />
                        <span @class(['truncate', 'font-bold' => $match->winner === 'challenger', 'text-ink-2' => $match->winner === 'challenged'])>{{ $match->challenger_name }}</span>
                        <span class="text-ink-3">vs</span>
                        <x-clan-tag :tag="$match->challenged_tag" size="sm" />
                        <span @class(['truncate', 'font-bold' => $match->winner === 'challenged', 'text-ink-2' => $match->winner === 'challenger'])>{{ $match->challenged_name }}</span>
                    </span>
                    <span class="flex flex-col leading-tight lg:order-none"><b>{{ $score['text'] }}</b>@if ($score['sub'] !== '')<span class="text-[11px] text-btc-hi">{{ $score['sub'] }}</span>@endif</span>
                    <span class="col-span-2 flex items-center gap-1.5 text-ink-2 max-lg:col-start-2 max-lg:text-xs lg:col-span-1"><x-icon name="rocket-league" :size="14" />{{ SeriesPresenter::format($match) }}@if (! $match->rated)<span class="ml-1 rounded-xs border border-line px-1 text-[10px] lg:hidden">{{ __('casual') }}</span>@endif</span>
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
                    @if ($game === 'chess')
                        <x-empty-state :heading="__('No games yet')" :text="__('The first chess game opens the list.')">
                            <x-button :href="route('chess.lobby')">{{ __('Play chess') }}</x-button>
                        </x-empty-state>
                    @else
                        <x-empty-state :heading="__('No matches yet')" :text="__('The first challenge opens the list.')">
                            @auth<x-button :href="route('challenges.create')">{{ __('Challenge a clan') }}</x-button>@endauth
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
