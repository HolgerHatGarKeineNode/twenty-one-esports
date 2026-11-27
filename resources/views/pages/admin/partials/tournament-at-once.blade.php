{{--
    How a correspondence round-robin table starts its games (FormatOptions::$atOnce; DerCaddy, 2026-11-26: "ob die Spiele
    direkt parallel gespielt werden oder nacheinander"), part of the chooser's options (pages.admin.partials.tournament-chooser,
    its scope: $options, $optionRow, $help, $profile, $this->resultsMode). Only correspondence play has the choice: live, a
    player plays one game at a time (BoardGameService::start(), P5), so blitz and rapid always go one after another, and a
    tournament director opens the rounds himself.
--}}
@if ($profile->allAtOnce && $this->resultsMode !== \App\Enums\TournamentResultsMode::Director->value)
    <div class="{{ $optionRow }}" data-test="at-once-option">
        <span class="flex flex-col items-start gap-2">
            <span id="at-once-l" class="text-[13px] font-bold">{{ __('Games of the rounds') }}</span>
            <x-tournaments.segmented labelledby="at-once-l" method="pickAtOnce" :current="$options->atOnce" :grow="false" data-test="at-once"
                :options="[[true, __('All at once')], [false, __('Round by round')]]" />
        </span>
        <span class="{{ $help }}" data-test="at-once-help">{{ $options->atOnce
            ? __('Every game starts right away: everyone plays all their opponents at the same time. Shortest, but many games at once.')
            : __('One round after another: a player\'s next game starts once both players are done with their earlier ones. One game at a time for everyone, but it takes about as many times longer as there are rounds.') }}</span>
    </div>
@endif
