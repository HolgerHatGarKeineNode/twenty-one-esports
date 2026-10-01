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
    $clockTime = fn (\Carbon\CarbonImmutable $moment): string => $moment->setTimezone($this->chooserZone())->format('G:i');
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
            <span class="flex gap-2 text-xs leading-normal text-loss" role="status" data-test="duration-warning-{{ $warning }}"><x-icon name="warn" :size="14" class="mt-0.5 shrink-0" />{{ $warning === 'after-midnight'
                ? __('The expected end is after midnight (:zone).', ['zone' => (string) config('esports.preseason.display_timezone')])
                : __('If every deadline runs out this takes more than 10 hours. Fewer players, shorter series or fewer rounds help.') }}</span>
        @endforeach
        @if ($measured !== null)
            <span class="text-xs leading-normal text-ink-2" data-test="duration-measured">{{ trans_choice('Measured: a round took :measured in the median of :count finished round (assumed :assumed).|Measured: a round took :measured in the median of :count finished rounds (assumed :assumed).', $measured['rounds'], [
                'measured' => Estimator::format($measured['minutes'], $rangeProfile), 'assumed' => Estimator::format($rangeProfile->onlineSlot($this->evaluation->options->bestOf), $rangeProfile),
            ]) }}</span>
        @endif
    </div>
@endif
