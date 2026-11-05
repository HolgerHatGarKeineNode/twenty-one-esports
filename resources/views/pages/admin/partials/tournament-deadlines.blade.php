@php
    use App\Support\Tournaments\TournamentDeadlines;

    /*
     * The tournament's own deadlines (P18): the first-move window of a chess
     * game or a board game (plan "Mühle und Dame"), and the no-show wait, report deadline and response deadline of a
     * series. Empty = the league default, shown as the placeholder. Shared by
     * pages::admin.tournament-create and pages::admin.tournament-edit (state:
     * App\Livewire\TournamentFormatChooser); saved with the page's button.
     */
    $defaults = TournamentDeadlines::defaults($this->profile()->mode);
    $bounds = TournamentDeadlines::BOUNDS;
    // Online and a minute game: one day on the round clock (P18), whose derived values are the defaults.
    $roundClock = $this->stationLimit() === null && ! $this->profile()->isDaily();
    $shown = $defaults;
    $reportDefault = null;

    if ($roundClock) {
        $clock = TournamentDeadlines::clock($this->profile()->mode);
        $shown = [...$defaults, 'noshow_minutes' => $clock->noshowMinutes, 'response_minutes' => $clock->responseMinutes, 'report_hours' => ''];
        $chooserOptions = $this->formatOptions();
        $reportDefault = [$clock->reportDueMinutes($this->profile(), $chooserOptions->bestOf), $clock->reportDueMinutes($this->profile(), $chooserOptions->finalBestOf)];
    }
    $deadlineField = 'rv-in';
    // Each game sees the deadlines that reach it (DerCaddy, 2026-11-04: Blockli showed a captain's no-show report and a result
    // to report, though its games run here and end on the board): TournamentFormatChooser::applicableDeadlines().
    $applicable = $this->applicableDeadlines();
    $playedHere = $this->profile()->playedOnLeague($this->resultsMode === \App\Enums\TournamentResultsMode::Director->value);
    $deadlines = array_values(array_filter([
        ['checkinMinutes', 'checkin_minutes', __('First move within (minutes)'), __('A player who misses it loses by forfeit.')],
        ['noshowMinutes', 'noshow_minutes', __('No-show report after (minutes)'), __('From the start until a captain can report the other side missing.')],
        ['reportHours', 'report_hours', __('Result due (hours after the start)'), __('Nobody reported by then: the match goes to the admin queue.')],
        ['responseMinutes', 'response_minutes', __('Answer within (minutes)'), __('An unanswered result is confirmed unrated; an unanswered no-show is a forfeit.')],
    ], fn (array $deadline): bool => in_array($deadline[0], $applicable, true)));
@endphp

@if (\App\Support\Tournaments\Lobbies::isLobbyGame($this->profile()->game))
    {{-- A lobby game (P10): no first move, no no-show, no series report; its players report each lobby by its own deadline. --}}
    <section class="rv-sec" data-test="tournament-deadlines-lobby">
        <h2 class="rv-h3">{{ __('Deadlines') }}</h2>
        <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('A lobby has no series deadlines: its players report the places within :time after the Time Limit, then a director decides.', ['time' => \App\Support\Pages\RulesPage::minutes((int) (\App\Support\Tournaments\Lobbies::config($this->profile()->game)['report_minutes'] ?? 60))]) }}</span>
    </section>
@elseif ($deadlines === [])
    {{-- Nothing reaches the game: directors enter every result, or Hyperbitcoinization runs on its own turn clock. --}}
    <section class="rv-sec" data-test="{{ $playedHere ? 'tournament-deadlines-none' : 'tournament-deadlines-directors' }}">
        <h2 class="rv-h3">{{ __('Deadlines') }}</h2>
        <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ $playedHere
            ? __('Every match runs here on the league on its own turn clock: no deadline of the tournament applies.')
            : __('The directors enter every result: no deadline of the league applies.') }}</span>
    </section>
