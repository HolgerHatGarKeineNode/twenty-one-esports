<?php

use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Models\Clan;
use App\Models\Lineup;
use App\Models\Rating;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Models\User;
use App\Support\Clans\ClanStats;
use App\Support\GameNames;
use App\Support\PageMeta;
use App\Support\Rating\Ratings;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The page of a series game (Rocket League, EA Sports FC), one per registry
 * entry (`games/{slug}`). From the top: the game, the invite, casual 1v1,
 * the next tournament as a poster and the cups as a side mention (P23, P26
 * first slice).
 *
 * P26 (user, 2026-09-28: "zu textlich und nicht aufregend genug"): the rest
 * shows people and results instead of tables and empty charts.
 *  - Matches: live first, then the next ones, then the latest results, as
 *    cards with both sides' faces and the score; the series per week as a
 *    small sparkline beside the heading.
 *  - The ladder's top five with faces and rating, from the mode with the
 *    most results (rated once it has a result, casual before).
 *  - The clans of this game with their logos and wins, and the clan
 *    Hashrate of the last 7 days as one stacked bar (a clan's points are
 *    not split by game).
 *  - What a series is worth: the Elo formula (K 32) in one strip.
 * A part without data says so in one line, never an empty chart.
 */
new #[Layout('layouts::app', ['section' => 'matches'])] class extends Component
{
    public string $slug = '';

    /** Match cards on the page at most (four below sm). */
    public const MATCH_CARDS = 6;

    public function mount(string $slug): void
    {
        abort_unless(app(GameRegistry::class)->isSeries($slug), 404);

        $this->slug = $slug;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $game = GameNames::game($this->slug);
        $modes = implode(', ', array_keys(app(GameRegistry::class)->get($this->slug)->modes()));
        $view->title($game);
        app(PageMeta::class)->describe($game, __(':game in the TWENTY ONE esports league: clan lineups play series in :modes, with Elo per lineup, the latest results and open challenges.', ['game' => $game, 'modes' => $modes]));
    }

    #[Computed]
    public function nextTournament(): ?Tournament
    {
        return Tournament::query()->special()->where('game', $this->slug)->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->orderBy('signup_closes_at')->first();
    }

    /**
     * The clan Hashrate of the live season's last 7 days (ClanStats), clans
     * with points only, most first, with each clan's share of the week.
     *
     * @return Collection<int, array{clan: Clan, points: int, rl: int, share: float}>
     */
    #[Computed]
    public function hashrate(): Collection
    {
        $stats = app(ClanStats::class);
        $rows = Clan::query()->orderBy('name')->get()->map(function (Clan $clan) use ($stats) {
            $hash = $stats->hashrate($clan);

            return ['clan' => $clan, 'points' => $hash['week'], 'rl' => $stats->seriesHashrate($clan, true)];
        })->filter(fn (array $row): bool => $row['points'] > 0)->sortByDesc('points')->values();

        $total = max(1, (int) $rows->sum('points'));

        return $rows->map(fn (array $row) => [...$row, 'share' => $row['points'] / $total]);
    }

    /**
     * The match cards: live series first, then the next ones (open
     * challenges and accepted series still ahead), then the latest results,
     * up to MATCH_CARDS; and the series per week of the last 12 weeks. A
     * casual 1v1 still in its ready check is no card.
     *
     * @return array{cards: list<array{match: SeriesMatch, state: 'live'|'next'|'final'}>, weeks: list<int>}
     */
    #[Computed]
    public function matches(): array
    {
        $base = fn () => SeriesMatch::query()->where('game', $this->slug)->with(['challengerLineup.clan', 'challengedLineup.clan']);

        $live = $base()->where('status', SeriesStatus::Accepted)->where('start_at', '<=', now())->latest('start_at')->limit(self::MATCH_CARDS)->get();
        $next = $base()->where(fn ($query) => $query->where('status', SeriesStatus::Open)
            ->orWhere(fn ($query) => $query->where('status', SeriesStatus::Accepted)->where('start_at', '>', now())))
            ->orderByRaw('coalesce(start_at, respond_by)')->limit(2)->get();
        $final = $base()->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->orderByDesc('finished_at')->limit(self::MATCH_CARDS)->get();

        $cards = collect()
            ->concat($live->map(fn (SeriesMatch $match) => ['match' => $match, 'state' => 'live']))
            ->concat($next->map(fn (SeriesMatch $match) => ['match' => $match, 'state' => 'next']))
            ->concat($final->map(fn (SeriesMatch $match) => ['match' => $match, 'state' => 'final']))
            ->take(self::MATCH_CARDS)
            ->values();

        $since = now()->startOfWeek()->subWeeks(11);
        $perWeek = SeriesMatch::query()->where('game', $this->slug)->whereNotNull('start_at')->where('start_at', '>=', $since)->pluck('start_at')
            ->countBy(fn ($start) => (int) floor($since->diffInWeeks($start)));

        return [
            'cards' => $cards->all(),
            'weeks' => array_map(fn (int $week) => (int) ($perWeek[$week] ?? 0), range(0, 11)),
        ];
    }

    /**
     * The players of the roster sides on the cards (a tournament's 1v1 and
     * mix teams, casual 1v1), in one query.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function rosterPlayers(): Collection
    {
        $ids = collect($this->matches['cards'])->flatMap(fn (array $card) => [...$card['match']->rosterSide('challenger'), ...$card['match']->rosterSide('challenged')])->unique();

        return User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * The ladder's top five of the mode with the most results: the rated
     * ladder once it has a result, the casual one before (as the ladder
     * page opens). Null when no mode has a result.
     *
     * @return array{mode: string, pool: string, rows: Collection<int, Rating>}|null
     */
    #[Computed]
    public function ladder(): ?array
    {
        $best = null;

        foreach (array_keys(app(GameRegistry::class)->get($this->slug)->modes()) as $mode) {
            foreach ([Rating::RATED, Rating::CASUAL] as $pool) {
                $season = Ratings::season($pool, $this->slug, $mode);
                $query = $season === null ? null : Rating::query()->where(['pool' => $pool, 'season' => $season, 'game' => $this->slug, 'mode' => $mode])->where('results', '>', 0);
                $count = $query?->count() ?? 0;

                if ($count > 0) {
                    if ($best === null || $count > $best['count']) {
                        $best = ['mode' => (string) $mode, 'pool' => $pool, 'count' => $count, 'query' => $query];
                    }

                    // Rated wins over casual within a mode.
                    break;
                }
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            'mode' => $best['mode'],
            'pool' => $best['pool'],
            'rows' => $best['query']->with(['user.clanMember.clan', 'lineup.clan'])->orderByDesc('rating')->orderByDesc('results')->orderBy('id')->limit(5)->get(),
        ];
    }

    /**
     * The clans with a lineup in this game, most wins here first, with
     * their series won in this game.
     *
     * @return Collection<int, array{clan: Clan, wins: int}>
     */
    #[Computed]
    public function clans(): Collection
    {
        $clanIds = Lineup::query()->where('game', $this->slug)->distinct()->pluck('clan_id');
        $wins = SeriesMatch::query()->where('game', $this->slug)->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES)
            ->with(['challengerLineup', 'challengedLineup'])->get()
            ->countBy(fn (SeriesMatch $match) => $match->lineup((string) $match->winner)?->clan_id ?? 0);

        return Clan::query()->whereIn('id', $clanIds)->get()
            ->map(fn (Clan $clan) => ['clan' => $clan, 'wins' => (int) ($wins[$clan->id] ?? 0)])
            ->sortBy([['wins', 'desc'], [fn (array $a, array $b) => strcasecmp($a['clan']->name, $b['clan']->name)]])
            ->values();
    }

    /**
     * Elo change of a win and a loss against an opponent `gap` points away (K 32, scale 400).
     *
     * @return array{win: int, loss: int}
     */
    public function stake(int $gap): array
    {
        $expected = 1 / (1 + 10 ** ($gap / 400));

        return ['win' => (int) round(32 * (1 - $expected)), 'loss' => (int) round(32 * $expected)];
    }
}; ?>


