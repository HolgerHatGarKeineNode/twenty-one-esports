{{--
    A Blockfill week's own page (plan "Restposten nach TMNF", P4), below its hero: the board as TMNF's week board shows
    it, the first three on the podium (with their replays) and the rows from fourth place on, the full table one click
    away; beside it the week's facts as chips and the way onward (last week, the points ladder, the calendar, every run).
    $tournament: the week; $standings: its list<App\Support\Scores\ScoreStanding>; $metric: App\Games\ScoreMetric.
--}}
@php
    $window = \App\Support\Scores\ScoreWindow::of($tournament);
    $viewer = auth()->user() instanceof \App\Models\User ? auth()->user() : null;
    $placed = array_values(array_filter($standings, fn ($row): bool => $row->place !== null && $row->value !== null));
    // The viewer's own run that is a moment: its share button sits on the podium or in the viewer's row.
    $shareMoment = app(\App\Support\Stacker\BlockfillMoments::class)->shareableOn($viewer, $tournament);
    $podiumReplays = app(\App\Support\Stacker\StackerReplays::class)->forStandings(array_slice($standings, 0, 3), $viewer);
    $windowState = match (true) {
        ! $window->hasStarted() => __('opens :at', ['at' => \App\Support\LeagueTime::stamp($window->start)]),
        ! $window->hasEnded() => __('open until :at', ['at' => \App\Support\LeagueTime::stamp($window->end)]),
        default => __('closed :at', ['at' => \App\Support\LeagueTime::stamp($window->end)]),
    };
    $facts = [
        ['award', __('Fastest time wins')],
        ['clock', __('A tie goes to the earlier run')],
        ['ladder', __('Places score points on the ladder')],
        ['mining', __('Mines no season blocks')],
    ];
@endphp
<div class="grid grid-cols-1 gap-6 px-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,400px)] lg:items-start lg:gap-8 lg:px-12">
    <section id="leaderboard" aria-labelledby="leaderboard-h" class="flex min-w-0 scroll-mt-24 flex-col gap-5 rounded-lg bg-card px-2 py-4 lg:px-6 lg:py-5" data-test="tournament-leaderboard">
        <div class="flex flex-wrap items-center justify-between gap-3 px-2 lg:px-0">
            <span class="flex min-w-0 flex-col gap-1">
                <h2 id="leaderboard-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ $tournament->status === \App\Enums\TournamentStatus::Finished ? __('Final standings') : __('Leaderboard') }}</h2>
                <span class="text-xs text-ink-2" data-test="score-window">{{ __('Window :state', ['state' => $windowState]) }} · {{ \App\Support\Stacker\BlockfillRules::weekBlocks($tournament) }}</span>
            </span>
            @if (\Illuminate\Support\Facades\Route::has('tournaments.scores'))
                <x-button variant="secondary" :href="route('tournaments.scores', $tournament)" data-test="to-scores">{{ __('Full table') }}</x-button>
            @endif
        </div>

        @if ($placed === [])
            @include('pages.scores.partials.blockfill-empty', ['finished' => $tournament->status === \App\Enums\TournamentStatus::Finished])
        @else
            <div class="px-2 lg:px-0">
                @include('pages.scores.partials.podium', ['standings' => $standings, 'metric' => $metric, 'test' => 'week-podium',
                    'replays' => $podiumReplays, 'viewerId' => $viewer?->id, 'shareMoment' => $shareMoment])
            </div>
            @if (count($standings) > 3)
                @include('pages.scores.partials.leaderboard', ['standings' => $standings, 'metric' => $metric, 'skip' => 3, 'limit' => 7, 'viewerId' => $viewer?->id, 'shareMoment' => $shareMoment])
            @endif
        @endif
    </section>

    <aside class="flex min-w-0 flex-col gap-4" aria-label="{{ __('About this week') }}">
        <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-test="week-facts">
            @foreach ($facts as [$icon, $fact])
                <li class="inline-flex min-h-8 items-center gap-2 rounded-md bg-raised px-3 py-1.5 text-xs text-ink">
                    <x-icon :name="$icon" :size="14" class="shrink-0 text-btc-hi" />{{ $fact }}
                </li>
            @endforeach
        </ul>
        @include('pages.scores.partials.blockfill-nav', ['week' => $tournament])
    </aside>
</div>
@if ($shareMoment !== null)
    <livewire:blockfill-share key="blockfill-share" />
@endif
