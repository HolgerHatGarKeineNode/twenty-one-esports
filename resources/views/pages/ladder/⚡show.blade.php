<?php

use App\Games\GameMode;
use App\Games\GameRegistry;
use App\Models\Rating;
use App\Support\Board\RatedBoard;
use App\Support\PageMeta;
use App\Support\Rating\LadderBoard;
use App\Support\Rating\Ratings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * The ladder of one game and mode (Ladder.dc.html, ChessLadder.dc.html,
 * LadderPrelaunch.dc.html), from the stored ratings (P7b, completed in P32).
 *
 * Two pools: "Rated" is the season ladder with rank tiers; before Block 0 no
 * ladder is open and it shows the Pre-Season empty state. "Casual" is the
 * permanent casual rating, which never has a tier and counts for nothing
 * else. Chess rates players, Rocket League rates lineups (1v1 players).
 *
 * Without `?pool=` the page opens on the view that has rows: rated once it
 * has a result, casual before (pre-season or an empty season), with a slim
 * note that links to the rated ladder. So every plain link to a ladder lands
 * on content; `?pool=rated|casual` pins a pool.
 *
 * Around the rows (LadderBoard, a fixed number of queries at any size): the
 * live season and its chain height, the ladder's totals and share of wins,
 * the tier lines drawn through the rated table where a rank threshold falls
 * between two rows, each entry's last five results, Block Height and Global
 * Rating on a rated player ladder, the clan view (`?view=clans`: every clan's
 * value on each ladder of the game) and the Proof block.
 */
