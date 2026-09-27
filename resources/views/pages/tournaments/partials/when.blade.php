{{--
    When the tournament is, in the hero under the name: the date and the
    start in the league's zone with the zone named (MEZ or MESZ follow the
    date), how long until then, the sign-up deadline while sign-up is open,
    and the calendar file. The last line says the start in the viewer's own
    time; the browser fills it only when its zone runs on another offset at
    that moment, and its height is reserved so nothing moves.
    $tournament, $startsIn (TournamentLanding::startsIn()), $published.
--}}
@use('App\Support\LeagueTime')
@php
    $start = $tournament->starts_at;
    $utc = $start->copy()->utc()->format('Y-m-d\TH:i:s\Z');
@endphp

<div class="flex flex-col gap-3" data-test="tournament-when"
     x-data="localTime({ at: {{ (int) $start->getTimestampMs() }}, zone: @js(LeagueTime::zone()), label: @js(__('In your time: :time')) })">
    <time datetime="{{ $utc }}" class="flex flex-col gap-1.5" data-test="when-start">
        <span class="font-display text-lg leading-tight font-medium text-ink sm:text-xl" data-test="when-date">{{ LeagueTime::date($start) }}</span>
        <span class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="font-display text-[28px] leading-none font-bold tabular-nums sm:text-4xl" data-test="when-time">{{ LeagueTime::hour($start) }}</span>
            <span class="inline-flex h-7 items-center rounded-tag bg-raised px-2.5 text-[13px] font-bold whitespace-nowrap text-ink" data-test="when-zone"
                  title="{{ LeagueTime::zoneName() }}, {{ LeagueTime::offset($start) }} · {{ LeagueTime::utc($start) }}">{{ __(':abbreviation, :city', ['abbreviation' => LeagueTime::abbreviation($start), 'city' => LeagueTime::city()]) }}</span>
        </span>
    </time>

    <span class="flex flex-wrap items-center gap-x-5 gap-y-1 text-[13px] leading-normal text-ink-2">
        @if ($startsIn)
            <span class="text-ink" data-test="when-starts-in" x-data="startsIn({ at: {{ $startsIn['ms'] }}, labels: @js([
                'dh' => __('starts in :d d :h h'), 'hm' => __('starts in :h h :m min'), 'm' => __('starts in :m min'), 'soon' => __('starts in under a minute'),
            ]) })" x-text="text">{{ $startsIn['text'] }}</span>
        @endif
        @if ($tournament->isSignupOpen())
            <span data-test="when-signup">{{ __('Sign-up until :time', ['time' => LeagueTime::stamp($tournament->signup_closes_at)]) }}</span>
        @endif
        @if ($published)
            <a href="{{ route('tournaments.calendar', $tournament) }}" class="inline-flex min-h-11 items-center gap-1.5 sm:min-h-6" data-test="when-calendar" download>
                <x-icon name="calendar" :size="16" class="shrink-0" />{{ __('Add to calendar') }}
            </a>
        @endif
    </span>

    <span class="h-5 truncate text-[13px] leading-5 text-ink-2" data-test="when-local" x-text="text" x-bind:title="zone"></span>
</div>
