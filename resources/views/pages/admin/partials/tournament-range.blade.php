@php
    use App\Support\Tournaments\Estimator;
    use App\Support\Tournaments\RoundTimes;

    /*
     * The honest duration of the chosen format online (P18, user decision
     * 2026-09-27): an online tournament has a start and an open end, so the
     * organizer sees when it is expected to end and the latest if every
     * deadline of the round clock runs out, with a warning past midnight
     * (league time) or beyond 10 hours. Measured rounds of finished
     * tournaments of the game are shown next to the assumption, never used
     * yet. Part of the chooser island (App\Livewire\TournamentFormatChooser).
     */
    $expected = $this->expectedTimes();
    $rangeProfile = $this->profile();
    $range = $this->durationRange;
    // With the night pause on, a time on a later day than the start says so: the pause moves an end to the next evening
    // (DerCaddy, 2026-11-04). Played through, an end after midnight keeps its plain time and the midnight warning.
    $pauseMoves = $this->nightPauseApplies() && $this->nightPauseOn();
    $clockTime = function (\Carbon\CarbonImmutable $moment) use ($expected, $pauseMoves): string {
        $local = $moment->setTimezone($this->chooserZone());
        $days = $expected['start'] === null || ! $pauseMoves ? 0 : (int) $expected['start']->setTimezone($this->chooserZone())->startOfDay()->diffInDays($local->startOfDay());

        return match (true) {
            $days <= 0 => $local->format('G:i'),
            $days === 1 => __(':time the next day', ['time' => $local->format('G:i')]),
            default => __(':time in :count days', ['time' => $local->format('G:i'), 'count' => $days]),
        };
    };
    $measured = $expected === null ? null : RoundTimes::median($rangeProfile->game, $rangeProfile->mode);
@endphp

@if ($expected !== null && $range !== null)
    <div class="flex flex-col gap-1.5 rounded-md bg-card px-3 py-2.5" data-test="duration-range">
        <p class="m-0 text-[13px] leading-normal" data-test="duration-range-line">
            @if ($expected['start'] !== null)
                {{ __('Start :start · expected end about :typical · at the latest about :latest if every deadline runs out', [
                    'start' => $clockTime($expected['start']), 'typical' => $clockTime($expected['typical']), 'latest' => $clockTime($expected['latest']),
                ]) }}
            @else
                {{ __('Expected about :typical, at the latest :latest if every deadline runs out', [
                    'typical' => Estimator::format($range->typical, $rangeProfile), 'latest' => Estimator::format($range->latest, $rangeProfile),
                ]) }}
            @endif
        </p>
        <span class="text-xs leading-normal text-ink-2" data-test="duration-range-detail">{{ \App\Support\Tournaments\Lobbies::isLobbyGame($rangeProfile->game)
            ? __('Every lobby plays at the same time, :planned with filling the lobby and the Time Limit. The end stays open for the players.', ['planned' => Estimator::format($range->planned, $rangeProfile)])
            : __('Planned play :planned, online about :typical with finding the opponent and reporting, at most :latest. The end stays open for the players.', [
            'planned' => Estimator::format($range->planned, $rangeProfile), 'typical' => Estimator::format($range->typical, $rangeProfile), 'latest' => Estimator::format($range->latest, $rangeProfile),
        ]) }}</span>
        @foreach ($expected['warnings'] as $warning)
            {{-- Past midnight with the night pause on (NightPause) is no fault: it says when play goes on instead of warning. --}}
            @if ($warning === 'after-midnight' && $this->nightPauseApplies() && $this->nightPauseOn())
                <span class="flex gap-2 text-xs leading-normal text-ink-2" role="status" data-test="duration-night-pause"><x-icon name="clock" :size="14" class="mt-0.5 shrink-0" />{{ __('The expected end is after :until: the night pause holds the tournament then, it goes on the next day at :resume (:zone).', [
                    'until' => \App\Support\Tournaments\NightPause::untilLabel($this->nightPauseTimes()['until']), 'resume' => $this->nightPauseTimes()['resume'], 'zone' => (string) config('esports.preseason.display_timezone'),
                ]) }}</span>
                @continue
            @endif
            <span class="flex gap-2 text-xs leading-normal text-loss" role="status" data-test="duration-warning-{{ $warning }}"><x-icon name="warn" :size="14" class="mt-0.5 shrink-0" />{{ $warning === 'after-midnight'
                ? __('The expected end is after midnight (:zone).', ['zone' => (string) config('esports.preseason.display_timezone')])
                : __('If every deadline runs out this takes more than 10 hours. Fewer players, shorter series or fewer rounds help.') }}</span>
        @endforeach
        @if ($measured !== null)
            <span class="text-xs leading-normal text-ink-2" data-test="duration-measured">{{ trans_choice('Measured: a round took :measured in the median of :count finished round (assumed :assumed).|Measured: a round took :measured in the median of :count finished rounds (assumed :assumed).', $measured['rounds'], [
                'measured' => Estimator::format($measured['minutes'], $rangeProfile), 'assumed' => Estimator::format($rangeProfile->onlineSlot(Estimator::matchGames($rangeProfile, $this->evaluation->options, Estimator::isTable($this->format, $this->evaluation->options))), $rangeProfile),
            ]) }}</span>
        @endif
    </div>
@endif