new #[Layout('layouts::app', ['section' => 'ladder'])] class extends Component
{
    public string $game;

    public string $mode;

    /** The pinned pool, '' = the one that has rows (see activePool()). */
    #[Url(except: '')]
    public string $pool = '';

    /** '' = the entries of this ladder, `clans` = the clan view. */
    #[Url(except: '')]
    public string $view = '';

    public function mount(string $game, string $mode): void
    {
        $registry = app(GameRegistry::class);

        // The board games' blitz (dropped 2026-10-07, user: correspondence only): an old link lands on the game's correspondence ladder.
        if ($mode === 'blitz' && $registry->isBoard($game) && $registry->mode($game, $mode) === null && $registry->mode($game, 'correspondence') !== null) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(redirect()->route('ladder.show', [$game, 'correspondence'], 301));
        }

        abort_if($registry->mode($game, $mode) === null, 404);
        // A score game (plan "AoE2 und Trackmania", P4) has no Elo ladder: its points ladder is on its leaderboards' page.
        abort_if(app(GameRegistry::class)->isScore($game), 404);

        $this->game = $game;
        $this->mode = $mode;
        $this->pool = in_array($this->pool, [Rating::RATED, Rating::CASUAL], true) ? $this->pool : '';
        $this->view = $this->view === 'clans' ? 'clans' : '';
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $game = __(app(GameRegistry::class)->get($this->game)->name());
        $mode = __($this->gameMode->name);
        $title = __(':game :mode ladder', ['game' => $game, 'mode' => $mode]);
        $view->title($title);
        $replace = ['game' => $game, 'mode' => $mode];
        app(PageMeta::class)->describe($title, $this->gameMode->rates === 'player'
            ? __('The rated season ladder and the casual ladder of :game :mode in the TWENTY ONE esports league: rank, rating and results of every player.', $replace)
            : __('The rated season ladder and the casual ladder of :game :mode in the TWENTY ONE esports league: rank, rating and results of every lineup.', $replace))
            ->card(fn () => \App\Support\Cards\PageCard::ladder($this->game, $this->mode));
    }

    public function pickPool(string $pool): void
    {
        $this->pool = $pool === Rating::CASUAL || $this->casualOnly ? Rating::CASUAL : Rating::RATED;
    }

    /**
     * A board game other than chess (plan "Mühle und Dame", P5) has its
     * casual ladder only while its rated queue is not offered (P6,
     * RatedBoard::offered()): no Rated choice and no note about a rated
     * ladder nobody can play on. Offered, it is a ladder like chess.
     */
    #[Computed]
    public function casualOnly(): bool
    {
        return app(GameRegistry::class)->isBoard($this->game) && ! RatedBoard::offered();
    }

    public function pickView(string $view): void
    {
        $this->view = $view === 'clans' ? 'clans' : '';
    }

    #[Computed]
    public function gameMode(): GameMode
    {
        return app(GameRegistry::class)->mode($this->game, $this->mode);
    }

    /**
     * The pool on screen: the pinned one, else rated when it has a row and
     * casual when it has none.
     */
    #[Computed]
    public function activePool(): string
    {
        if ($this->casualOnly) {
            return Rating::CASUAL;
        }

        if ($this->pool !== '') {
            return $this->pool;
        }

        return $this->ratedHasRows ? Rating::RATED : Rating::CASUAL;
    }

    /** Null while the rated ladder is closed (before Block 0). */
    #[Computed]
    public function ratedSeason(): ?string
    {
        return Ratings::season(Rating::RATED, $this->game, $this->mode);
    }

    #[Computed]
    public function ratedHasRows(): bool
    {
        return $this->ratedSeason !== null && Rating::query()
            ->where(['pool' => Rating::RATED, 'season' => $this->ratedSeason, 'game' => $this->game, 'mode' => $this->mode])
            ->where('results', '>', 0)
            ->exists();
    }

    /** Null while this pool has no open ladder (rated before Block 0). */
    #[Computed]
    public function season(): ?string
    {
        return $this->activePool === Rating::RATED ? $this->ratedSeason : Ratings::season(Rating::CASUAL, $this->game, $this->mode);
    }

    #[Computed]
    public function board(): ?LadderBoard
    {
        return $this->season === null ? null : new LadderBoard($this->game, $this->mode, $this->activePool, $this->season);
    }

    /**
     * Rows ordered by rating, then more results, then first rated. Rank
     * numbers count every row; ties share nothing, the order decides.
     *
     * @return Collection<int, array{rank: int, row: Rating, summary: array<string, mixed>}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if ($this->season === null) {
            return collect();
        }

        return Rating::query()
            ->where(['pool' => $this->activePool, 'season' => $this->season, 'game' => $this->game, 'mode' => $this->mode])
            ->where('results', '>', 0)
            ->with(['user.clanMember.clan', 'lineup.clan'])
            ->orderByDesc('rating')->orderByDesc('results')->orderBy('id')
            ->limit(LadderBoard::LIMIT)
            ->get()
            ->values()
            ->map(fn (Rating $row, int $index) => ['rank' => $index + 1, 'row' => $row, 'summary' => Ratings::summary($row, $this->activePool)]);
    }

    /**
     * Chess in ChessModes order (rapid, the default, first: plan "Schach Rapid und Clan", P3), every other game in registry order.
     *
     * @return list<array{0: string, 1: string}> mode slug => label, for the switch
     */
    #[Computed]
    public function modes(): array
    {
        $modes = app(GameRegistry::class)->get($this->game)->modes();

        if ($this->game === 'chess') {
            $modes = array_map(fn (string $slug): GameMode => $modes[$slug], \App\Support\Chess\ChessModes::all());
        }

        return array_values(array_map(fn (GameMode $mode) => [$mode->slug, __($mode->name)], $modes));
    }
}; ?>

