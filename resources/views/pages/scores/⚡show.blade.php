<?php

use App\Enums\TournamentFormat;
use App\Enums\TournamentStatus;
use App\Games\GameRegistry;
use App\Games\ScoreGame;
use App\Models\Tournament;
use App\Models\User;
use App\Support\LeagueTime;
use App\Support\Scores\ScorePoints;
use App\Support\Scores\ScoreWindow;
use App\Support\Stacker\BlockfillWeeks;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/*
 * A score game's page (plan "AoE2 und Trackmania", P4): its leaderboards
 * (open for sign-up, running, the last finished ones) and its points ladder
 * per mode, the points of every finished leaderboard summed per player
 * (ScorePoints). League names only. Routed only while a score game is registered.
 */
new #[Layout('layouts::app', ['section' => 'tournaments', 'scripts' => ['resources/js/gameChannel.js']])] class extends Component {
    public string $game;

    public function mount(string $game): void
    {
        abort_unless(app(GameRegistry::class)->isScore($game), 404);

        $this->game = $game;
    }

    public function rendering(\Illuminate\View\View $view): void
    {
        $name = \App\Support\GameNames::game($this->game);
        // ":game leaderboards", not the game's name alone: Blockfill's own page (/blockfill) has that title.
        $title = __(':game leaderboards', ['game' => $name]);
        $view->title($title);
        app(\App\Support\PageMeta::class)->describe($title, __(':game on TWENTY ONE Esports: leaderboards where everyone plays alone for the best value, and a points ladder.', ['game' => $name]))
            ->card(fn () => \App\Support\Cards\PageCard::page('scores.'.$this->game));
    }

    #[Computed]
    public function score(): ScoreGame
    {
        $game = app(GameRegistry::class)->get($this->game);
        abort_unless($game instanceof ScoreGame, 404);

        return $game;
    }

    /**
     * Published leaderboards of the game: sign-up, running, and the last finished.
     *
     * @return Collection<int, Tournament>
     */
    #[Computed]
    public function leaderboards(): Collection
    {
        $base = fn () => Tournament::query()->where(['game' => $this->game, 'format' => TournamentFormat::Leaderboard])->whereNotNull('published_at');

        return $base()->whereIn('status', [TournamentStatus::Signup, TournamentStatus::Drawing, TournamentStatus::Running])->orderBy('starts_at')->limit(12)->get()
            ->concat($base()->where('status', TournamentStatus::Finished)->orderByDesc('starts_at')->limit(6)->get());
    }

    /**
     * The standings of the running leaderboards (at most three, the earliest
     * first), so the table shows here and not only one click away.
     *
     * @return list<array{tournament: Tournament, standings: list<\App\Support\Scores\ScoreStanding>}>
     */
    #[Computed]
    public function running(): array
    {
        return $this->leaderboards->where('status', TournamentStatus::Running)->take(3)
            ->map(fn (Tournament $tournament): array => ['tournament' => $tournament, 'standings' => app(\App\Support\Scores\ScoreRuns::class)->standings($tournament)])
            ->values()->all();
    }

    /**
     * Blockfill's running week (null: another game, or the week is not opened yet), shown first under the Play button.
     */
    #[Computed]
    public function week(): ?Tournament
    {
        return $this->game === \App\Games\Blockfill::SLUG && \Illuminate\Support\Facades\Route::has('stacker.play')
            ? app(BlockfillWeeks::class)->current()
            : null;
    }

    /**
     * TMNF's running week (plan "Trackmania und Restposten", P2; null: another game, or not opened yet), shown first
     * with How to join.
     */
    #[Computed]
    public function tmnfWeek(): ?Tournament
    {
        return $this->game === \App\Games\TrackmaniaNationsForever::SLUG ? app(\App\Support\Tmnf\TmnfWeeks::class)->current() : null;
    }

    /**
     * The points ladder of each mode, the top 20.
     *
     * @return array<string, list<array{user: User, points: int}>>
     */
    #[Computed]
    public function ladders(): array
    {
        $points = app(ScorePoints::class);
        $ladders = [];

        foreach ($this->score->modes() as $mode) {
            $totals = array_slice($points->ladder($this->score, $mode->slug), 0, 20, true);
            $users = User::query()->whereIn('id', array_keys($totals))->get()->keyBy('id');
            $ladders[$mode->slug] = array_values(array_filter(array_map(fn (int $user, int $sum): ?array => $users->has($user) ? ['user' => $users->get($user), 'points' => $sum] : null, array_keys($totals), $totals)));
        }

        return $ladders;
    }
}; ?>

