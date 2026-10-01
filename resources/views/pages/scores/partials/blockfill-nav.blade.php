{{--
    The way around Blockfill's weeks: this week (when another one is shown), the week before the one shown ("Last week"
    from this week or the game page), the points ladder, the running week in a calendar, and every run in the matches list.
    $week: the week shown (null: the game page, which shows this week).
--}}
@php
    $weeks = app(\App\Support\Stacker\BlockfillWeeks::class);
    $current = $weeks->current();
    $shown = $week ?? $current;
    $isCurrent = $shown !== null && $current !== null && $shown->is($current);
    $before = $weeks->previous($shown?->starts_at);
    $links = array_values(array_filter([
        $current !== null && ($week === null || ! $isCurrent) ? ['tournaments', __('This week'), route('tournaments.scores', $current), 'nav-this-week'] : null,
        $before !== null ? ['prev', $isCurrent || $shown === null ? __('Last week') : __('Week before'), route('tournaments.scores', $before), 'nav-last-week'] : null,
        ['ladder', __('Points ladder'), route('scores.show', \App\Games\Blockfill::SLUG).'#points', 'nav-points'],
        $isCurrent ? ['calendar', __('Add to calendar'), route('tournaments.calendar', $current), 'nav-calendar'] : null,
        ['matches', __('All runs'), route('matches.index', ['game' => \App\Games\Blockfill::SLUG]), 'nav-runs'],
    ]));
@endphp
<nav aria-label="{{ __('Blockfill weeks') }}" data-test="blockfill-nav">
    <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
        @foreach ($links as [$icon, $label, $href, $test])
            <li class="min-w-0">
                <a href="{{ $href }}" class="btn-w inline-flex h-11 items-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] text-ink hover:text-ink" data-test="{{ $test }}">
                    <x-icon :name="$icon" :size="16" class="shrink-0 text-ink-2" />{{ $label }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