@php
    $registry = app(\App\Games\GameRegistry::class);
    $gameName = __($registry->get($game)->name());
    $modeName = __($this->gameMode->name);
    $players = $this->gameMode->rates === 'player';
    $active = $this->activePool;
    $rated = $active === 'rated';
    $clansView = $view === 'clans';
    $board = $this->board;
    $rows = $this->rows;
    $tab = 'inline-flex h-11 cursor-pointer items-center border-0 px-4 text-[13px] whitespace-nowrap';

    $context = $board?->seasonContext();
    $totals = $board?->totals() ?? ['entries' => 0, 'results' => 0, 'wins' => 0, 'games' => 0, 'average' => null];
    $shares = $board === null ? [] : $board->shares($rows->pluck('row'), $totals['entries'], $totals['wins']);
    $form = $board === null || $clansView ? [] : $board->form($rows->map(fn (array $entry): int => $entry['row']->id)->all());
    $score = $board === null || ! $players || $clansView ? ['heights' => [], 'global' => []]
        : $board->globalScore(array_values(array_unique($rows->map(fn (array $entry): ?int => $entry['row']->user_id)->filter()->all())));
    $showScore = $rated && $players && ! $clansView;
    $last = $board?->lastResult();
    $clans = $board !== null && $clansView ? $board->clans() : [];

    $series = $registry->isSeries($game);
    $letter = fn (float $score): string => $score >= 1.0 ? 'W' : ($score > 0.0 ? 'D' : 'L');
    $word = ['W' => __('win'), 'D' => __('draw'), 'L' => __('loss')];
    $cell = ['W' => 'bg-win-tint text-win', 'D' => 'bg-raised text-ink-2', 'L' => 'bg-loss-tint text-loss'];
    $shown = ['W' => __('W'), 'D' => __('D'), 'L' => __('L')];

    // Grid of the entry rows: mobile rank | name | Elo; from lg the full set.
    $cols = match (true) {
        $showScore => 'lg:grid-cols-[40px_minmax(0,1fr)_150px_64px_96px_112px_96px_64px]',
        $rated => 'lg:grid-cols-[40px_minmax(0,1fr)_150px_64px_96px_112px]',
        default => 'lg:grid-cols-[40px_minmax(0,1fr)_64px_96px_112px]',
    };
    $grid = 'grid grid-cols-[28px_minmax(0,1fr)_56px] gap-3 '.$cols;
    $modeCount = count($this->modes);
@endphp

