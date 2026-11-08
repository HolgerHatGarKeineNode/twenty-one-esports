{{--
    When something goes wrong in a series (not a casual 1v1, which has both in its steps): the no-show report and
    what a dispute means. Shown with the step while the no-show can be filed now, otherwise inside the room's closed
    Details block. Needs $m, $viewer, $captainSide, $games and $noshowFrom from pages/matches/⚡room.
--}}
@php
    use App\Enums\SeriesStatus;
    use App\Support\Series\SeriesPresenter;
@endphp
<section aria-labelledby="problems-h" class="flex flex-col gap-3 rounded-lg bg-card px-4 py-5 lg:px-6" data-test="room-problems">
    <h2 id="problems-h" class="m-0 text-[15px] font-bold">{{ __('If something goes wrong') }}</h2>
    @if ($m->status === SeriesStatus::Accepted && $noshowFrom)
        <div class="flex flex-col gap-2" data-test="room-noshow">
            <b class="text-[13px]">{{ __('Opponent not in the lobby?') }}</b>
            <p class="m-0 text-xs leading-normal text-ink-2">{{ __('From :time, :minutes minutes after start, you can report it. An admin checks and scores the match as a forfeit.', ['time' => SeriesPresenter::time($noshowFrom, $viewer, 'H:i'), 'minutes' => $m->noshowMinutes()]) }}</p>
            <span class="flex flex-wrap items-center gap-3">
                <x-button variant="secondary" icon="user" wire:click="reportNoShow" class="disabled:cursor-not-allowed disabled:opacity-50" :disabled="$captainSide === null || $noshowFrom->isFuture() || $games !== [] || $m->noshow_reported_at !== null" data-test="report-noshow">{{ __('Opponent didn\'t show') }}</x-button>
                <span class="text-xs text-ink-3">{{ $m->noshow_reported_at ? __('reported, an admin decides') : ($games !== [] ? __('not needed, games are entered') : '') }}</span>
            </span>
        </div>
    @endif
    <p class="m-0 flex items-start gap-2 text-xs leading-normal text-ink-2"><x-icon name="retry" :size="14" class="mt-0.5 shrink-0" />{{ __('If a result is disputed, either side can submit again. The new one replaces the old; the timeline keeps both.') }}</p>
</section>