@php
    $modes = app(GameRegistry::class)->get($this->slug)->modes();
    $gameName = GameNames::game($this->slug);
    $bestOf = array_values(array_unique(array_merge(...array_values(array_map(fn ($mode) => $mode->bestOf, $modes)))));
    $next = $this->nextTournament;
    $hash = $this->hashrate;
    $hashEmpty = \App\Support\Series\Ladders::season() === null ? __('Hashrate starts at Block 0: rated games earn points for their clan.') : __('No rated game in the last 7 days.');
    $weekTotal = (int) $hash->sum('points');
    $rlTotal = (int) $hash->sum('rl');
    // One accent in four steps: the clans of the Hashrate bar tell apart by lightness, the legend names them.
    $segment = ['bg-btc', 'bg-btc-hi', 'bg-btc-deep', 'bg-cube-fill-deep', 'bg-edge'];
    ['cards' => $cards, 'weeks' => $weeks] = $this->matches;
    $weekSum = array_sum($weeks);
    $weekTop = max(1, max($weeks));
    $players = $this->rosterPlayers;
    $ladder = $this->ladder;
    $clans = $this->clans;
@endphp

<div class="flex grow flex-col" data-test="game-page" data-game="{{ $slug }}">
    {{--
        The head. Below sm the cover sits small next to the name, so the invite under it stays above the
        phone's tab bar (full width, the cover alone took 193 px and pushed the invite to 626 px, under the
        tab bar at 603 px of 667). From sm the cover spans both rows next to name and buttons.
    --}}
    <div @class(['grid grid-cols-[7rem_minmax(0,1fr)] items-center gap-x-3 gap-y-3 px-4 pt-6 pb-4 sm:gap-x-4 sm:gap-y-2 lg:px-12 lg:pt-8',
        'sm:grid-cols-[320px_minmax(0,1fr)] lg:grid-cols-[400px_minmax(0,1fr)]' => $next === null,
        'sm:grid-cols-[200px_minmax(0,1fr)] lg:grid-cols-[240px_minmax(0,1fr)]' => $next !== null])>
        <x-game-cover :game="$slug" size="header" class="w-full rounded-lg shadow-ring sm:row-span-2" />
        <div class="flex min-w-0 flex-col gap-1 sm:gap-2 sm:self-end">
            <h1 class="m-0 font-display text-2xl leading-tight font-bold sm:text-[28px] lg:text-[34px]">{{ $gameName }}</h1>
            <p class="m-0 flex flex-wrap gap-1.5 text-xs font-bold text-ink-2" aria-label="{{ __('Modes: :modes · best of :bo', ['modes' => implode(', ', array_keys($modes)), 'bo' => implode(' / ', $bestOf)]) }}" data-test="game-modes">
                @foreach (array_keys($modes) as $modeName)
                    <span class="inline-flex h-7 items-center rounded-tag bg-raised px-2.5" aria-hidden="true">{{ $modeName }}</span>
                @endforeach
                <span class="inline-flex h-7 items-center rounded-tag bg-raised px-2.5" aria-hidden="true">BO{{ implode('/', $bestOf) }}</span>
            </p>
        </div>
        <div class="col-span-2 flex flex-wrap gap-2 sm:col-span-1 sm:col-start-2 sm:self-start">
            @auth<x-button :href="route('challenges.create', ['game' => $slug])" data-test="game-page-challenge">{{ __('Challenge a clan') }}</x-button>@endauth
            <x-button variant="secondary" :href="route('ladder.show', [$slug, array_key_first($modes)])">{{ __('Ladder') }}</x-button>
            <x-button variant="quiet" :href="route('matches.index', ['game' => $slug])">{{ __('Matches') }}</x-button>
        </div>
    </div>

    {{-- Invite a friend: the join link of the player's clan (series are played by clan lineups) --}}
    <div class="px-4 pb-4 lg:px-12 lg:pb-5"><livewire:invite-link :game="$slug" place="game" /></div>

    {{-- Casual 1v1 without a clan (P23 S3): find an opponent, invite someone looking, answer invites. --}}
    @if (\App\Support\Series\CasualLobby::offers($slug))
        <div class="px-4 pb-4 lg:px-12 lg:pb-5"><livewire:casual-play :game="$slug" /></div>
    @endif

    {{--
        The game's next tournament open for sign-up as a poster (user, 2026-09-28: every game page lacked a
        view of the next tournament; it sat as the eighth card of the grid, under the fold, and read as text).
        Under the invite, which keeps its place in the phone's first screen (InvitePlacementTest).
    --}}
    <div class="px-4 pb-4 lg:px-12 lg:pb-5" data-test="game-next-tournament">
        @if ($next)
            <x-tournaments.poster :tournament="$next" heading-id="game-next-h" />
        @else
            <x-tournaments.next-empty :game="$slug" heading-id="game-next-h" />
        @endif
        <x-tournaments.cup-mentions :game="$slug" class="mt-2" />
    </div>

    {{-- Matches (P26): live, next, latest, with faces and scores. --}}
    <section aria-labelledby="gm-h" class="flex flex-col gap-3 px-4 pb-5 lg:px-12 lg:pb-6" data-test="game-matches">
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
            <h2 id="gm-h" class="m-0 font-display text-lg leading-tight font-bold lg:text-xl">{{ __('Matches') }}</h2>
            <span class="flex items-center gap-3">
                @if ($weekSum > 0)
                    <span class="flex items-center gap-2 text-xs text-ink-2" data-test="game-weeks">
                        <span role="img" aria-label="{{ trans_choice(':count series in the last 12 weeks|:count series in the last 12 weeks', $weekSum) }}" class="flex h-6 items-end gap-0.5">
                            @foreach ($weeks as $count)
                                <span @class(['block w-1.5 rounded-t-[1px]', 'bg-btc' => $count > 0, 'bg-line' => $count === 0]) style="height: {{ $count > 0 ? max(15, round($count / $weekTop * 100)) : 8 }}%"></span>
                            @endforeach
                        </span>
                        <span aria-hidden="true">{{ trans_choice(':count in 12 weeks|:count in 12 weeks', $weekSum) }}</span>
                    </span>
                @endif
                <a href="{{ route('matches.index', ['game' => $slug]) }}" class="inline-flex min-h-11 items-center text-[13px]" data-test="game-matches-all">{{ __('All matches') }}</a>
            </span>
        </div>

        @if ($cards === [])
            <p class="m-0 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg bg-card px-4 py-3 text-[13px] leading-normal text-ink-2 shadow-ring" data-test="game-matches-empty">
                <span>{{ __('No :game series yet.', ['game' => $gameName]) }}</span>
                @if (\App\Support\Series\CasualLobby::offers($slug))<a href="#casual" class="inline-flex min-h-11 items-center">{{ __('Find a 1v1 opponent') }}</a>@endif
                @auth<a href="{{ route('challenges.create', ['game' => $slug]) }}" class="inline-flex min-h-11 items-center">{{ __('Challenge a clan') }}</a>@endauth
            </p>
        @else
            <ul role="list" class="m-0 grid list-none grid-cols-1 gap-3 p-0 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($cards as $index => ['match' => $match, 'state' => $state])
                    <li wire:key="gm-{{ $match->id }}" @class(['min-w-0', 'max-sm:hidden' => $index >= 4])>
                        <x-games.match-card :match="$match" :state="$state" :players="$players" :viewer="auth()->user()" class="h-full" />
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <div class="grid grow grid-cols-1 gap-4 px-4 pb-5 lg:grid-cols-12 lg:items-start lg:gap-5 lg:px-12 lg:pb-6">
        {{-- The ladder's top five (P26) --}}
        <section aria-labelledby="gl-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:col-span-7 lg:px-5" data-test="game-ladder" data-mode="{{ $ladder['mode'] ?? '' }}" data-pool="{{ $ladder['pool'] ?? '' }}">
            <span class="flex flex-wrap items-center justify-between gap-x-3">
                <span class="flex items-center gap-2">
                    <h2 id="gl-h" class="m-0 text-[15px] font-bold">{{ $ladder ? __(':mode ladder', ['mode' => $ladder['mode']]) : __('Ladder') }}</h2>
                    @if ($ladder)<span class="rounded-xs px-1.5 py-0.5 text-[11px] leading-4 text-ink-2 shadow-ring">{{ $ladder['pool'] === 'rated' ? __('Rated') : __('Casual') }}</span>@endif
                </span>
                <a href="{{ route('ladder.show', [$slug, $ladder['mode'] ?? array_key_first($modes)]) }}" class="inline-flex min-h-11 items-center text-[13px]" data-test="game-ladder-link">{{ __('Full ladder') }}</a>
            </span>
            @if ($ladder === null)
                <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="game-ladder-empty">{{ __('No results yet: the ladder fills with the first finished series.') }}</p>
            @else
                <ol class="m-0 grid list-none grid-cols-1 p-0 lg:grid-cols-5 lg:gap-3">
                    @foreach ($ladder['rows'] as $row)
                        @php
                            $isPlayer = $row->user_id !== null;
                            $clan = $isPlayer ? $row->user?->clanMember?->clan : $row->lineup?->clan;
                            $name = $isPlayer ? ($row->user?->displayName() ?? __('Deleted account')) : ($clan?->name ?? __('Deleted lineup'));
                            $href = $isPlayer ? ($row->user ? route('players.show', $row->user->npub) : null) : ($clan ? route('clans.show', $clan) : null);
                        @endphp
                        <li wire:key="gl-{{ $row->id }}" class="min-w-0" data-test="game-ladder-row">
                            <a @if ($href) href="{{ $href }}" @endif class="flex min-h-12 items-center gap-3 border-b border-hairline text-[13px] text-ink hover:text-ink lg:h-full lg:flex-col lg:gap-2 lg:rounded-md lg:border-0 lg:bg-well lg:px-2 lg:py-3 lg:text-center lg:hover:bg-row-hover">
                                <b @class(['w-5 shrink-0 text-right font-display text-sm lg:w-auto lg:text-base', 'text-btc' => $loop->first, 'text-ink-2' => ! $loop->first])>{{ $loop->iteration }}</b>
                                @if ($isPlayer && $row->user)
                                    <x-avatar :user="$row->user" :size="48" class="size-8! rounded-md lg:size-12!" />
                                @else
                                    <x-clan-tag :clan="$clan" :tile="48" class="flex size-8 shrink-0 items-center justify-center rounded-md bg-btc-tint text-[10px] font-bold text-btc lg:size-12 lg:text-[11px]" />
                                @endif
                                <span class="min-w-0 grow truncate lg:w-full lg:grow-0">{{ $name }}</span>
                                <b class="shrink-0 font-display text-[15px] tabular-nums lg:text-lg" data-test="game-ladder-rating">{{ $row->rating }}</b>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>

        {{-- The clans of this game and the clan Hashrate (P26) --}}
        <section aria-labelledby="gc-h" class="flex flex-col gap-4 rounded-lg bg-card px-4 py-4 lg:col-span-5 lg:px-5" data-test="game-clans">
            <span class="flex flex-wrap items-center justify-between gap-x-3">
                <h2 id="gc-h" class="m-0 text-[15px] font-bold">{{ __('Clans') }}@if ($clans->isNotEmpty()) <span class="font-normal text-ink-2">{{ $clans->count() }}</span>@endif</h2>
                <a href="{{ route('clans.index') }}" class="inline-flex min-h-11 items-center text-[13px]">{{ __('All clans and season standings') }}</a>
            </span>

            <div class="flex flex-col gap-2">
                <h3 id="rl-hr" class="m-0 text-[13px] font-bold text-ink-2">{{ __('Clan Hashrate, last 7 days') }}</h3>
                @if ($hash->isEmpty())
                    <p class="m-0 text-[13px] leading-normal text-ink-2" data-test="rl-hashrate-empty">{{ $hashEmpty }}</p>
                @else
                    <div role="img" aria-label="{{ $hash->map(fn ($row) => $row['clan']->name.' '.$row['points'])->implode(', ') }}" class="flex h-3 overflow-hidden rounded-xs bg-raised" data-test="rl-hashrate-bar">
                        @foreach ($hash as $index => $row)
                            <span class="block h-3 {{ $segment[$index % count($segment)] }} {{ $index > 0 ? 'border-l-2 border-card' : '' }}" style="width: {{ round($row['share'] * 100, 2) }}%"></span>
                        @endforeach
                    </div>
                    <ul role="list" class="m-0 flex list-none flex-wrap gap-x-4 gap-y-1 p-0 text-xs">
                        @foreach ($hash->take(3) as $index => $row)
                            <li class="flex min-w-0 items-center gap-1.5"><span class="size-2 shrink-0 rounded-[2px] {{ $segment[$index % count($segment)] }}" aria-hidden="true"></span><span class="truncate">{{ $row['clan']->name }}</span><b>{{ $row['points'] }}</b></li>
                        @endforeach
                        @if ($hash->count() > 3)<li class="text-ink-2">{{ __('+:count more', ['count' => $hash->count() - 3]) }}</li>@endif
                    </ul>
                    <span class="text-xs text-ink-3">{{ __(':rl of :total points from series', ['rl' => $rlTotal, 'total' => $weekTotal]) }}</span>
                @endif
            </div>

            @if ($clans->isEmpty())
                <p class="m-0 flex flex-wrap items-center gap-x-3 border-t border-hairline pt-3 text-[13px] leading-normal text-ink-2" data-test="game-clans-empty">
                    <span>{{ __('No clan has a lineup for :game yet.', ['game' => $gameName]) }}</span>
                    <a href="{{ route('clans.create') }}" class="inline-flex min-h-11 items-center">{{ __('Start a clan') }}</a>
                </p>
            @else
                <ul role="list" class="m-0 grid list-none grid-cols-3 gap-2 border-t border-hairline p-0 pt-3 sm:grid-cols-4">
                    @foreach ($clans->take(8) as ['clan' => $clan, 'wins' => $clanWins])
                        <li wire:key="gc-{{ $clan->id }}" class="min-w-0">
                            <a href="{{ route('clans.show', $clan) }}" class="flex h-full flex-col items-center gap-1.5 rounded-md bg-well px-1.5 py-2.5 text-center text-ink hover:bg-row-hover hover:text-ink" data-test="game-clan">
                                <x-clan-tag :clan="$clan" :tile="40" class="flex size-10 items-center justify-center rounded-md bg-btc-tint text-[10px] font-bold text-btc" />
                                <span class="w-full truncate text-xs font-bold">{{ $clan->name }}</span>
                                <span class="text-[11px] text-ink-2">{{ trans_choice(':count win|:count wins', $clanWins) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($clans->count() > 8)
                    <a href="{{ route('clans.index') }}" class="inline-flex min-h-11 items-center self-start text-[13px]">{{ __('+:count more', ['count' => $clans->count() - 8]) }}</a>
                @endif
            @endif
        </section>
    </div>

    {{-- What a series is worth: the Elo formula (K 32) in one strip --}}
    <section aria-labelledby="gw-h" class="mx-4 mb-6 flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:mx-12 lg:mb-10 lg:flex-row lg:items-center lg:gap-6 lg:px-5" data-test="game-worth">
        <span class="flex flex-col gap-0.5 lg:w-56 lg:shrink-0">
            <h2 id="gw-h" class="m-0 text-[15px] font-bold">{{ __('What a series is worth') }}</h2>
            <span class="text-xs text-ink-2">{{ __('Elo per lineup, K 32, first 5 series K 40') }}</span>
        </span>
        <ul role="list" class="m-0 grid list-none grid-cols-3 gap-2 p-0 lg:grow">
            @foreach ([[__('200 Elo below you'), -200], [__('same Elo as you'), 0], [__('200 Elo above you'), 200]] as [$label, $gap])
                @php($stake = $this->stake($gap))
                <li class="flex min-w-0 flex-col items-center gap-0.5 rounded-md bg-well px-2 py-2 text-center lg:flex-row lg:justify-center lg:gap-3">
                    <span class="text-[11px] leading-snug text-ink-2 lg:text-xs">{{ $label }}</span>
                    <span class="flex items-baseline gap-2"><b class="font-display text-lg text-win">+{{ $stake['win'] }}</b><span class="text-xs text-loss">−{{ $stake['loss'] }}</span></span>
                </li>
            @endforeach
        </ul>
        <a href="{{ route('rules') }}" class="inline-flex min-h-11 shrink-0 items-center text-[13px]">{{ __('How points are counted') }}</a>
    </section>
</div>