<div class="flex grow flex-col gap-4 px-4 pb-8 lg:gap-6 lg:px-12" data-test="ladder">
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="flex min-w-0 items-center gap-4">
            <a href="{{ \App\Support\GameNames::page($game) }}" class="shrink-0" aria-label="{{ $gameName }}"><x-game-cover :game="$game" size="thumb" class="w-24 rounded-md shadow-ring lg:w-32" data-test="ladder-game-cover" /></a>
            <div class="flex min-w-0 flex-col gap-2">
                <h1 class="m-0 flex flex-wrap items-baseline gap-x-3 font-display text-[26px] font-bold lg:text-[34px]">
                    {{ $gameName }} {{ $modeName }}
                    <span class="font-sans text-xs font-normal text-ink-2">{{ __('Ladder') }}</span>
                </h1>
                @if ($context !== null)
                    {{-- The season this rated ladder belongs to, and the chain it mines into. --}}
                    <p class="m-0 flex flex-wrap items-center gap-2 text-xs" data-test="ladder-season">
                        <span class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 shadow-ring"><b>{{ \App\Support\Badges\BadgeCopy::season($context['slug']) }}</b><span class="text-ink-2">{{ __('since :date', ['date' => $context['since']->locale(app()->getLocale())->translatedFormat(app()->getLocale() === 'de' ? 'j. M' : 'M j')]) }}</span></span>
                        <a href="{{ route('mining') }}" class="inline-flex h-8 items-center gap-2 rounded-md bg-ground px-3 text-ink shadow-ring hover:text-ink" data-test="ladder-chain">
                            <x-icon name="mining" :size="14" class="text-btc" />{{ $context['height'] === null ? __('Block 0') : __('Block :n', ['n' => $context['height']]) }}
                        </a>
                    </p>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <nav aria-label="{{ __('Mode') }}" class="flex overflow-hidden rounded-md border border-edge">
                @foreach ($this->modes as [$slug, $label])
                    <a href="{{ route('ladder.show', [$game, $slug]) }}{{ $pool === '' ? '' : '?pool='.$pool }}" wire:key="m-{{ $slug }}"
                       @if ($slug === $mode) aria-current="page" @endif
                       @class([$tab, 'border-l border-edge' => ! $loop->first, 'bg-btc font-bold text-on-btc hover:text-on-btc' => $slug === $mode, 'bg-ground text-ink-2 hover:text-ink' => $slug !== $mode])>{{ $label }}</a>
                @endforeach
            </nav>
            @unless ($this->casualOnly)
                <div role="group" aria-label="{{ __('Ladder') }}" class="flex overflow-hidden rounded-md border border-edge">
                    @foreach (['rated' => __('Rated'), 'casual' => __('Casual')] as $key => $label)
                        <button type="button" wire:click="pickPool('{{ $key }}')" aria-pressed="{{ $active === $key ? 'true' : 'false' }}" data-test="pool-{{ $key }}"
                                @class([$tab, 'border-l border-edge' => $key === 'casual', 'bg-raised font-bold text-ink' => $active === $key, 'bg-ground text-ink-2' => $active !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            @endunless
        </div>
    </div>

    {{-- Casual on screen because the rated ladder has no row yet: say why, and link it. --}}
    @if (! $rated && ! $this->ratedHasRows && ! $this->casualOnly)
        @php
            $locked = $this->ratedSeason === null;
        @endphp
        {{-- Below lg: text on one line, the link under it; from lg one 44 px row. --}}
        <p @class(['m-0 grid items-center gap-x-3 rounded-md px-4 text-[13px] leading-normal text-ink-2 shadow-[inset_0_0_0_1px_#2A2A30] lg:flex',
                   'grid-cols-[16px_minmax(0,1fr)]' => $locked, 'grid-cols-1' => ! $locked]) data-test="ladder-rated-note">
            @if ($locked)<x-icon name="lock" :size="16" class="shrink-0" />@endif
            <span class="min-w-0 pt-2.5 lg:grow lg:pb-2.5">{{ $locked ? __('The rated ladder starts at Block 0.') : __('The rated ladder has no results this season yet.') }}</span>
            <a href="{{ route('ladder.show', [$game, $mode]) }}?pool=rated" @class(['inline-flex min-h-11 items-center justify-self-start font-bold whitespace-nowrap', 'col-start-2' => $locked]) data-test="ladder-rated-link">{{ __('See the rated ladder') }}</a>
        </p>
    @endif

    @if ($rated && $this->season === null)
        <section class="flex flex-col gap-4 rounded-lg bg-card px-4 py-5 lg:px-8 lg:py-7" data-test="ladder-preseason">
            <x-empty-state :heading="__('Pre-Season starts at Block 0')"
                           :text="$players
                               ? __('The ladder fills with the first rated game after Block 0. Every player starts at :elo Elo. Until then every game is casual and counts for the casual ladder only.', ['elo' => \App\Support\Rating\RatingSettings::inForce()['rating']['start']])
                               : __('The ladder fills with the first rated series after Block 0. Every lineup starts at :elo Elo. Until then every match is casual and counts for the casual ladder only.', ['elo' => \App\Support\Rating\RatingSettings::inForce()['rating']['start']])">
                <button type="button" wire:click="pickPool('casual')" class="btn-p inline-flex h-11 cursor-pointer items-center rounded-md border-0 bg-btc px-5 text-sm font-bold text-on-btc">{{ __('Show the casual ladder') }}</button>
                <a href="{{ \App\Support\GameNames::page($game) }}" class="inline-flex h-11 items-center rounded-md border border-edge bg-ground px-5 text-sm text-ink hover:text-ink">{{ __('Play a casual game') }}</a>
            </x-empty-state>
        </section>
    @else
        @if ($totals['entries'] > 0)
            {{-- The ladder at a glance: three numbers, and who takes the wins. --}}
            <section aria-label="{{ __('Overview') }}" class="grid grid-cols-1 gap-6 rounded-lg bg-card px-4 py-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] lg:gap-12 lg:px-6" data-test="ladder-overview">
                <dl class="m-0 grid grid-cols-3 content-start gap-3">
                    @foreach ([
                        [$series ? __('Series played') : __('Games'), $totals['games'], 'ladder-stat-games'],
                        [$players ? __('Players') : __('Lineups'), $totals['entries'], 'ladder-stat-entries'],
                        [__('Average Elo'), $totals['average'] ?? '–', 'ladder-stat-average'],
                    ] as [$label, $value, $test])
                        <div class="flex min-w-0 flex-col gap-1">
                            <dt class="text-xs text-ink-2">{{ $label }}</dt>
                            <dd class="m-0 font-display text-2xl font-bold tabular-nums lg:text-[28px]" data-test="{{ $test }}">{{ $value }}</dd>
                        </div>
                    @endforeach
                    @if ($last !== null)
                        <p class="col-span-3 m-0 text-xs text-ink-3" data-test="ladder-last">{{ $last['number'] === null ? __('Standings after the last result, :time', ['time' => $last['at']->diffForHumans()]) : __('Standings after match #:n, :time', ['n' => $last['number'], 'time' => $last['at']->diffForHumans()]) }}</p>
                    @endif
                </dl>

                <div class="flex min-w-0 flex-col gap-3" data-test="ladder-shares">
                    <h2 class="m-0 flex flex-wrap items-baseline gap-x-3 text-[15px] font-bold">{{ __('Share of wins') }} <span class="text-xs font-normal text-ink-3">{{ trans_choice(':count win on this ladder|:count wins on this ladder', $totals['wins']) }}</span></h2>
                    @if ($shares === [])
                        <p class="m-0 text-[13px] text-ink-2">{{ __('No wins yet: every result so far was a draw.') }}</p>
                    @else
                        @php $top = max(array_column($shares, 'share')); @endphp
                        <ol class="m-0 flex list-none flex-col p-0">
                            @foreach ($shares as $share)
                                @php
                                    $entry = $share['row'];
                                    $shareName = $players ? ($entry->user?->displayName() ?? __('Deleted account')) : ($entry->lineup?->clan?->name ?? __('Deleted lineup'));
                                    $percent = (int) round($share['share'] * 100);
                                @endphp
                                <li class="grid min-h-9 grid-cols-[minmax(0,1fr)_64px_80px] lg:grid-cols-[minmax(0,5fr)_minmax(0,4fr)_88px] items-center gap-3 border-b border-hairline text-[13px] last:border-b-0" data-test="ladder-share"
                                    title="{{ __(':name: :wins of :total wins', ['name' => $shareName, 'wins' => $share['wins'], 'total' => $totals['wins']]) }}">
                                    <span class="flex min-w-0 items-center gap-2">
                                        @if ($players && $entry->user)<x-avatar :user="$entry->user" :size="20" />@endif
                                        @unless ($players)<x-clan-tag :clan="$entry->lineup?->clan" compact />@endunless
                                        <span class="truncate">{{ $shareName }}</span>
                                    </span>
                                    <span class="block h-2 rounded-r-[4px] bg-raised" aria-hidden="true"><span class="block h-2 rounded-r-[4px] bg-btc" data-test="ladder-share-bar" style="width: {{ round($share['share'] / $top * 100, 1) }}%"></span></span>
                                    <span class="text-right whitespace-nowrap tabular-nums"><b>{{ $share['wins'] }}</b> <span class="text-ink-2">{{ $percent }}%</span></span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </section>
        @endif

        <section aria-labelledby="ladder-h" class="flex flex-col rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-2 pb-3 lg:px-0">
                <h2 id="ladder-h" class="m-0 text-[15px] font-bold">{{ $rated ? __('Rated ladder') : __('Casual ladder') }}</h2>
                <div role="group" aria-label="{{ __('Ladder view') }}" class="flex overflow-hidden rounded-md border border-edge">
                    @foreach (['' => $players ? __('Players') : __('Lineups'), 'clans' => __('Clans')] as $key => $label)
                        <button type="button" wire:click="pickView('{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" data-test="view-{{ $key === '' ? 'entries' : $key }}"
                                @class([$tab, 'border-l border-edge' => $key === 'clans', 'bg-raised font-bold text-ink' => $view === $key, 'bg-ground text-ink-2' => $view !== $key])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @if ($clansView)
                {{-- One row per clan: its value on each ladder of this game, this mode's column first in weight. --}}
                @php
                    // Literal classes (Tailwind only builds what it finds in the source): two or three modes per game.
                    $clanGrid = 'grid grid-cols-[28px_minmax(0,1fr)_72px] gap-3 '.($modeCount >= 3
                        ? 'lg:grid-cols-[40px_minmax(0,1fr)_96px_96px_96px_64px]'
                        : 'lg:grid-cols-[40px_minmax(0,1fr)_96px_96px_64px]');
                @endphp
                <div class="{{ $clanGrid }} h-8 items-center border-b border-hairline px-2 text-xs font-bold text-ink-2">
                    <span>#</span>
                    <span>{{ __('Clan') }}</span>
                    @foreach ($this->modes as [$slug, $label])
                        <span @class(['text-right', 'hidden lg:block' => $slug !== $mode, 'text-ink' => $slug === $mode])>{{ $label }}</span>
                    @endforeach
                    <span class="hidden text-right lg:block">{{ __('Wins') }}</span>
                </div>
                @forelse ($clans as $clanRow)
                    <a href="{{ route('clans.show', $clanRow['clan']) }}" wire:key="c-{{ $clanRow['clan']->id }}" data-test="ladder-clan"
                       class="tr {{ $clanGrid }} min-h-[52px] items-center rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink">
                        <span class="text-ink-3">{{ $clanRow['modes'][$mode]['rank'] ?? '–' }}</span>
                        <span class="flex min-w-0 items-center gap-2">
                            <x-clan-tag :clan="$clanRow['clan']" compact />
                            <span class="truncate font-bold">{{ $clanRow['clan']->name }}</span>
                        </span>
                        @foreach ($this->modes as [$slug, $label])
                            @php
                                $cellValue = $clanRow['modes'][$slug];
                                $isPlayerMode = ($registry->mode($game, $slug)?->rates ?? 'lineup') === 'player';
                            @endphp
                            <span @class(['flex flex-col items-end text-right', 'hidden lg:flex' => $slug !== $mode]) data-test="ladder-clan-{{ $slug }}">
                                @if ($cellValue['value'] !== null)
                                    <b @class(['tabular-nums', 'text-[15px]' => $slug === $mode, 'text-ink-2' => $slug !== $mode])>{{ $cellValue['value'] }}</b>
                                    <span class="text-[11px] text-ink-3">#{{ $cellValue['rank'] }}</span>
                                @elseif ($cellValue['count'] > 0 && $isPlayerMode)
                                    <span class="text-ink-3">–</span>
                                    <span class="text-[11px] text-ink-3">{{ __(':n of :top players', ['n' => $cellValue['count'], 'top' => (int) config('season.clan_rating_top')]) }}</span>
                                @else
                                    <span class="text-ink-3">–</span>
                                @endif
                            </span>
                        @endforeach
                        <span class="hidden text-right text-ink-2 tabular-nums lg:block">{{ $clanRow['wins'] }}</span>
                    </a>
                @empty
                    <p class="m-0 px-2 py-6 text-[13px] text-ink-2" data-test="ladder-clans-empty">{{ __('No clan has a player or lineup on this game’s ladders yet.') }}</p>
                @endforelse
                <p class="mx-2 mt-3 mb-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2 lg:mx-0">{{ __('A player ladder counts the average of a clan’s :n best members, a lineup ladder the clan’s lineup.', ['n' => (int) config('season.clan_rating_top')]) }}</p>
            @else
                <div class="{{ $grid }} h-8 items-center border-b border-hairline px-2 text-xs font-bold text-ink-2">
                    <span>#</span>
                    <span>{{ $players ? __('Player') : __('Lineup') }}</span>
                    @if ($rated)<span class="hidden lg:block">{{ __('Tier') }}</span>@endif
                    <span class="text-right">{{ __('Elo') }}</span>
                    <span class="hidden text-right lg:block">{{ $this->gameMode->allowsDraws ? __('W / D / L') : __('W / L') }}</span>
                    <span class="hidden lg:block">{{ __('Last :n', ['n' => LadderBoard::FORM]) }}</span>
                    @if ($showScore)
                        <span class="hidden text-right lg:block" title="{{ __('Block Height: rated games in any game') }}">{{ __('Block Height') }}</span>
                        <span class="hidden text-right lg:block" title="{{ __('Global Rating: strength across every game of the season') }}" data-test="ladder-global-head">{{ __('Global') }}</span>
                    @endif
                </div>

                @php $previousLine = null; @endphp
                @forelse ($rows as $entry)
                    @php
                        $row = $entry['row'];
                        $summary = $entry['summary'];
                        $badge = \App\Support\Rating\Ratings::badge($summary['tier']);
                        $href = $players ? ($row->user ? route('players.show', $row->user->npub) : null) : ($row->lineup?->clan ? route('clans.show', $row->lineup->clan) : null);
                        $name = $players ? ($row->user?->displayName() ?? __('Deleted account')) : ($row->lineup?->clan?->name ?? __('Deleted lineup'));
                        $clan = $players ? $row->user?->clanMember?->clan : $row->lineup?->clan;
                        $letters = array_map($letter, $form[$row->id] ?? []);
                        $line = $rated ? LadderBoard::line($row->rating) : null;
                    @endphp
                    @if ($line !== null && $line['token'] !== $previousLine)
                        {{-- A tier line: the rank threshold this row and the ones under it sit above. --}}
                        @php
                            $previousLine = $line['token'];
                            $tierColour = 'var(--color-rank-'.preg_replace('/-[123]$/', '', $line['token']).')';
                        @endphp
                        <div class="flex h-7 items-center gap-2 px-2 text-[11px] font-bold" style="color: {{ $tierColour }}" data-test="tier-line" data-tier="{{ $line['token'] }}" wire:key="t-{{ $line['token'] }}">
                            <span class="whitespace-nowrap">{{ $line['label'] }}</span>
                            <span class="h-px grow opacity-60" style="background: {{ $tierColour }}" aria-hidden="true"></span>
                        </div>
                    @endif
                    <a @if ($href) href="{{ $href }}" @endif wire:key="r-{{ $row->id }}" data-test="ladder-row"
                       class="tr {{ $grid }} min-h-[52px] items-center rounded-sm px-2 py-1.5 text-[13px] text-ink hover:text-ink">
                        <span class="text-ink-3">{{ $entry['rank'] }}</span>
                        <span class="flex min-w-0 flex-col gap-1">
                            <span class="flex min-w-0 items-center gap-2">
                                @if ($players && $row->user)<x-avatar :user="$row->user" :size="22" />@endif
                                <x-clan-tag :clan="$clan" compact />
                                <span class="truncate font-bold" data-test="ladder-name">{{ $name }}</span>
                            </span>
                            <span class="flex flex-col items-start gap-1 lg:hidden">
                                @if ($rated)
                                    <x-rank-badge :tier="$badge['tier']" :level="$badge['level']" size="sm" />
                                @elseif ($summary['provisional'])
                                    <span class="text-[11px] text-ink-3">{{ __('provisional') }}</span>
                                @endif
                                @if ($letters !== [])
                                    <x-ladder-form :letters="$letters" :words="$word" :cells="$cell" :shown="$shown" />
                                @endif
                            </span>
                            @if (! $rated && $summary['provisional'])
                                <span class="hidden text-[11px] text-ink-3 lg:block">{{ __('provisional') }}</span>
                            @endif
                        </span>
                        @if ($rated)
                            <span class="hidden lg:block" data-test="ladder-tier"><x-rank-badge :tier="$badge['tier']" :level="$badge['level']" /></span>
                        @endif
                        <b class="text-right text-[15px] tabular-nums" data-test="ladder-elo">{{ $summary['rating'] }}</b>
                        <span class="hidden text-right whitespace-nowrap text-ink-2 tabular-nums lg:block">{{ $this->gameMode->allowsDraws ? $summary['wins'].' / '.$summary['draws'].' / '.$summary['losses'] : $summary['wins'].' / '.$summary['losses'] }}</span>
                        <span class="hidden lg:block" data-test="ladder-form">
                            @if ($letters !== [])
                                <x-ladder-form :letters="$letters" :words="$word" :cells="$cell" :shown="$shown" />
                            @endif
                        </span>
                        @if ($showScore)
                            <span class="hidden text-right text-ink-2 tabular-nums lg:block" data-test="ladder-height">{{ $row->user_id === null ? '–' : ($score['heights'][$row->user_id] ?? 0) }}</span>
                            <span class="hidden text-right tabular-nums lg:block" data-test="ladder-global">{{ $row->user_id !== null && isset($score['global'][$row->user_id]) ? $score['global'][$row->user_id] : '–' }}</span>
                        @endif
                    </a>
                @empty
                    <x-empty-state class="px-2 py-6" :heading="$rated ? __('No rated results yet') : __('No casual results yet')"
                                   :text="__('The first finished game opens the table. Every new entry starts at :elo Elo.', ['elo' => (int) config($rated ? 'season.rating.start' : 'season.casual.start')])">
                        {{-- An empty table invites the first game (P16): the page where this game is played. --}}
                        <a href="{{ \App\Support\GameNames::page($game) }}" class="btn-p inline-flex h-11 items-center rounded-md bg-btc px-5 text-sm font-bold text-on-btc hover:text-on-btc" data-test="ladder-empty-play">{{ $rated ? __('Play a rated game') : __('Play a casual game') }}</a>
                    </x-empty-state>
                    {{-- An empty table fills faster with friends: the invite of this game (chess: a daily link, series: the clan's join link). --}}
                    <div class="px-2 pb-2" data-test="ladder-empty-invite"><livewire:invite-link :game="$game" place="ladder" :wire:key="'invite-ladder-'.$game.'-'.$mode" /></div>
                @endforelse

                @if ($rows->isNotEmpty())
                    <p class="mx-2 mt-3 mb-0 border-t border-hairline pt-3 text-xs leading-[1.6] text-ink-2 lg:mx-0">
                        @if ($rated)
                            {{ __('Rank from Elo alone, provisional until :n rated results.', ['n' => \App\Support\Rating\RatingSettings::inForce()['rating']['provisional']]) }}
                            @if ($showScore) {{ __('Global Rating shows from :n rated results in the season.', ['n' => (int) config('season.global_rating_min_weight')]) }} @endif
                            {{-- The cross-game list (P40): every rated ladder feeds it, lineup ladders through their rosters. --}}
                            <a href="{{ route('ladder.strongest') }}" class="inline-flex min-h-11 items-center" data-test="ladder-strongest-link">{{ __('Strongest players across all games') }}</a>
                        @elseif (config('season.casual.daily_pair_limit') !== null)
                            {{ __('Casual Elo is just for fun: it never counts for ranks, badges, Block Height, mining or rewards. At most :n games of the same pairing per day move it.', ['n' => (int) config('season.casual.daily_pair_limit')]) }}
                        @else
                            {{ __('Casual Elo is just for fun: it never counts for ranks, badges, Block Height, mining or rewards.') }}
                        @endif
                    </p>
                @endif
            @endif
        </section>

        <x-proof :rows="$board?->proof() ?? []" toggle="show" class="border-0 bg-proof-fill shadow-[inset_0_0_0_1px_var(--color-proof-ring)]" data-test="ladder-proof">
            {{ $rated ? __('Anyone can recompute this table and every Elo from the league’s signed records.') : __('Casual ratings stay with the league; only rated results go on Nostr.') }}
            <a href="{{ route('protocol') }}#verify" class="inline-flex min-h-11 items-center" data-test="ladder-proof-link">{{ __('Check it yourself') }}</a>
        </x-proof>
    @endif
</div>
