@php
    use App\Support\Tournaments\TournamentDeadlines;

    /*
     * The tournament's own deadlines (P18): the first-move window of a chess
     * game, and the no-show wait, report deadline and response deadline of a
     * series. Empty = the league default, shown as the placeholder. Shared by
     * pages::admin.tournament-create and pages::admin.tournament-edit (state:
     * App\Livewire\TournamentFormatChooser); saved with the page's button.
     */
    $defaults = TournamentDeadlines::defaults($this->profile()->mode);
    $bounds = TournamentDeadlines::BOUNDS;
    $deadlineField = 'h-11 w-full min-w-0 rounded-md border border-edge bg-ground px-3 text-[13px] text-ink';
    $deadlines = [
        ['checkinMinutes', 'checkin_minutes', __('Chess: first move within (minutes)'), __('A player who misses it loses by forfeit.')],
        ['noshowMinutes', 'noshow_minutes', __('Series: no-show report after (minutes)'), __('From the start until a captain can report the other side missing.')],
        ['reportHours', 'report_hours', __('Series: result due (hours after the start)'), __('Nobody reported by then: the match goes to the admin queue.')],
        ['responseMinutes', 'response_minutes', __('Series: answer within (minutes)'), __('An unanswered result is confirmed unrated; an unanswered no-show is a forfeit.')],
    ];
@endphp

<section aria-labelledby="deadlines-h" class="flex flex-col gap-3 rounded-lg bg-card p-4 lg:px-6 lg:py-5" data-test="tournament-deadlines">
    <span class="flex flex-col gap-1">
        <h2 id="deadlines-h" class="m-0 text-[15px] font-bold">{{ __('Deadlines') }}</h2>
        <span class="max-w-[80ch] text-xs leading-normal text-ink-2">{{ __('The league applies them when the players report their results. Leave a field empty for the league default. A change while the tournament runs applies to matches paired from then on.') }}</span>
    </span>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4 lg:gap-4">
        @foreach ($deadlines as [$property, $column, $label, $hint])
            <label class="flex min-w-0 flex-col gap-1.5 text-xs text-ink-2" wire:key="deadline-{{ $column }}">
                {{ $label }}
                <input type="number" inputmode="numeric" min="{{ $bounds[$column][0] }}" max="{{ $bounds[$column][1] }}" step="1"
                       wire:model="{{ $property }}" placeholder="{{ $defaults[$column] }}" data-test="deadline-{{ $column }}"
                       class="{{ $deadlineField }}">
                <span class="text-ink-3">{{ $hint }} {{ __(':min to :max, default :default.', ['min' => $bounds[$column][0], 'max' => $bounds[$column][1], 'default' => $defaults[$column]]) }}</span>
                @error($property)<span class="text-loss" role="alert">{{ $message }}</span>@enderror
            </label>
        @endforeach
    </div>
</section>