@php
    $score = $this->score;
    $name = \App\Support\GameNames::game($this->game);
    $statusLabel = fn (Tournament $tournament): string => match ($tournament->status) {
        TournamentStatus::Signup => __('Sign-up open'),
        TournamentStatus::Drawing => __('Sign-up closed'),
        TournamentStatus::Running => ScoreWindow::of($tournament)->hasEnded() ? __('Window closed') : __('Window open'),
        default => __('Finished'),
    };
    $week = $this->week;
    $blockfill = $this->game === \App\Games\Blockfill::SLUG && \Illuminate\Support\Facades\Route::has('stacker.play');
    $weekStandings = $week !== null ? (collect($this->running)->first(fn ($entry): bool => $entry['tournament']->is($week))['standings'] ?? app(\App\Support\Scores\ScoreRuns::class)->standings($week)) : [];
    $weekMetric = $week !== null ? app(\App\Support\Scores\ScoreRuns::class)->metricOf($week) : null;
    $weekShare = $week !== null ? app(\App\Support\Stacker\BlockfillMoments::class)->shareableOn(auth()->user(), $week) : null;
    // TMNF (plan "Trackmania und Restposten", P2): the week on our server first, then How to join.
    $tmnf = $this->game === \App\Games\TrackmaniaNationsForever::SLUG;
    if ($tmnf) {
        $week = $this->tmnfWeek;
        $weekStandings = $week !== null ? (collect($this->running)->first(fn ($entry): bool => $entry['tournament']->is($week))['standings'] ?? app(\App\Support\Scores\ScoreRuns::class)->standings($week)) : [];
        $weekMetric = $week !== null ? app(\App\Support\Scores\ScoreRuns::class)->metricOf($week) : null;
        $weekShare = null;
        $tmnfShare = $week !== null ? app(\App\Support\Tmnf\TmnfMoments::class)->shareableOn(auth()->user(), $week) : null;
        // No week runs (not approved yet): no track is guessed for it.
        $tmnfTrack = \App\Support\Tmnf\TmnfWeeks::track($week?->score_course);
    }
@endphp

@php
    $chatRail = \App\Support\GameChat\GameChannels::has($this->game);
