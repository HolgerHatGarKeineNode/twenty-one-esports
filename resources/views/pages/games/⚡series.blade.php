<?php

use App\Games\GameRegistry;
use App\Enums\SeriesStatus;
use App\Enums\TournamentStatus;
use App\Models\Clan;
use App\Models\SeriesMatch;
use App\Models\Tournament;
use App\Support\GameNames;
use App\Support\Series\SeriesPresenter;
use App\Support\Clans\ClanStats;
use App\Support\PageMeta;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * The page of a series game (Rocket League, EA Sports FC), 1:1 from
 * RocketLeague.dc.html, one per registry entry (`games/{slug}`). The modes
 * and best-of options come from the game registry and the clan list is real.
 * Series, results, open challenges, series per week and the next tournament
 * are those of this game; the clan Hashrate and the series share count
 * every game (a clan's points are not split by game).
 * The "what a series is worth" numbers are the Elo formula (K 32) itself.
 */
new #[Layout('layouts::app', ['section' => 'matches'])] class extends Component {
    public string $slug = '';

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
        return Tournament::query()->where('game', $this->slug)->where('status', TournamentStatus::Signup)->where('signup_closes_at', '>', now())
            ->orderBy('signup_closes_at')->first();
    }

    /**
     * @return Collection<int, array{clan: Clan, rank: int, points: int, bonus: int, rl: int, width: string, share: string}>
     */
    #[Computed]
    public function hashrate(): Collection
    {
        // The live season's last 7 days (ClanStats); clans without points are left out.
        $stats = app(ClanStats::class);
        $rows = Clan::query()->orderBy('name')->get()->map(function (Clan $clan) use ($stats) {
            $hash = $stats->hashrate($clan);

            return ['clan' => $clan, 'points' => $hash['week'], 'bonus' => $hash['weekBonus'], 'rl' => $stats->seriesHashrate($clan, true)];
        })->filter(fn (array $row): bool => $row['points'] > 0)->sortByDesc('points')->values();

        $top = max(1, (int) $rows->max('points'));
        $total = max(1, (int) $rows->sum('points'));

        return $rows->map(fn (array $row, int $index) => [...$row, 'rank' => $index + 1,
            'width' => number_format($row['points'] / $top * 100, 1).'%',
            'share' => number_format($row['points'] / $total * 100, 1).'%']);
    }

    /**
     * Real series for the P6 cards: wins by clan, the latest results, open
     * challenges and upcoming series, and series per week (last 12 weeks).
     *
     * @return array{wins: list<array{0: string, 1: int}>, recent: list<SeriesMatch>, open: list<SeriesMatch>, weeks: list<int>}
     */
    #[Computed]
    public function series(): array
    {
        $done = SeriesMatch::query()->where('game', $this->slug)->whereIn('status', [SeriesStatus::Confirmed, SeriesStatus::Resolved])->whereIn('winner', SeriesMatch::SIDES);
        $wins = (clone $done)->get()->countBy(fn (SeriesMatch $match) => $match->sideName((string) $match->winner))->sortDesc();
        $top = $wins->take(5)->map(fn (int $count, string $clan) => [$clan, $count])->values()->all();

        if ($wins->count() > 5) {
            $top[] = [__(':n other clans', ['n' => $wins->count() - 5]), (int) $wins->slice(5)->sum()];
        }

        $since = now()->startOfWeek()->subWeeks(11);
        $perWeek = SeriesMatch::query()->where('game', $this->slug)->whereNotNull('start_at')->where('start_at', '>=', $since)->pluck('start_at')
            ->countBy(fn ($start) => (int) floor($since->diffInWeeks($start)));

        return [
            'wins' => $top,
            'recent' => (clone $done)->orderByDesc('finished_at')->limit(6)->get()->all(),
            'open' => SeriesMatch::query()->where('game', $this->slug)->with('challengerLineup.clan')->whereIn('status', [SeriesStatus::Open, SeriesStatus::Accepted])->orderBy('respond_by')->limit(5)->get()->all(),
            'weeks' => array_map(fn (int $week) => (int) ($perWeek[$week] ?? 0), range(0, 11)),
        ];
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
    $rows = $this->hashrate;
    $rl = $rows->sortByDesc('rl')->values();
    $rlTotal = $rl->sum('rl');
    $rlTop = max(1, (int) $rl->max('rl'));
    $hashEmpty = \App\Support\Series\Ladders::season() === null ? __('Hashrate starts at Block 0: rated games earn points for their clan.') : __('No rated game in the last 7 days.');
    $weekTotal = $rows->sum('points');
    $series = $this->series;
    $weeks = $series['weeks'];
    $weekTop = max(1, max($weeks));
    $wins = $series['wins'];
    $winTop = max(1, (int) collect($wins)->max(1));
    $winTotal = max(1, (int) collect($wins)->sum(1));
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
    </div>

    <div class="grid grow grid-cols-1 gap-4 px-4 pb-6 lg:grid-cols-2 lg:gap-5 lg:px-12 lg:pb-10">
        {{-- Clan Hashrate, last 7 days (P7) --}}
        <section aria-labelledby="rl-hr" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 pb-2"><h2 id="rl-hr" class="m-0 text-[15px] font-bold">{{ __('Clan Hashrate · last 7 days') }}</h2><a href="{{ route('clans.index') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('All clans and season standings') }}</a></span>
            <div class="grid h-8 grid-cols-[20px_minmax(0,1fr)_96px] items-center gap-3 border-b border-hairline px-2 text-xs text-ink-2 lg:grid-cols-[24px_180px_minmax(0,1fr)_52px_52px_52px]">
                <span>#</span><span>{{ __('Clan') }}</span><span class="hidden lg:block">{{ __('Hashrate') }}</span><span class="text-right">{{ __('Points') }}</span><span class="hidden text-right lg:block">{{ __('Bonus') }}</span><span class="hidden text-right lg:block">{{ __('Share') }}</span>
            </div>
            @forelse ($rows as $row)
                <a href="{{ route('clans.show', $row['clan']) }}" wire:key="rl-{{ $row['clan']->id }}" class="tr grid h-[38px] grid-cols-[20px_minmax(0,1fr)_96px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[24px_180px_minmax(0,1fr)_52px_52px_52px]">
                    <span class="text-ink-3">{{ $row['rank'] }}</span>
                    <span class="flex min-w-0 items-center gap-2"><x-clan-tag :clan="$row['clan']" size="sm" /><span class="truncate">{{ $row['clan']->name }}</span></span>
                    <span class="hidden h-3.5 rounded-r-sm bg-raised lg:block"><span class="block h-3.5 animate-fill rounded-r-sm bg-btc" style="width: {{ $row['width'] }}"></span></span>
                    <b class="text-right">{{ $row['points'] }}</b>
                    <span class="hidden text-right text-ink-2 lg:block">+{{ $row['bonus'] }}</span>
                    <span class="hidden text-right text-ink-2 lg:block">{{ $row['share'] }}</span>
                </a>
            @empty
                <p class="m-0 py-6 text-center text-[13px] text-ink-2" data-test="rl-hashrate-empty">{{ $hashEmpty }}</p>
            @endforelse
        </section>

        {{-- Series share (P7): every series game, a clan's points are not split by game --}}
        <section aria-labelledby="rl-share" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2 id="rl-share" class="m-0 text-[15px] font-bold">{{ __('Series share') }}</h2><span class="text-xs text-ink-3">{!! __('last 7 days, <b class="text-ink">:rl</b> of :total points', ['rl' => $rlTotal, 'total' => $weekTotal]) !!}</span></span>
            <div role="img" aria-label="{{ __('Series Hashrate per clan, last 7 days') }}" class="flex h-[180px] items-end gap-1.5 border-b border-line lg:gap-2">
                @foreach ($rl as $index => $row)
                    <span class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1" title="{{ $row['clan']->name }}: {{ $row['rl'] }}">
                        <span class="flex min-w-0 items-center gap-1 text-[10px] text-ink-2 lg:text-[11px]"><x-clan-tag :clan="$row['clan']" size="sm" compact /><span class="truncate">{{ $row['rl'] }}</span></span>
                        <span class="bar block bg-btc" style="height: {{ max($row['rl'] / $rlTop * 100, 1) }}%; animation-delay: {{ $index * 0.05 }}s"></span>
                    </span>
                @endforeach
            </div>
            <span class="flex justify-between text-[11px] text-ink-3"><span>{{ __('per clan, most first') }}</span><span>{{ __('the rest came from chess') }}</span></span>
            <a href="{{ route('rules') }}" class="inline-flex min-h-11 items-center self-start text-xs">{{ __('How points are counted') }}</a>
        </section>

        {{-- What a series is worth: the Elo formula itself (K 32) --}}
        <section aria-labelledby="rl-worth" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex flex-wrap items-baseline gap-x-2 gap-y-1"><h2 id="rl-worth" class="m-0 text-[15px] font-bold">{{ __('What a series is worth') }}</h2><span class="text-xs text-ink-3">{{ __('Elo per lineup, K 32, first 5 series K 40') }}</span></span>
            <div class="grid grid-cols-3 gap-2 lg:gap-3">
                @foreach ([[__('Weaker opponent'), __('200 Elo below you'), -200, 'bg-btc-deep'], [__('Even match'), __('same Elo as you'), 0, 'bg-[#F8A23A]'], [__('Stronger opponent'), __('200 Elo above you'), 200, 'bg-btc-hi']] as [$label, $gap, $diff, $colour])
                    @php($stake = $this->stake($diff))
                    <div class="flex flex-col overflow-hidden rounded-md shadow-ring">
                        <span class="{{ $colour }} px-2 py-1.5 text-center text-[11px] font-bold text-on-btc lg:text-xs">{{ $label }}</span>
                        <span class="flex flex-col items-center gap-1 px-2 py-3">
                            <span class="text-center text-[10px] text-ink-2 lg:text-[11px]">{{ $gap }}</span>
                            <b class="font-display text-xl text-win lg:text-[22px]">+{{ $stake['win'] }}</b>
                            <span class="text-xs text-loss">−{{ $stake['loss'] }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Series per week (P6) --}}
        <section aria-labelledby="rl-weeks" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between"><h2 id="rl-weeks" class="m-0 text-[15px] font-bold">{{ __('Series per week') }}</h2><span class="text-xs text-ink-3">{{ __('all series, last 12 weeks') }}</span></span>
            <div class="grid h-[150px] grid-cols-[24px_minmax(0,1fr)] gap-2">
                <div class="flex flex-col justify-between text-right text-[11px] text-ink-3"><span>{{ $weekTop }}</span><span>{{ intdiv($weekTop, 2) }}</span><span>0</span></div>
                <div role="img" aria-label="{{ __('Series per week over the last 12 weeks') }}" class="flex items-end gap-1 border-b border-line lg:gap-2">
                    @foreach ($weeks as $index => $count)
                        <span class="flex h-full min-w-0 flex-1 flex-col justify-end gap-1" title="{{ $count }}">
                            <span class="text-center text-[10px] whitespace-nowrap text-ink-2">{{ $count }}</span>
                            <span class="bar block bg-btc" style="height: {{ max($count / $weekTop * 100, 2) }}%; animation-delay: {{ $index * 0.05 }}s"></span>
                        </span>
                    @endforeach
                </div>
            </div>
            <span class="flex justify-between pl-8 text-[11px] text-ink-3"><span>{{ __('12 weeks ago') }}</span><span>{{ __('6 weeks ago') }}</span><span>{{ __('this week') }}</span></span>
        </section>

        {{-- Wins by clan (P6) --}}
        <section aria-labelledby="rl-wins" class="flex flex-col gap-2 rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between"><h2 id="rl-wins" class="m-0 text-[15px] font-bold">{{ __('Wins by clan') }}</h2><span class="text-xs text-ink-3">{{ __('all modes, since launch') }}</span></span>
            @if ($wins === [])<p class="m-0 text-[13px] text-ink-2">{{ __('No series finished yet.') }}</p>@endif
            @foreach ($wins as [$clan, $count])
                <div class="grid h-7 grid-cols-[120px_minmax(0,1fr)_96px] items-center gap-3 text-[13px] lg:grid-cols-[170px_minmax(0,1fr)_120px]">
                    <span class="truncate">{{ $clan }}</span>
                    <span class="block h-3.5 rounded-r-sm bg-raised"><span class="block h-3.5 animate-fill rounded-r-sm bg-btc" style="width: {{ $count / $winTop * 100 }}%"></span></span>
                    <span class="text-right text-ink-2">{{ trans_choice(':count win|:count wins', $count) }} · {{ round($count / $winTotal * 100) }}%</span>
                </div>
            @endforeach
        </section>

        {{-- Latest series (P6) --}}
        <section aria-labelledby="rl-latest" class="flex flex-col rounded-lg bg-card px-4 py-4 lg:px-6 lg:py-5">
            <h2 id="rl-latest" class="m-0 pb-2 text-[15px] font-bold">{{ __('Latest series') }}</h2>
            <div class="grid h-8 grid-cols-[52px_minmax(0,1fr)_40px_36px] items-center gap-3 px-2 text-xs text-ink-2 lg:grid-cols-[64px_minmax(0,1fr)_100px_56px_56px]"><span>{{ __('Match') }}</span><span>{{ __('Winner') }}</span><span class="hidden lg:block">{{ __('When') }}</span><span>{{ __('Score') }}</span><span>{{ __('Mode') }}</span></div>
            @forelse ($series['recent'] as $match)
                <a href="{{ route('matches.show', $match) }}" wire:key="rs-{{ $match->id }}" class="tr grid h-12 grid-cols-[52px_minmax(0,1fr)_40px_36px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[64px_minmax(0,1fr)_100px_56px_56px]">
                    <span class="text-btc">{{ $match->label() }}</span><span class="truncate">{{ in_array($match->winner, SeriesMatch::SIDES, true) ? $match->sideName($match->winner) : __('no winner') }}</span><span class="hidden text-ink-2 lg:block">{{ $match->finished_at?->diffForHumans() }}</span><span>{{ str_replace(' ', '', SeriesPresenter::score($match)['text']) }}</span><span class="text-ink-2">{{ $match->mode }}</span>
                </a>
            @empty
                <p class="m-0 px-2 py-2 text-[13px] text-ink-2">{{ __('No series finished yet.') }}</p>
            @endforelse
        </section>

        {{-- Open challenges and next series (P6) --}}
        <section aria-labelledby="rl-open" class="flex flex-col rounded-lg lg:col-span-2 bg-card px-4 py-4 lg:px-6 lg:py-5">
            <span class="flex items-baseline justify-between pb-2"><h2 id="rl-open" class="m-0 text-[15px] font-bold">{{ __('Open challenges and next series') }}</h2><a href="{{ route('challenges.create') }}" class="inline-flex min-h-11 items-center text-xs lg:min-h-6">{{ __('Challenge a clan') }}</a></span>
            <div class="grid h-8 grid-cols-[minmax(0,1fr)_80px_64px] items-center gap-3 px-2 text-xs text-ink-2 lg:grid-cols-[minmax(0,1fr)_100px_64px_100px]"><span>{{ __('Clan') }}</span><span>{{ __('Format') }}</span><span>{{ __('Match kind') }}</span><span class="hidden text-right lg:block">{{ __('When') }}</span></div>
            @forelse ($series['open'] as $match)
                <a href="{{ route('matches.show', $match) }}" wire:key="os-{{ $match->id }}" class="tr grid h-12 grid-cols-[minmax(0,1fr)_80px_64px] items-center gap-3 rounded-sm px-2 text-[13px] text-ink hover:text-ink lg:grid-cols-[minmax(0,1fr)_100px_64px_100px]">
                    <span class="flex min-w-0 items-center gap-2"><x-clan-tag :clan="$match->sideClan('challenger')" :tag="$match->challenger_tag" size="sm" /><span class="truncate">{{ $match->status === SeriesStatus::Open ? __('challenges :clan', ['clan' => $match->challenged_name]) : __(':number vs :clan', ['number' => $match->label(), 'clan' => $match->challenged_name]) }}</span></span>
                    <span class="text-ink-2">{{ $match->mode }} · BO{{ $match->best_of }}</span><span class="text-ink-2">{{ $match->rated ? __('rated') : __('casual') }}</span><span class="hidden text-right text-ink-2 lg:block">{{ SeriesPresenter::when($match, auth()->user()) }}</span>
                </a>
            @empty
                <p class="m-0 px-2 py-2 text-[13px] text-ink-2">{{ __('No open challenge right now.') }}</p>
            @endforelse
        </section>
    </div>
</div>
