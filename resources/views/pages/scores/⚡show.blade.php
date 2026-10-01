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
new #[Layout('layouts::app', ['section' => 'tournaments'])] class extends Component {
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
@endphp

<div class="flex flex-col gap-6 px-4 pt-6 pb-12 lg:gap-8 lg:px-12 lg:pt-8" data-test="score-game" style="--game: {{ $score->assets()->colour }}">
    @if ($blockfill)
        {{-- Blockfill: the game first (Play now), then this week's board, then the way around the weeks. --}}
        @include('pages.scores.partials.blockfill-hero', ['heading' => $name, 'week' => $week, 'standings' => $weekStandings, 'metric' => $weekMetric])

        <section aria-labelledby="week-h" class="flex flex-col gap-3 rounded-lg bg-card px-2 py-4 lg:px-5" @if ($week) wire:key="running-{{ $week->id }}" @endif data-test="score-running">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-2 lg:px-0">
                <h2 id="week-h" class="m-0 text-[15px] font-bold">{{ $week !== null ? BlockfillWeeks::title($week) : __('This week') }}</h2>
                @if ($week !== null)
                    <a href="{{ route('tournaments.scores', $week) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink" data-test="score-full-table">{{ __('Full table') }}</a>
                @endif
            </div>
            @if ($weekStandings === [])
                @include('pages.scores.partials.blockfill-empty', ['finished' => false])
            @else
                @include('pages.scores.partials.leaderboard', ['standings' => $weekStandings, 'metric' => $weekMetric, 'limit' => 10, 'viewerId' => auth()->id(), 'staff' => false, 'beat' => route('stacker.play')])
            @endif
        </section>

        @include('pages.scores.partials.blockfill-nav', ['week' => null])
    @else
    <header class="flex flex-col gap-2">
        <h1 class="m-0 font-display text-[28px] leading-[1.15] font-bold lg:text-4xl">{{ $name }}</h1>
        <p class="m-0 max-w-[68ch] text-[13px] leading-normal text-ink-2">{{ __('Everyone plays alone, as often as they like, for the best value on the course inside the window. No lobby, no opponent to wait for. Each leaderboard\'s places score points on the ladder below.') }}</p>
        @include('pages.scores.partials.play-auto', ['slug' => $this->game, 'class' => 'pt-1'])
        <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
            @foreach ($score->modes() as $mode)
                <li class="inline-flex min-h-8 items-center gap-2 rounded-md bg-card px-3 text-xs text-ink-2" wire:key="mode-{{ $mode->slug }}">
                    <b class="text-ink">{{ __($mode->name) }}</b>{{ $score->metric($mode)->lowerIsBetter() ? __('the fastest time wins') : __('the highest score wins') }}
                </li>
            @endforeach
        </ul>
    </header>
    @endif

    <section aria-labelledby="boards-h" class="flex flex-col gap-3">
        <h2 id="boards-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Leaderboards') }}</h2>
        @if ($this->leaderboards->isEmpty())
            <x-empty-state :heading="__('No leaderboard yet')" :text="__('The next one shows up here as soon as it is published.')">
                <x-button :href="route('tournaments.index')" variant="secondary">{{ __('All tournaments') }}</x-button>
            </x-empty-state>
        @else
            <ul class="m-0 grid list-none grid-cols-1 gap-3 p-0 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->leaderboards as $tournament)
                    @php($window = ScoreWindow::of($tournament))
                    <li wire:key="board-{{ $tournament->id }}" data-test="score-board-card">
                        {{-- A Blockfill week opens on its board; any other leaderboard on its tournament page --}}
                        <a href="{{ $blockfill ? route('tournaments.scores', $tournament) : route('tournaments.show', $tournament) }}" class="flex h-full flex-col gap-2 rounded-lg bg-card p-4 text-ink shadow-ring hover:text-ink hover:shadow-[inset_0_0_0_1px_var(--color-btc)]">
                            {{-- The title wraps rather than cutting a translated name short --}}
                            <span class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                                <b class="min-w-0 text-[15px] [overflow-wrap:anywhere]" data-test="score-board-title">{{ BlockfillWeeks::title($tournament) }}</b>
                                <span class="shrink-0 text-xs text-ink-2">{{ $statusLabel($tournament) }}</span>
                            </span>
                            {{-- The course only when it is more than the mode itself (Blockfill's course is its mode) --}}
                            <span class="text-xs text-ink-2" data-test="score-board-mode">{{ __($score->mode($tournament->mode)?->name ?? $tournament->mode) }}@if ($tournament->score_course && $tournament->score_course !== $tournament->mode) · <span class="font-mono">{{ $tournament->score_course }}</span>@endif</span>
                            <span class="text-xs text-ink-3 tabular-nums">{{ LeagueTime::stamp($window->start) }} – {{ LeagueTime::stamp($window->end) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @foreach ($this->running as ['tournament' => $tournament, 'standings' => $standings])
        @continue($week !== null && $tournament->is($week))
        <section aria-labelledby="running-h-{{ $tournament->id }}" class="flex flex-col gap-3 rounded-lg bg-card px-2 py-4 lg:px-5" wire:key="running-{{ $tournament->id }}" data-test="score-running">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 px-2 lg:px-0">
                <h2 id="running-h-{{ $tournament->id }}" class="m-0 text-[15px] font-bold">{{ BlockfillWeeks::title($tournament) }}</h2>
                <a href="{{ route('tournaments.scores', $tournament) }}" class="inline-flex min-h-11 items-center text-[13px] font-bold text-ink underline decoration-edge underline-offset-4 hover:decoration-ink">{{ __('Full table') }}</a>
            </div>
            @include('pages.scores.partials.leaderboard', ['standings' => $standings, 'metric' => app(\App\Support\Scores\ScoreRuns::class)->metricOf($tournament), 'limit' => 10, 'viewerId' => auth()->id(), 'staff' => false])
        </section>
    @endforeach

    <section id="points" aria-labelledby="points-h" class="flex scroll-mt-24 flex-col gap-3">
        <div class="flex flex-col gap-2">
            <h2 id="points-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Points ladder') }}</h2>
            {{-- The points per place of every finished leaderboard, as chips: place, then its points --}}
            <ol class="m-0 flex list-none flex-wrap gap-1.5 p-0" aria-label="{{ __('Points per place') }}" data-test="score-points-table">
                @foreach (ScorePoints::table($score) as $index => $points)
                    <li class="inline-flex h-7 items-center gap-1.5 rounded-xs bg-card px-2 text-xs tabular-nums"><span class="text-ink-3">#{{ $index + 1 }}</span><b>{{ $points }}</b></li>
                @endforeach
                <li class="inline-flex h-7 items-center px-1 text-xs text-ink-3">{{ __('then 0') }}</li>
            </ol>
        </div>
        <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
            @foreach ($score->modes() as $mode)
                <div class="flex flex-col gap-2 rounded-lg bg-card px-2 py-4 lg:px-5" wire:key="ladder-{{ $mode->slug }}" data-test="score-points-{{ $mode->slug }}">
                    <h3 class="m-0 px-2 text-[15px] font-bold lg:px-0">{{ __($mode->name) }}</h3>
                    @forelse ($this->ladders[$mode->slug] as $index => $entry)
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
            @endforeach
        </div>
    </section>
</div>
