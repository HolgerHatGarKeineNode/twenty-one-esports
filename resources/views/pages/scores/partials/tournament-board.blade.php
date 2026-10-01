{{--
    The leaderboard on a score tournament's page (plan "AoE2 und Trackmania", P4), in the bracket's place: the window,
    the course, the top of the table and the way to the full table and the submission form.
    $tournament, $metric (App\Games\ScoreMetric), $drawn (the entries are fixed).
--}}
@php
    $window = \App\Support\Scores\ScoreWindow::of($tournament);
    $standings = $drawn ? app(\App\Support\Scores\ScoreRuns::class)->standings($tournament) : [];
    $viewerId = auth()->id();
    $entered = $viewerId !== null && collect($standings)->contains(fn ($row): bool => $row->participant->user_id === $viewerId);
    $windowState = match (true) {
        ! $window->hasStarted() => __('opens :at', ['at' => \App\Support\LeagueTime::stamp($window->start)]),
        ! $window->hasEnded() => __('open until :at', ['at' => \App\Support\LeagueTime::stamp($window->end)]),
        default => __('closed :at', ['at' => \App\Support\LeagueTime::stamp($window->end)]),
    };
@endphp
<section id="leaderboard" aria-labelledby="leaderboard-h" class="flex scroll-mt-24 flex-col gap-4 px-4 lg:px-12" data-test="tournament-leaderboard">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <span class="flex min-w-0 flex-col gap-1">
            <h2 id="leaderboard-h" class="m-0 font-display text-xl font-bold lg:text-2xl">{{ __('Leaderboard') }}</h2>
            {{-- A course that is the mode itself (Blockfill's "40-blocks") is named by the mode, as on the leaderboards page, never by its slug --}}
            <span class="text-xs text-ink-2" data-test="score-window">{{ __('Window :state', ['state' => $windowState]) }}@if ($tournament->score_course === $tournament->mode) · {{ \App\Support\GameNames::mode($tournament->game, $tournament->mode) }}@elseif ($tournament->score_course !== null) · <span class="font-mono">{{ $tournament->score_course }}</span>@endif</span>
        </span>
        @if (\Illuminate\Support\Facades\Route::has('tournaments.scores') && $tournament->status !== \App\Enums\TournamentStatus::Draft)
            @php($manual = app(\App\Games\GameRegistry::class)->find($tournament->game)?->acceptsManual() ?? true)
            <span class="flex flex-wrap items-center gap-2">
                {{-- A game the league checks itself (Blockfill) takes no submission: Play instead, and its runs are the full table --}}
                @if ($tournament->status === \App\Enums\TournamentStatus::Running)
                    @if ($manual)
                        @if ($entered)
                            <x-button :href="route('tournaments.scores', $tournament).'#submit'" icon="send" data-test="to-submit">{{ __('Submit your value') }}</x-button>
                        @endif
                    @else
                        @include('pages.scores.partials.play-auto', ['slug' => $tournament->game])
                    @endif
                @endif
                <x-button variant="secondary" :href="route('tournaments.scores', $tournament)" data-test="to-scores">{{ $manual ? __('All values') : __('Full table') }}</x-button>
            </span>
        @endif
    </div>

    <div class="rounded-lg bg-card px-2 py-3 lg:px-6 lg:py-4">
        @if ($drawn)
            @include('pages.scores.partials.leaderboard', ['standings' => $standings, 'metric' => $metric, 'limit' => 10, 'viewerId' => $viewerId])
        @else
            <p class="m-0 px-2 py-4 text-[13px] text-ink-2">{{ __('The leaderboard fills once sign-up has closed and the window is open.') }}</p>
        @endif
    </div>
</section>
