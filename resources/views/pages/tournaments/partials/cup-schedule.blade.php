{{--
    A casual cup's Rocket League or EA Sports FC match (P25 S3, CupSchedules):
    once the league started the series, its time and the way to the match
    room (check-in, lobby, report there); before, the agreed time, the
    opponent's proposal to accept, the player's own proposal waiting, or the
    form to propose one to three times. Without an agreement the league
    starts it at the auto slot. Needs $cup, $error and $format from
    partials/cup-match.
--}}
@php
    $checkinBefore = (int) config('esports.casual.checkin_before_minutes', 10);
    $schedule = $cup['match']->schedule;
    $open = $schedule !== null && $cup['agreed'] === null && (int) ($schedule['respond_by'] ?? 0) > now()->getTimestamp();
@endphp
@if ($cup['seriesMatch'])
    <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2" data-test="cup-series-set">
        {{ __('Your match starts :time. Check in in the match room from :open, or you lose by forfeit.', ['time' => $format($cup['seriesMatch']->start_at), 'open' => $format($cup['seriesMatch']->start_at->copy()->subMinutes($checkinBefore))]) }}
    </p>
    <div><x-button :href="route('matches.room', $cup['seriesMatch'])" icon="next" data-test="cup-series-room">{{ __('Go to the match room') }}</x-button></div>
@elseif ($cup['agreed'])
    <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2" data-test="cup-schedule-agreed">
        {{ __('Agreed: :time. The check-in opens :minutes minutes before in the match room.', ['time' => $format($cup['agreed']), 'minutes' => $checkinBefore]) }}
    </p>
@else
    <p class="m-0 max-w-[80ch] text-[13px] leading-normal text-ink-2" data-test="cup-schedule-when">
        {{ __('Agree on a time by :deadline. Without one the league starts your match at :slot.', ['deadline' => $format($cup['endsAt']), 'slot' => $format($cup['slot'])]) }}
    </p>
    @if ($open && ! $cup['mine'])
        <div class="flex flex-wrap items-center gap-2" data-test="cup-schedule-offers">
            @foreach ($schedule['proposals'] as $at)
                <x-button type="button" variant="secondary" wire:click="acceptCupTime({{ $at }})" data-test="cup-schedule-accept">{{ __('Accept :time', ['time' => $format(\Carbon\CarbonImmutable::createFromTimestamp($at))]) }}</x-button>
            @endforeach
        </div>
    @elseif ($open)
        <p class="m-0 text-[13px] text-ink-2" data-test="cup-schedule-waiting">{{ __('Times proposed. Waiting for :name to accept one.', ['name' => $cup['opponent']]) }}</p>
    @endif
    <form wire:submit="proposeCupTimes" class="flex flex-col gap-3" data-test="cup-schedule-form">
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach ([0, 1, 2] as $index)
                <x-berlin-datetime-input :model="'cupTimes.'.$index" :label="__('Time :number', ['number' => $index + 1])" :value="$this->cupTimes[$index] ?? ''" :test="'cup-time-'.$index" />
            @endforeach
        </div>
        <div><x-button type="submit" icon="calendar" data-test="cup-schedule-propose">{{ $open ? __('Propose other times') : __('Propose times') }}</x-button></div>
    </form>
@endif
@if ($error !== '')
    <p class="m-0 text-[13px] text-loss" role="alert" data-test="cup-match-error">{{ $error }}</p>
@endif