@endphp
{{-- The lobby template T1 (x-game.page; GameScore.dc.html for Blockfill): game bar, hero, Spielarten, this week's board, the leaderboards and the points ladder; the chat on the right. --}}
{{-- Blockfill's play field is its own page (stacker.play, the "Spielen" tab); this page is its leaderboard tab. --}}
<x-game.page :game="$this->game" :play-label="$blockfill ? __('Start a ranked run') : null" :active="$blockfill ? 'leaderboard' : 'play'" data-test="score-game" :mode="$blockfill && $week !== null ? __('Week :week', ['week' => (int) $week->starts_at->copy()->timezone(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->format('W')]) : null"
             :lede="__('Everyone plays alone, as often as they like, for the best value on the course inside the window. No lobby, no opponent to wait for. Each leaderboard\'s places score points on the ladder below.')">
    @if ($blockfill)
        <x-slot:primary>
            <a href="{{ route('stacker.play') }}" class="rv-b" data-test="game-primary">{{ __('Start a ranked run') }}</a>
        </x-slot:primary>
        <x-slot:hero>
            @include('pages.scores.partials.game-hero', ['heading' => $name, 'week' => $week, 'standings' => $weekStandings, 'metric' => $weekMetric])
        </x-slot:hero>
    @elseif ($tmnf)
        <x-slot:hero>
            @include('pages.scores.partials.tmnf-hero', ['heading' => $name, 'week' => $week, 'standings' => $weekStandings, 'metric' => $weekMetric, 'track' => $tmnfTrack])
        </x-slot:hero>
    @endif

    @if ($blockfill)
        {{-- Spielarten (GameScore.dc.html): the ranked run of the week and the practice run, both on the play field. --}}
        <x-slot:lead>
        <section class="gp-panel gp-modes" aria-labelledby="play-h" data-test="play">
            <h2 id="play-h" class="gp-h">{{ __('Ways to play') }}</h2>
            <ul role="list" class="m-0 flex list-none flex-col p-0">
                <li>
                    <x-chess.lobby-tile :label="__('Ranked run of the week')" :action="__('Start run')" variant="primary" :href="route('stacker.play')" data-test="play-now">
                        <x-slot:detail>{{ $week !== null ? __('counts for week :week, with a replay', ['week' => (int) $week->starts_at->copy()->timezone(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->format('W')]) : __('Next week starts soon. Ranked runs count again once it is open.') }}</x-slot:detail>
                        <x-slot:meta>@if ($week !== null){{ __('Week :week', ['week' => (int) $week->starts_at->copy()->timezone(\App\Support\Stacker\BlockfillWeeks::TIMEZONE)->format('W')]) }}@endif</x-slot:meta>
                    </x-chess.lobby-tile>
                </li>
                <li>
                    <x-chess.lobby-tile :label="__('Practice run')" :action="__('Practise')" :href="route('stacker.play')" data-test="play-practice">
                        <x-slot:detail>{{ __('never counts, also for guests') }}</x-slot:detail>
                    </x-chess.lobby-tile>
                </li>
            </ul>
        </section>
        </x-slot:lead>

        <section aria-labelledby="week-h" class="gp-panel flex flex-col gap-3 px-2 py-4 lg:px-4" @if ($week) wire:key="running-{{ $week->id }}" @endif data-test="score-running">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-2 lg:px-0">
                <h2 id="week-h" class="gp-h">{{ $week !== null ? BlockfillWeeks::title($week) : __('This week') }}</h2>
                @if ($week !== null)
                    <a href="{{ route('tournaments.scores', $week) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="score-full-table">{{ __('Full table') }}</a>
                @endif
            </div>
            @if ($week === null)
                {{-- No week runs: the admins have not approved the next one yet (LeagueWeekDrafts). --}}
                <p class="m-0 px-2 py-3 text-[13px] text-ink-2 lg:px-0" data-test="next-week-soon">{{ __('Next week starts soon. Ranked runs count again once it is open.') }}</p>
            @elseif ($weekStandings === [])
                @include('pages.scores.partials.blockfill-empty', ['finished' => false])
            @else
                @include('pages.scores.partials.leaderboard', ['standings' => $weekStandings, 'metric' => $weekMetric, 'limit' => 10, 'viewerId' => auth()->id(), 'staff' => false, 'beat' => route('stacker.play'), 'shareMoment' => $weekShare])
            @endif
        </section>

        @include('pages.scores.partials.blockfill-nav', ['week' => null])
    @elseif ($tmnf)

        <section aria-labelledby="week-h" class="flex flex-col gap-3 rounded-[4px] bg-card px-2 py-4 lg:px-5" @if ($week) wire:key="running-{{ $week->id }}" @endif data-test="score-running">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-2 lg:px-0">
                <h2 id="week-h" class="m-0 text-[15px] font-bold">{{ $week !== null ? $week->title() : __('This week') }}</h2>
                @if ($week !== null)
                    <a href="{{ route('tournaments.show', $week) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="score-full-table">{{ __('Week page') }}</a>
                @endif
            </div>
            @if ($week === null)
                {{-- No week runs: the admins have not approved the next one yet (LeagueWeekDrafts). --}}
                <p class="m-0 px-2 py-3 text-[13px] text-ink-2 lg:px-0" data-test="next-week-soon">{{ __('Next week starts soon. Your times count again once it is open.') }}</p>
            @elseif ($weekStandings === [])
                <p class="m-0 px-2 py-3 text-[13px] text-ink-2 lg:px-0" data-test="tmnf-board-empty">{{ __('Nobody has a time this week yet. Join our server and be the first on the board.') }}</p>
            @else
                @include('pages.scores.partials.leaderboard', ['standings' => $weekStandings, 'metric' => $weekMetric, 'limit' => 10, 'viewerId' => auth()->id(), 'staff' => false, 'shareMoment' => null])
            @endif
            @include('pages.scores.partials.tmnf-share', ['moment' => $tmnfShare])
        </section>

        @include('pages.scores.partials.tmnf-join', ['week' => $week, 'track' => $tmnfTrack])
    @else
        {{-- Any other score game: the template's hero carries its name and line; its modes and how to start. --}}
        @include('pages.scores.partials.play-auto', ['slug' => $this->game, 'class' => 'pt-1'])
        <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
            @foreach ($score->modes() as $mode)
                <li class="rv-tag" wire:key="mode-{{ $mode->slug }}">
                    <b class="text-ink">{{ __($mode->name) }}</b>{{ $score->metric($mode)->lowerIsBetter() ? __('the fastest time wins') : __('the highest score wins') }}
                </li>
            @endforeach
        </ul>
    @endif

    <section aria-labelledby="boards-h" class="flex flex-col gap-3">
        <h2 id="boards-h" class="gp-h">{{ __('Leaderboards') }}</h2>
        @if ($this->leaderboards->isEmpty())
            <x-empty-state :heading="__('No leaderboard yet')" :text="__('The next one shows up here as soon as it is published.')">
                <x-button :href="route('tournaments.index')" variant="secondary">{{ __('All tournaments') }}</x-button>
            </x-empty-state>
        @else
            {{-- auto-fit: one or two leaderboards fill the row instead of standing in a third of it (P4, R1). --}}
            <ul class="m-0 grid list-none grid-cols-[repeat(auto-fit,minmax(240px,1fr))] gap-3 p-0">
                @foreach ($this->leaderboards as $tournament)
                    @php($window = ScoreWindow::of($tournament))
                    <li wire:key="board-{{ $tournament->id }}" data-test="score-board-card">
                        {{-- A Blockfill week opens on its board; any other leaderboard on its tournament page --}}
                        <a href="{{ $blockfill && ! $tmnf ? route('tournaments.scores', $tournament) : route('tournaments.show', $tournament) }}" class="flex h-full flex-col gap-2 rounded-[4px] bg-card p-4 text-ink shadow-ring hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-btc)]">
                            {{-- The title wraps rather than cutting a translated name short --}}
                            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <b class="min-w-0 text-[15px] [overflow-wrap:anywhere]" data-test="score-board-title">{{ BlockfillWeeks::title($tournament) }}</b>
                                <span class="shrink-0 text-xs text-ink-2">{{ $statusLabel($tournament) }}</span>
                            </span>
                            {{-- The course only when it is more than the mode itself (Blockfill's course is its mode) --}}
                            {{-- A TMNF course is a track UID: its name shows instead --}}
                            <span class="text-xs text-ink-2" data-test="score-board-mode">{{ $tournament->game === \App\Games\Blockfill::SLUG ? \App\Support\Stacker\BlockfillRules::weekBlocks($tournament) : __($score->mode($tournament->mode)?->name ?? $tournament->mode) }}@if ($tmnf && ($trackOf = \App\Support\Tmnf\TmnfWeeks::track($tournament->score_course)) !== null) · {{ $trackOf['name'] }}@elseif ($tournament->score_course && $tournament->score_course !== $tournament->mode) · <span class="font-mono">{{ $tournament->score_course }}</span>@endif</span>
                            <span class="text-xs text-ink-3 tabular-nums">{{ LeagueTime::stamp($window->start) }} – {{ LeagueTime::stamp($window->end) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @php($shareable = $weekShare !== null)
    @foreach ($this->running as ['tournament' => $tournament, 'standings' => $standings])
        @continue($week !== null && $tournament->is($week))
        @php($shareMoment = app(\App\Support\Stacker\BlockfillMoments::class)->shareableOn(auth()->user(), $tournament))
        @php($shareable = $shareable || $shareMoment !== null)
        <section aria-labelledby="running-h-{{ $tournament->id }}" class="flex flex-col gap-3 rounded-[4px] bg-card px-2 py-4 lg:px-5" wire:key="running-{{ $tournament->id }}" data-test="score-running">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-2 lg:px-0">
                <h2 id="running-h-{{ $tournament->id }}" class="m-0 text-[15px] font-bold">{{ BlockfillWeeks::title($tournament) }}</h2>
                <a href="{{ route('tournaments.scores', $tournament) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink">{{ __('Full table') }}</a>
            </div>
            @include('pages.scores.partials.leaderboard', ['standings' => $standings, 'metric' => app(\App\Support\Scores\ScoreRuns::class)->metricOf($tournament), 'limit' => 10, 'viewerId' => auth()->id(), 'staff' => false, 'shareMoment' => $shareMoment])
        </section>
    @endforeach
    {{-- A Blockfill moment of the viewer's on a running week: its row opens the share sheet --}}
    @if ($shareable)
        <livewire:blockfill-share key="blockfill-share" />
    @endif

    <section id="points" aria-labelledby="points-h" class="flex scroll-mt-24 flex-col gap-3">
        <div class="flex flex-col gap-2">
            <h2 id="points-h" class="gp-h">{{ __('Points ladder') }}</h2>
            {{-- The points per place of every finished leaderboard, as chips: place, then its points --}}
            <ol class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="{{ __('Points per place') }}" data-test="score-points-table">
                @foreach (ScorePoints::table($score) as $index => $points)
                    <li class="inline-flex h-7 items-center gap-1.5 rounded-xs bg-card px-2 text-xs tabular-nums"><span class="text-ink-3">#{{ $index + 1 }}</span><b>{{ $points }}</b></li>
                @endforeach
                <li class="inline-flex h-7 items-center px-1 text-xs text-ink-3">{{ __('then 0') }}</li>
            </ol>
        </div>
        {{-- One mode (Blockfill's "Blöcke der Woche", GameScore.dc.html): its card spans the main column and its places run
             in two columns, 1 to 10 on the left; with more modes one card per column. Either way no half of the column
             stays empty (plan "Canvas and live alignment", P4, comparison GameScore, R1). --}}
        @php($single = count($score->modes()) === 1)
        <div @class(['grid grid-cols-1 gap-3', 'lg:grid-cols-2' => ! $single])>
            @foreach ($score->modes() as $mode)
                @php($entries = $this->ladders[$mode->slug])
                @php($split = $single && count($entries) > 1)
                <div class="flex flex-col gap-2 rounded-[4px] bg-card px-2 py-4 lg:px-5" wire:key="ladder-{{ $mode->slug }}" data-test="score-points-{{ $mode->slug }}">
                    <h3 class="m-0 px-2 text-[15px] font-bold lg:px-0">{{ __($mode->name) }}</h3>
                    <div @class(['grid grid-cols-1', 'lg:grid-flow-col lg:grid-cols-2 lg:grid-rows-[repeat(var(--rows),auto)] lg:gap-x-8' => $split]) @if ($split) style="--rows: {{ (int) ceil(count($entries) / 2) }}" @endif>
                    @forelse ($entries as $index => $entry)
                        <div class="grid min-h-11 grid-cols-[32px_minmax(0,1fr)_64px] items-center gap-3 border-b border-hairline px-2 text-[13px] last:border-b-0" data-test="score-points-row">
                            <span class="font-display font-bold text-ink-2 tabular-nums">{{ $index + 1 }}</span>
                            <span class="flex min-w-0 items-center gap-2">
                                <x-avatar :user="$entry['user']" :size="22" class="shrink-0 rounded-sm" />
                                <a href="{{ route('players.show', $entry['user']->npub) }}" class="truncate text-ink hover:text-btc-hi">{{ $entry['user']->displayName() }}</a>
                            </span>
                            <b class="text-right tabular-nums">{{ $entry['points'] }}</b>
                        </div>
                    @empty
                        <p class="m-0 px-2 py-3 text-[13px] text-ink-2 lg:px-0">{{ __('No finished leaderboard yet.') }}</p>
                    @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- The foot of every game page (GameScore.dc.html "Nächstes Turnier" | the secondary line, R7): the running or next
         leaderboard of this game, and the weekly dates behind the closed secondary line (P4, comparison GameScore). --}}
    @php($nextBoard = $this->leaderboards->first(fn (Tournament $tournament): bool => $tournament->status !== TournamentStatus::Finished))
    <x-slot:next>
        @if ($nextBoard !== null)
            {{-- One row (GameScore.dc.html): the leaderboard's cover, its name and state; a running week has no start to count down to. --}}
            <section aria-labelledby="score-next-h" class="flex flex-col gap-3" data-test="score-next">
                <h2 id="score-next-h" class="gp-h">{{ __('Next tournament') }}</h2>
                <a href="{{ $blockfill ? route('tournaments.scores', $nextBoard) : route('tournaments.show', $nextBoard) }}" class="flex min-h-11 items-center gap-3 rounded-[4px] bg-card p-3 text-ink shadow-ring hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-btc)]">
                    <x-game-cover :game="$this->game" size="thumb" class="w-14 shrink-0 rounded-xs" />
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <b class="text-[13px] [overflow-wrap:anywhere]">{{ BlockfillWeeks::title($nextBoard) }}</b>
                        <span class="text-xs text-ink-2">{{ $statusLabel($nextBoard) }}</span>
                    </span>
                    @if ($nextBoard->status === TournamentStatus::Running)
                        <span class="rv-tag shrink-0 text-win">{{ __('Running') }}</span>
                    @endif
                </a>
            </section>
        @else
            <x-tournaments.next-empty :game="$this->game" heading-id="score-next-h" />
        @endif
    </x-slot:next>
    <x-slot:secondary>
        <x-game.secondary :label="__('Weekly events')">
            <x-weekly-events :events="app(App\Support\Engagement\WeeklySlots::class)->upcoming(4)" heading-id="score-weekly-h" />
        </x-game.secondary>
    </x-slot:secondary>
    @if ($chatRail)
        <x-slot:chat>
            {{-- The game's chat (every game has one, 2026-10-03): the right column, sticky (rule R6). --}}
            <livewire:game-channel :game="$this->game" :wire:key="'game-channel-'.$this->game" />
        </x-slot:chat>
    @endif
</x-game.page>
