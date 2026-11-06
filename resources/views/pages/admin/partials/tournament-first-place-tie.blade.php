{{--
    What a tie for place 1 of the final table comes to when points and every tie-break leave it (FirstPlaceTie,
    FormatOptions::$firstPlaceTie), part of the chooser's options (pages.admin.partials.tournament-chooser, its scope:
    $options, $optionRow, $help), right under the tie-breaks of a round robin and of Swiss. DerCaddy, 2026-11-06: "entweder
    Stichkampf oder geteilter erster Platz, soll der Turnierleiter im Editor auswählen können". Options stored before the
    choice keep the seed order (`seed`): the control then shows neither, and the help says so.
--}}
<div class="{{ $optionRow }}" data-test="first-place-tie-option">
    <span class="flex flex-col items-start gap-2">
        <span id="fpt-l" class="text-[13px] font-bold">{{ __('Tie for 1st place') }}</span>
        <x-tournaments.segmented labelledby="fpt-l" method="pickFirstPlaceTie" :current="$options->firstPlaceTie" :grow="false" data-test="first-place-tie"
            :options="[['playoff', __('Tie-break match')], ['shared', __('Shared 1st place')]]" />
    </span>
    <span class="{{ $help }}" data-test="first-place-tie-help">{{ match ($options->firstPlaceTie) {
        'shared' => __('Shared 1st place: those level on points and every tie-break share 1st place, and the prizes of the places they hold are split equally.'),
        'playoff' => __('Tie-break match: those level on points and every tie-break play it after the last round, as a knockout without draws; with three or more, the best seeds get a bye. Plan a little time for it.'),
        default => __('Stored before this choice: the higher seed takes 1st place. Pick one to change it.'),
    } }}</span>
</div>