@else
<section aria-labelledby="deadlines-h" class="rv-sec" data-test="tournament-deadlines">
    <span class="flex flex-col gap-1">
        <h2 id="deadlines-h" class="rv-h3">{{ __('Deadlines') }}</h2>
        <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ $playedHere
            ? __('Every game starts on the league by itself; its result comes from the game itself, nobody reports it. Leave the field empty for the league default. A change while the tournament runs applies to games paired from then on.')
            : __('The league applies them when the players report their results. Leave a field empty for the league default. A change while the tournament runs applies to matches paired from then on.') }}</span>
        @if ($reportDefault !== null && in_array('noshowMinutes', $applicable, true))
            <span class="max-w-[80ch] text-xs leading-normal text-ink-2" data-test="round-clock">{{ __('Online on one day, every deadline runs from the start of the match: a no-show after :noshow min, the result :report min after the start (a final :final min), an answer within :response min.', [
                'noshow' => $shown['noshow_minutes'], 'report' => $reportDefault[0], 'final' => $reportDefault[1], 'response' => $shown['response_minutes'],
            ]) }}</span>
        @endif
    </span>
    {{-- Three fields side by side only where each keeps about 340 px for its label and help (the admin column from 1400 px); at
         1024 the columns were 156 px and the help ran 4 to 8 lines (E1). A single field keeps one column's width. --}}
    <div @class(['grid grid-cols-1 gap-3 @min-[480px]/admin:grid-cols-2 @min-[480px]/admin:gap-4', '@min-[1400px]/admin:grid-cols-3' => count($deadlines) > 1, '@min-[1400px]/admin:grid-cols-4' => count($deadlines) === 1])>
        @foreach ($deadlines as [$property, $column, $label, $hint])
            <label class="flex min-w-0 flex-col text-xs text-ink-2" wire:key="deadline-{{ $column }}">
                <span class="rv-lb">{{ $label }}</span>
                <input type="number" inputmode="numeric" min="{{ $bounds[$column][0] }}" max="{{ $bounds[$column][1] }}" step="1"
                       wire:model="{{ $property }}" placeholder="{{ $shown[$column] }}" data-test="deadline-{{ $column }}"
                       class="{{ $deadlineField }}">
                <span class="mt-1 text-ink-3">{{ $hint }} {{ $column === 'report_hours' && $reportDefault !== null
                    ? __(':min to :max; empty: :minutes min after the start of the match.', ['min' => $bounds[$column][0], 'max' => $bounds[$column][1], 'minutes' => $reportDefault[0]])
                    : __(':min to :max, default :default.', ['min' => $bounds[$column][0], 'max' => $bounds[$column][1], 'default' => $shown[$column]]) }}</span>
                @error($property)<span class="text-loss" role="alert">{{ $message }}</span>@enderror
            </label>
        @endforeach
    </div>
</section>
@endif

@if ($this->nightPauseApplies())
    @php
        // The night pause (NightPause): the league's default as the placeholder, the organizer's switch and times win.
        $nightDefaults = \App\Support\Tournaments\NightPause::defaults();
        $nightOn = $this->nightPauseOn();
        $nightTimes = $this->nightPauseTimes();
    @endphp
    {{-- The night pause in the board's section (`sec`) and switch row (`srow` + `sw`), the times as plain fields: no native control. --}}
    <section aria-labelledby="night-pause-h" class="rv-sec" data-test="tournament-night-pause">
        {{-- State and switch go under the text where both do not fit beside it (the text ran 12 lines in 140 px at 390). --}}
        <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
            <span class="flex min-w-0 grow basis-[18rem] flex-col gap-1">
                <h2 id="night-pause-h" class="rv-h3">{{ __('Night pause') }}</h2>
                <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('From :until to :resume (Berlin) no new match starts and no deadline runs. A match under way may be played to its end; its deadlines stop and go on at :resume.', [
                    'until' => \App\Support\Tournaments\NightPause::untilLabel($nightTimes['until']), 'resume' => $nightTimes['resume'],
                ]) }}</span>
                <span class="text-xs text-ink-3">{{ __('League default: :state, :until to :resume.', [
                    'state' => $nightDefaults['enabled'] ? __('on') : __('off'), 'until' => \App\Support\Tournaments\NightPause::untilLabel($nightDefaults['until']), 'resume' => $nightDefaults['resume'],
                ]) }}</span>
            </span>
            <span @class(['rv-tag shrink-0', 'is-btc' => $nightOn]) data-test="night-pause-state">{{ $nightOn ? __('on') : __('play through') }}</span>
            <x-ui.switch :checked="$nightOn" :label="__('Night pause')" wire:click="toggleNightPause" data-test="night-pause-switch" />
        </div>
        @if ($nightOn)
            <div class="grid grid-cols-2 gap-3 sm:max-w-md lg:gap-4">
                @foreach ([['nightPauseUntil', __('Pause from'), \App\Support\Tournaments\NightPause::untilLabel($nightDefaults['until'])], ['nightPauseResume', __('Go on at'), $nightDefaults['resume']]] as [$property, $label, $placeholder])
                    <label class="flex min-w-0 flex-col text-xs text-ink-2" wire:key="night-{{ $property }}">
                        <span class="rv-lb">{{ $label }}</span>
                        <input type="text" inputmode="numeric" maxlength="5" autocomplete="off" wire:model.blur="{{ $property }}" placeholder="{{ $placeholder }}"
                               data-test="night-pause-{{ $property === 'nightPauseUntil' ? 'until' : 'resume' }}" class="{{ $deadlineField }} tabular-nums">
                        <span class="mt-1 text-ink-3">{{ __('HH:MM, Berlin; empty: :default.', ['default' => $placeholder]) }}</span>
                        @error($property)<span class="text-loss" role="alert">{{ $message }}</span>@enderror
                    </label>
                @endforeach
            </div>
        @endif
    </section>
@endif

{{-- Only a knockout plays a decider (DerCaddy, 2026-11-04). --}}
@if ($this->drawDeciderMatters())
    @php
        // A drawn deciding game of a two-leg pairing (Blockli): the race standing or a replay (DerCaddy, PR #5; user, 2026-10-11).
        $replays = \App\Support\Tournaments\TournamentRunner::drawnReplays();
        $deciderEditable = $this->drawDeciderEditable();
    @endphp
    {{-- The board's section (`sec`) with its choice list (`opts`), no native radio; locked once sign-up closed, like the prizes. --}}
    <section aria-labelledby="draw-decider-h" class="rv-sec" data-test="tournament-draw-decider">
        <span class="flex flex-col gap-1">
            <h2 id="draw-decider-h" class="rv-h3">{{ __('Drawn decider') }}</h2>
            <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('At 1:1 after both games a third game decides. If it is drawn too:') }}</span>
        </span>
        @if ($deciderEditable)
            <x-ui.options class="sm:max-w-xl" method="useDrawDecider" :current="$this->drawDecider" labelledby="draw-decider-h" test="draw-decider" :options="[
                [\App\Models\Tournament::DRAW_DECIDER_STANDING, __('The race standing decides'), __('The side ahead in the race standing of the final position wins. A level standing is replayed.')],
                [\App\Models\Tournament::DRAW_DECIDER_REPLAY, __('Replay'), trans_choice('Replayed up to :count time, then the higher seed goes through.|Replayed up to :count times, then the higher seed goes through.', $replays)],
            ]" />
        @else
            <p class="m-0 text-[13px] text-ink" data-test="draw-decider-locked">
                <b>{{ $this->drawDecider === \App\Models\Tournament::DRAW_DECIDER_STANDING ? __('The race standing decides') : __('Replay') }}.</b>
                <span class="text-ink-2">{{ __('Part of the rules players signed up under: it no longer changes.') }}</span>
            </p>
        @endif
    </section>
@endif
