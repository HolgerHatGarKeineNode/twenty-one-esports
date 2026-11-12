<?php

use App\Enums\BoardGameStatus;
use App\Enums\ChessGameStatus;
use App\Enums\HyperMatchStatus;
use App\Enums\PongMatchStatus;
use App\Enums\SeriesStatus;
use App\Games\Blockfill;
use App\Games\GameKind;
use App\Games\GameRegistry;
use App\Games\Hyperbitcoinization;
use App\Models\BoardGame;
use App\Models\ChessGame;
use App\Models\Clan;
use App\Models\HyperMatch;
use App\Models\PongMatch;
use App\Models\ScoreRun;
use App\Models\SeriesMatch;
use App\Models\StackerRun;
use App\Support\Engagement\HomeBoard;
use App\Support\GameNames;
use App\Support\Hyper\HyperTeams;
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
 * chain has none. Hyperbitcoinization matches (plan "Hyperbitcoinization",
 * P6) share strip and table while the game is on: live or done, each opening
 * its full-screen page in a new tab. So do finished live Proof of Pong matches
 * (plan "Proof of Pong", P2): one row each, the winner alone, never on the
 * season chain.
 */
new #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->title(__('Matches'));
        app(PageMeta::class)->describe(__('Matches'), ScoreAttempts::slugs() === []
            ? __('Every series and game of the TWENTY ONE esports league by match number: live, scheduled, waiting for confirmation and done.')
            : __('Every match of the TWENTY ONE esports league by match number, and every highscore attempt: live, scheduled, waiting for confirmation and done.'));
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

    /** The advanced statistics are read only once they are opened (rule R7: loaded only when opened). */
    public bool $statsOpen = false;

    /** How many finished blocks the chain shows; dragging it to the left end loads older ones (loadEarlier). */
    public int $history = MempoolStrip::SIDE;

    /** Older blocks for the chain's left end (canvas NEU (Scope) "Historie lädt links nach"), up to MempoolStrip::HISTORY. */
    public function loadEarlier(): void
    {
        $this->history = min(MempoolStrip::HISTORY, $this->history + 10);
        unset($this->strip);
    }

    /**
     * The address of this page with one filter changed: the chips are links (the filter is the page's URL), a new
     * filter starts on page 1.
     *
     * @param  array<string, string>  $change
     */
    public function filterUrl(array $change): string
    {
        $query = array_filter([...['game' => $this->game, 'clan' => $this->clan, 'status' => $this->status, 'chain' => $this->chain], ...$change],
            fn (string $value, string $key): bool => $value !== '' && ! ($value === 'all' && $key !== 'clan'), ARRAY_FILTER_USE_BOTH);

        return route('matches.index', $query);
    }

    /**
     * The game chips in the registry's display order (rule R19: browser games first), the ones the table can show.
     *
     * @return list<string>
     */
    public function orderedGames(): array
    {
        $listable = $this->gameFilters();

        return array_values(array_filter(array_keys(app(GameRegistry::class)->all()), fn (string $slug): bool => in_array($slug, $listable, true)));
    }

    /**
     * The advanced statistics under the table (canvas NEU (Scope)): the rows of this page by kind, and today's
     * checked Blockfill runs (count, fastest, median, slowest) while Blockfill is listed.
     *
     * @return array{kinds: list<array{label: string, count: int}>, runs: array{count: int, fastest: string, median: string, slowest: string}|null}
     */
    #[Computed]
    public function stats(): array
    {
        $kinds = [];
        foreach ($this->matches as $row) {
            $model = $row['model'];
            $label = match (true) {
                $row['type'] === 'run' => __('highscore').' ('.GameNames::game(ScoreAttempts::game($model)).')',
                $row['type'] === 'pong' => __('casual').' ('.GameNames::game(\App\Games\ProofOfPong::SLUG).')',
                (bool) ($model->rated ?? false) => __('rated'),
                default => __('casual'),
            };
            $kinds[$label] = ($kinds[$label] ?? 0) + 1;
        }
        arsort($kinds);

        $runs = null;
        if (ScoreAttempts::blockfill()) {
            $today = ScoreAttempts::stacker('done')->whereNotNull('ticks')
                ->where('verified_at', '>=', \Carbon\CarbonImmutable::now(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->startOfDay())
                ->orderBy('ticks')->orderBy('id')->limit(500)->get();
            if ($today->isNotEmpty()) {
                $values = $today->map(fn (StackerRun $run): string => (string) ScoreAttempts::value($run))->values();
                $runs = ['count' => $today->count(), 'fastest' => $values->first(), 'median' => $values[intdiv($values->count() - 1, 2)], 'slowest' => $values->last()];
            }
        }

        return ['kinds' => array_map(fn (string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($kinds), $kinds), 'runs' => $runs];
    }

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

    /** The "Team" filter: chess team matches only (plan "Schach Rapid und Clan", P6). */
    public const TEAM = 'team';

    private function listable(string $game): bool
    {
        return $game === 'all' || $game === self::TEAM || in_array($game, $this->gameFilters(), true);
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
            ->when($this->game === self::TEAM, fn (Builder $query) => $query->whereNotNull('boards'))
            ->when($this->game !== 'all' && $this->game !== self::TEAM, fn (Builder $query) => $query->where('game', $this->game))
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
     * Whether Hyperbitcoinization matches (plan "Hyperbitcoinization", P6) can show under these filters: while the
     * game is on and routed, under "all" or its own filter, and, as chess, only "live" and "done".
     */
    private function listsHyper(string $status): bool
    {
        return MempoolStrip::hyper() && in_array($this->game, ['all', Hyperbitcoinization::SLUG], true) && $this->chessStatuses($status) !== [];
    }

    /**
     * @param  Builder<HyperMatch>  $query
     * @return Builder<HyperMatch>
     */
    private function filteredHyper(Builder $query, string $status): Builder
    {
        $clan = $this->selectedClan;
        // One row per match a player won, once it is over (user 2026-10-09): no running match, no bot's win.
        return MempoolStrip::onChain($query, $this->chain)
            ->where('status', HyperMatchStatus::Finished)
            ->whereHas('seats', fn (Builder $seats) => $seats->where('place', 1)->whereNotNull('user_id')->where('bot', false))
            ->when(! in_array($status, ['all', 'done'], true), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($clan !== null, fn (Builder $query) => $query->whereHas('seats.user.clanMember', fn (Builder $query) => $query->where('clan_id', $clan->id)));
    }

    /**
     * Whether Proof of Pong matches (plan "Proof of Pong", P2) can show under these filters: while the game is on and
     * routed, under "all" or its own filter, only "done" (a running match is not listed), never on the season chain.
     */
    private function listsPong(string $status): bool
    {
        return MempoolStrip::pong() && $this->chain !== 'season' && in_array($this->game, ['all', \App\Games\ProofOfPong::SLUG], true) && in_array($status, ['all', 'done'], true);
    }

    /**
     * One row per finished match, its winner alone; the clan filter keeps the winners of that clan.
     *
     * @param  Builder<PongMatch>  $query
     * @return Builder<PongMatch>
     */
    private function filteredPong(Builder $query): Builder
    {
        $clan = $this->selectedClan;

        // Only a match that was played: a win without a start (a tournament no-show) is no game.
        return $query->where('status', PongMatchStatus::Finished)->whereNotNull('winner_id')->whereNotNull('started_at')
            ->when($clan !== null, fn (Builder $query) => $query->whereHas('winner.clanMember', fn (Builder $query) => $query->where('clan_id', $clan->id)));
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
        return $this->game === 'all' || $this->game === self::TEAM || app(GameRegistry::class)->isSeries($this->game);
    }

    /**
     * Rows of the table, newest first: `series` rows carry a SeriesMatch,
     * `chess` rows a ChessGame, `board` rows a BoardGame, `hyper` rows a
     * HyperMatch, `pong` rows a PongMatch, `run` rows a highscore attempt (a StackerRun of Blockfill
     * or a ScoreRun) and its link.
     *
     * @return LengthAwarePaginator<int, array{type: 'series'|'chess'|'board'|'hyper'|'pong'|'run', model: SeriesMatch|ChessGame|BoardGame|HyperMatch|PongMatch|StackerRun|ScoreRun, href?: string}>
     */
    #[Computed]
    public function matches(): LengthAwarePaginator
    {
        $perPage = 20;
        $page = $this->getPage();
        $take = $page * $perPage;
        $series = ! $this->listsSeries() ? collect() : $this->filtered(SeriesMatch::query()->with(['latestReport', 'challengerLineup.clan', 'challengedLineup.clan']), $this->status)->latest()->limit($take)->get()->tap(fn ($list) => \App\Support\Chess\ChessTeamMatches::preload($list));
        $chess = $this->listsChess($this->status) ? $this->filteredChess(ChessGame::query()->with(['white', 'black', 'seriesMatch:id,number']), $this->status)->latest()->limit($take)->get() : collect();
        $boards = $this->listsBoards($this->status) ? $this->filteredBoards(BoardGame::query()->with(['white', 'black', 'seriesMatch:id,number']), $this->status)->latest()->limit($take)->get() : collect();
        $hyper = $this->listsHyper($this->status) ? $this->filteredHyper(HyperMatch::query()->with('seats.user'), $this->status)->latest()->limit($take)->get()->tap(fn ($list) => HyperTeams::preload($list)) : collect();
        $pong = $this->listsPong($this->status) ? $this->filteredPong(PongMatch::query()->with('winner'))->latest()->limit($take)->get() : collect();
        $state = (string) $this->runState($this->status);
        $runs = collect([
            ...($this->listsStacker($this->status) ? ScoreAttempts::stacker($state, $this->selectedClan)->with('user')->latest()->limit($take)->get()->all() : []),
            ...($this->listsScores($this->status) ? ScoreAttempts::scores($this->listedScores(), $state, $this->selectedClan)->with('user')->latest()->limit($take)->get()->all() : []),
        ]);
        $total = (! $this->listsSeries() ? 0 : $this->filtered(SeriesMatch::query(), $this->status)->count())
            + ($this->listsChess($this->status) ? $this->filteredChess(ChessGame::query(), $this->status)->count() : 0)
            + ($this->listsBoards($this->status) ? $this->filteredBoards(BoardGame::query(), $this->status)->count() : 0)
            + ($this->listsHyper($this->status) ? $this->filteredHyper(HyperMatch::query(), $this->status)->count() : 0)
            + ($this->listsPong($this->status) ? $this->filteredPong(PongMatch::query())->count() : 0)
            + $this->runCount($this->status);

        $rows = $series->map(fn (SeriesMatch $match) => ['type' => 'series', 'model' => $match])
            ->concat($chess->map(fn (ChessGame $game) => ['type' => 'chess', 'model' => $game]))
            ->concat($boards->map(fn (BoardGame $game) => ['type' => 'board', 'model' => $game]))
            ->concat($hyper->map(fn (HyperMatch $match) => ['type' => 'hyper', 'model' => $match]))
            ->concat($pong->map(fn (PongMatch $match) => ['type' => 'pong', 'model' => $match]))
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
                    + ($this->listsHyper($status) ? $this->filteredHyper(HyperMatch::query(), $status)->count() : 0)
                    + ($this->listsPong($status) ? $this->filteredPong(PongMatch::query())->count() : 0)
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
        return MempoolStrip::build(auth()->user(), $this->chain, runs: true, history: $this->history);
    }
}; ?>

@php
    $viewer = auth()->user();
    $labels = SeriesPresenter::statusLabels();
    $strip = $this->strip;
    $counts = $this->counts;
    $registry = app(GameRegistry::class);
    $board = new HomeBoard($viewer);
    $chainChips = array_map(fn (string $key, string $label): array => ['key' => $key, 'label' => $label, 'href' => $this->filterUrl(['chain' => $key]), 'active' => $chain === $key],
        ['all', 'season', 'casual'], [__('All'), __('Season'), __('Casual')]);
    $gameChips = [
        ['key' => 'all', 'label' => __('All'), 'href' => $this->filterUrl(['game' => 'all']), 'active' => $game === 'all'],
        ...array_map(fn (string $slug): array => ['key' => $slug, 'label' => __($registry->get($slug)->assets()->shortLabel), 'href' => $this->filterUrl(['game' => $slug]), 'active' => $game === $slug], $this->orderedGames()),
        ['key' => 'team', 'label' => __('Team'), 'href' => $this->filterUrl(['game' => 'team']), 'active' => $game === 'team'],
    ];
    $statusChips = [
        ['key' => 'all', 'label' => __('All'), 'href' => $this->filterUrl(['status' => 'all']), 'active' => $status === 'all'],
        ...array_map(fn (string $key, int $count): array => ['key' => $key, 'label' => $labels[$key], 'count' => $count, 'href' => $this->filterUrl(['status' => $key]), 'active' => $status === $key], array_keys($counts), $counts),
    ];
    $clanOptions = ['' => __('All clans'), ...$this->clans->mapWithKeys(fn ($option) => [$option->slug => $option->name])->all()];
    $matches = $this->matches;
@endphp

{{--
    The Mempool (Mempool.dc.html, MempoolPhone.dc.html; template T14; rules R2, R8, R14): the head with its two counts,
    the chain filter, "gerade eben", the chain of cubes (drag, wheel, keys; older blocks load at the left end), the
    legend and the proud moments; then the matches with their game and status chips, the clan dropdown, one table
    and the advanced statistics folded under it.
--}}
<div class="flex grow flex-col gap-6 px-4 pt-4 pb-10 lg:px-12 lg:pt-8" data-test="matches">
    {{-- An open cup match and the viewer's open match rooms come first (users, 2026-10-02 and 2026-10-03). --}}
    @auth
        <livewire:cup-match variant="banner" frame="block" wire:key="cup-banner-matches" />
        <livewire:upcoming-events only="series" frame="" wire:key="upcoming-rooms" />
    @endauth

    <section class="flex flex-col gap-4" aria-labelledby="mempool-h" data-test="mempool-head">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <h1 id="mempool-h" class="rv-h1 max-lg:text-[24px]">{{ __('Mempool') }}</h1>
            <span class="text-[13px] text-ink-2 max-lg:hidden">{{ __('Matches and highscore attempts of every game, done on the left, waiting on the right.') }}</span>
            <span class="grow"></span>
            <span class="flex items-end gap-6 max-lg:gap-3" data-test="mempool-counts">
                <span class="flex flex-col items-start gap-1 max-lg:flex-row max-lg:items-center max-lg:gap-1.5">
                    <span class="flex items-center gap-2"><i class="mp-dot max-lg:hidden" aria-hidden="true"></i><b class="mp-big max-lg:text-[20px]" data-test="mempool-live">{{ $counts['live'] }}</b></span>
                    <span class="rv-m max-lg:order-first max-lg:hidden">{{ __('playing now') }}</span>
                    <span class="rv-live h-5 lg:hidden max-lg:order-first"><i></i>{{ __('live') }}</span>
                </span>
                <span class="flex flex-col items-start gap-1 max-lg:flex-row max-lg:items-center max-lg:gap-1.5">
                    <b class="mp-big max-lg:text-[20px]" data-test="mempool-done">{{ $counts['done'] }}</b>
                    <span class="rv-m max-lg:order-first">{{ __('finished') }}</span>
                </span>
            </span>
            <a href="{{ route('rules') }}#season-chain" class="rv-lk text-[13px] max-lg:hidden" data-test="mempool-rules">{{ __('How the mempool works') }}</a>
        </div>

        <x-ui.chips :label="__('Chain')" :items="$chainChips" test="chain" />
        <x-ui.live-bar :ticks="$board->ticker()" />

        @if ($strip['finished'] !== [] || $strip['running'] !== [])
            <x-ui.mempool-chain :finished="$strip['finished']" :running="$strip['running']" history="loadEarlier" stamps :more="count($strip['finished']) >= $history && $history < \App\Support\Matches\MempoolStrip::HISTORY" />
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rv-m max-lg:hidden" data-test="mempool-legend">
                <span class="flex items-center gap-1"><i class="block size-2.5 bg-ink-2" aria-hidden="true"></i>{{ __('done, a colour per game and the name') }}</span>
                <span class="flex items-center gap-1"><i class="block size-2.5 border border-edge" aria-hidden="true"></i>{{ __('playing, fills with every move') }}</span>
                <span class="flex items-center gap-1"><span class="rv-tag h-[18px]">{{ __('casual') }}</span>{{ __('not rated') }}</span>
                <span>{{ __('Dashed: done and waiting part here') }}</span>
            </div>
        @else
            <p class="m-0 text-[13px] text-ink-2">{{ __('No match in the mempool yet.') }} <a href="{{ route('play') }}" class="rv-lk">{{ __('All games') }}</a></p>
        @endif

        <x-ui.pride-row :moments="$board->pride()" class="max-lg:hidden" />
    </section>

    <section class="flex flex-col gap-3" aria-labelledby="matches-h">
        <h2 id="matches-h" class="rv-h2 mt-2 max-lg:sr-only">{{ __('Matches') }}</h2>
        <x-ui.chips :label="__('Game title')" :items="$gameChips" test="game" />
        <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
            <x-ui.chips :label="__('Status')" :items="$statusChips" test="status" class="min-w-0 grow" />
            <x-ui.dropdown :label="__('Clan')" :options="$clanOptions" :value="$clan" model="clan" test="clan-filter" class="w-[200px]" />
        </div>

        <div class="rv-card overflow-hidden p-0" data-test="matches-table">
            <div class="mp-th" aria-hidden="true">
                <span>{{ __('Match') }}</span><span>{{ __('Kind') }}</span><span>{{ __('Sides') }}</span><span>{{ __('Result') }}</span><span>{{ __('Format') }}</span><span>{{ __('Status') }}</span><span>{{ __('Verification') }}</span><span class="text-right">{{ __('When') }}</span>
            </div>
            @forelse ($matches as $row)
                @switch($row['type'])
                    @case('chess')
                        @include('pages.matches.partials.chess-row', ['chessGame' => $row['model'], 'viewer' => $viewer])
                        @break
                    @case('board')
                        @include('pages.matches.partials.board-row', ['boardGame' => $row['model'], 'viewer' => $viewer])
                        @break
                    @case('hyper')
                        @include('pages.matches.partials.hyper-row', ['hyperMatch' => $row['model'], 'viewer' => $viewer])
                        @break
                    @case('pong')
                        @include('pages.matches.partials.pong-row', ['pongMatch' => $row['model'], 'viewer' => $viewer])
                        @break
                    @case('run')
                        @include('pages.matches.partials.score-row', ['run' => $row['model'], 'href' => $row['href']])
                        @break
                    @default
                        @include('pages.matches.partials.series-row', ['match' => $row['model'], 'viewer' => $viewer])
                @endswitch
            @empty
                <div class="px-4 py-6">
                    @if ($game !== 'all' && $registry->isScore($game))
                        <x-empty-state :heading="__('No runs yet')" :text="__('The first run opens the list.')">
                            <x-button :href="GameNames::page($game)">{{ __('Play :game', ['game' => GameNames::game($game)]) }}</x-button>
                        </x-empty-state>
                    @elseif ($game === \App\Games\Hyperbitcoinization::SLUG || $game === \App\Games\ProofOfPong::SLUG)
                        <x-empty-state :heading="__('No matches yet')" :text="__('The first match opens the list.')">
                            <x-button :href="GameNames::page($game)">{{ __('Play :game', ['game' => GameNames::game($game)]) }}</x-button>
                        </x-empty-state>
                    @elseif ($game !== 'all' && $registry->isBoard($game))
                        <x-empty-state :heading="__('No games yet')" :text="__('The first game opens the list.')">
                            <x-button :href="GameNames::page($game)">{{ __('Play :game', ['game' => GameNames::game($game)]) }}</x-button>
                        </x-empty-state>
                    @elseif ($game !== 'all' && $game !== 'team' && ! $registry->isSeries($game))
                        <x-empty-state :heading="__('No games yet')" :text="__('The first chess game opens the list.')">
                            <x-button :href="route('chess.lobby')">{{ __('Play chess') }}</x-button>
                        </x-empty-state>
                    @else
                        <x-empty-state :heading="__('No matches yet')" :text="__('The first challenge opens the list.')">
                            @auth<x-button :href="route('challenges.create', in_array($game, ['all', 'team'], true) ? [] : ['game' => $game])">{{ __('Challenge a clan') }}</x-button>@endauth
                        </x-empty-state>
                    @endif
                </div>
            @endforelse

            @if ($matches->hasPages())
                @php($page = $matches->currentPage())
                @php($last = $matches->lastPage())
                <nav aria-label="{{ __('Pages') }}" class="flex items-center justify-center gap-2 border-t border-hairline p-4" data-test="matches-pages">
                    @if ($page > 1)
                        <button type="button" wire:click="gotoPage({{ $page - 1 }})" aria-label="{{ __('Previous page') }}" class="rv-b2 size-11 p-0">‹</button>
                    @endif
                    @foreach (range(max(1, $page - 1), min($last, max(1, $page - 1) + 2)) as $number)
                        <button type="button" wire:click="gotoPage({{ $number }})" @if ($number === $page) aria-current="page" @endif
                                @class(['size-11 p-0', 'rv-b' => $number === $page, 'rv-b2' => $number !== $page])>{{ $number }}</button>
                    @endforeach
                    @if ($page < $last)
                        <button type="button" wire:click="gotoPage({{ $page + 1 }})" aria-label="{{ __('Next page') }}" class="rv-b2 size-11 p-0">›</button>
                    @endif
                    <span class="rv-m">{{ __('of :count pages', ['count' => $last]) }}</span>
                </nav>
            @endif
        </div>

        {{-- Advanced statistics (canvas NEU (Scope)): the same data as the chain and the table, folded until asked for. --}}
        <details class="rv-adv mt-3" data-test="mempool-stats" @if ($statsOpen) open @endif x-on:toggle="if ($el.open && ! $wire.statsOpen) $wire.set('statsOpen', true)">
            <summary><x-icon name="chevron-down" :size="16" />{{ __('Advanced statistics') }} <span class="rv-m font-normal max-lg:hidden">{{ __('from the same data as the chain and the table') }}</span></summary>
            @if (! $statsOpen)
                <div class="h-24 animate-pulse p-4 motion-reduce:animate-none" aria-busy="true"><span class="sr-only">{{ __('Loading…') }}</span></div>
            @else
            @php($stats = $this->stats)
            <div class="grid gap-6 p-4 lg:grid-cols-3">
                <div class="flex flex-col gap-2">
                    <h3 class="rv-h3">{{ __('Status of all matches') }}</h3>
                    @php($total = max(1, array_sum($counts)))
                    <div class="flex h-6 gap-0.5 overflow-hidden rounded-[2px] bg-raised" role="img" aria-label="{{ collect($counts)->map(fn ($count, $key) => $labels[$key].' '.$count)->implode(', ') }}">
                        @foreach (['live' => 'bg-btc', 'to_confirm' => 'bg-btc-hi', 'disputed' => 'bg-loss', 'done' => 'bg-ink-2'] as $key => $colour)
                            @if (($counts[$key] ?? 0) > 0)
                                <span class="{{ $colour }}" style="width: {{ round(100 * $counts[$key] / $total, 2) }}%"></span>
                            @endif
                        @endforeach
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 rv-m">
                        @foreach ($counts as $key => $count)
                            <span>{{ $labels[$key] }} <b class="text-ink tabular-nums">{{ $count }}</b> ({{ \Illuminate\Support\Number::format(100 * $count / $total, 1) }} %)</span>
                        @endforeach
                    </div>
                </div>
                <div class="flex flex-col gap-2">
                    <h3 class="rv-h3">{{ __('Page :page by kind', ['page' => $matches->currentPage()]) }}</h3>
                    <table class="rv-tb">
                        <tbody>
                            @foreach ($stats['kinds'] as $kind)
                                <tr><td>{{ $kind['label'] }}</td><td class="is-r tabular-nums">{{ $kind['count'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($stats['runs'] !== null)
                    <div class="flex flex-col gap-2">
                        <h3 class="rv-h3">{{ __('Blockfill today') }}</h3>
                        <table class="rv-tb">
                            <tbody>
                                <tr><td>{{ __('Runs') }}</td><td class="is-r tabular-nums">{{ $stats['runs']['count'] }}</td></tr>
                                <tr><td>{{ __('Fastest') }}</td><td class="is-r tabular-nums">{{ $stats['runs']['fastest'] }}</td></tr>
                                <tr><td>{{ __('Median') }}</td><td class="is-r tabular-nums">{{ $stats['runs']['median'] }}</td></tr>
                                <tr><td>{{ __('Slowest') }}</td><td class="is-r tabular-nums">{{ $stats['runs']['slowest'] }}</td></tr>
                            </tbody>
                        </table>
                        <a href="{{ GameNames::page(Blockfill::SLUG) }}" class="rv-lk text-[13px]">{{ __('The spread on the Blockfill page') }}</a>
                    </div>
                @endif
            </div>
            @endif
        </details>
    </section>
</div>
