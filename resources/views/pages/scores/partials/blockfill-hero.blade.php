{{--
    The way into Blockfill on its score pages: the cover, the heading, the week as chips (the countdown to its close or
    "Final standings", the players), the time to beat (a finished week: its winner) and the one primary action, Play now,
    with How it works next to it.
    $heading: the h1; $week: the week shown (null: none opened yet); $standings: its list<ScoreStanding>;
    $metric: its App\Games\ScoreMetric (null without a week).
--}}
@php
    $window = $week !== null ? \App\Support\Scores\ScoreWindow::of($week) : null;
    $finished = $week?->status === \App\Enums\TournamentStatus::Finished;
    $open = $week !== null && $week->status === \App\Enums\TournamentStatus::Running && $window->hasStarted() && ! $window->hasEnded();
    $leader = collect($standings)->first(fn ($row): bool => $row->place !== null && $row->value !== null);
    $leaderUser = $leader?->participant->user;
    // The first frame of the countdown comes from the server, so nothing jumps (countdown() in resources/js/tournamentLanding.js).
    $seconds = $open ? max(0, (int) now()->diffInSeconds($window->end, false)) : 0;
    $days = intdiv($seconds, 86400);
    $countdown = ($days > 0 ? trans_choice(':count day|:count days', $days).' ' : '').sprintf('%02d:%02d:%02d', intdiv($seconds % 86400, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    $chip = 'inline-flex h-8 items-center gap-2 rounded-md bg-raised px-3 text-xs text-ink';
@endphp
<section aria-labelledby="blockfill-hero-h" class="grid grid-cols-1 overflow-hidden rounded-lg bg-card lg:grid-cols-[minmax(0,1fr)_minmax(0,560px)] lg:items-stretch" data-test="blockfill-hero">
    {{-- The art's own black behind it, so a taller text column never shows a seam above or below the picture --}}
    <div class="flex h-full items-center bg-black lg:order-2">
        <x-game-cover game="blockfill" size="header" loading="eager" class="w-full" />
    </div>

    <div class="flex min-w-0 flex-col gap-4 px-4 pt-4 pb-5 lg:order-1 lg:justify-center lg:gap-5 lg:px-8 lg:py-8">
        <h1 id="blockfill-hero-h" class="m-0 font-display text-[26px] leading-[1.15] font-bold [overflow-wrap:anywhere] lg:text-4xl">{{ $heading }}</h1>

        @if ($week !== null)
            <ul class="m-0 flex list-none flex-wrap gap-2 p-0" data-test="hero-chips">
                @if ($open)
                    <li class="{{ $chip }}" data-test="chip-closes">
                        <x-icon name="clock" :size="14" class="shrink-0 text-btc-hi" />
                        <span>{{ __('Closes in') }} <b class="font-bold tabular-nums" role="timer" x-data="countdown({ at: {{ (int) $window->end->getTimestampMs() }}, days: @js(__(':count day|:count days')) })" x-text="text">{{ $countdown }}</b></span>
                    </li>
                @elseif ($finished)
                    <li class="{{ $chip }}" data-test="chip-final"><x-icon name="check" :size="14" class="shrink-0 text-win" />{{ __('Finished') }}</li>
                @else
                    <li class="{{ $chip }}" data-test="chip-closed"><x-icon name="lock" :size="14" class="shrink-0 text-ink-2" />{{ __('Window closed') }}</li>
                @endif
                @if ($standings !== [])
                    <li class="{{ $chip }}" data-test="chip-players"><x-icon name="user" :size="14" class="shrink-0 text-btc-hi" />{{ trans_choice(':count player|:count players', count($standings)) }}</li>
                @endif
            </ul>
        @endif

        @if ($leader === null && $open)
            {{-- Nobody on the board yet: the first place is the prize in view --}}
            <span class="flex flex-col gap-1" data-test="first-place-free">
                <span class="text-xs text-ink-2">{{ __('Time to beat') }}</span>
                <b class="font-display text-2xl leading-tight font-bold lg:text-4xl">{{ __('#1 is free') }}</b>
            </span>
        @endif

        @if ($leader !== null)
            {{-- Below 360 px the time to beat follows Play, so Play stays above the tab bar of a 320 x 640 phone --}}
            <div class="flex flex-wrap items-end gap-x-5 gap-y-2 max-[359px]:order-last" data-test="{{ $finished ? 'week-winner' : 'time-to-beat' }}">
                <span class="flex flex-col gap-1">
                    <span class="text-xs text-ink-2">{{ $finished ? __('Winner') : __('Time to beat') }}</span>
                    <b class="font-display text-[32px] leading-none font-bold tabular-nums lg:text-5xl">{{ $metric->format($leader->value) }}</b>
                </span>
                <span class="flex min-w-0 items-center gap-2 pb-0.5 text-[13px]">
                    <x-icon name="trophy" :size="16" class="shrink-0 text-rank-gold" />
                    @if ($leaderUser)
                        <x-avatar :user="$leaderUser" :size="24" class="shrink-0 rounded-sm" />
                        <a href="{{ route('players.show', $leaderUser->npub) }}" class="min-w-0 truncate font-bold text-ink hover:text-btc-hi">{{ $leader->participant->name }}</a>
                    @else
                        <span class="min-w-0 truncate font-bold text-ink-2">{{ $leader->participant->name }}</span>
                    @endif
                </span>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('stacker.play') }}" class="btn-p inline-flex h-14 grow items-center justify-center gap-2 rounded-md bg-btc px-6 text-base font-bold text-on-btc hover:text-on-btc sm:grow-0 sm:px-8" data-test="play-now">
                <x-icon name="play" :size="20" />{{ __('Play now') }}
            </a>
            <a href="{{ route('rules') }}#blockfill" class="btn-s inline-flex h-14 items-center justify-center rounded-md border border-edge px-5 text-[13px] font-bold text-ink hover:text-ink" data-test="how-it-works">{{ __('How it works') }}</a>
        </div>
    </div>
</section>

@include('pages.scores.partials.blockfill-steps')
