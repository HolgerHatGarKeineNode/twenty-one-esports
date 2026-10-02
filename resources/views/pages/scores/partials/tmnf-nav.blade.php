{{--
    The way around TMNF's weeks from a week's board: this week (when another one is shown), the week before the one
    shown, the week's own page, the game page with its points ladder, and the rules.
    $week: the week shown.
--}}
@php
    $weeks = app(\App\Support\Tmnf\TmnfWeeks::class);
    $current = $weeks->current();
    $isCurrent = $current !== null && $week->is($current);
    $before = $weeks->previous($week->starts_at);
    $links = array_values(array_filter([
        $current !== null && ! $isCurrent ? ['tournaments', __('This week'), route('tournaments.scores', $current), 'nav-this-week'] : null,
        $before !== null ? ['prev', $isCurrent ? __('Last week') : __('Week before'), route('tournaments.scores', $before), 'nav-last-week'] : null,
        ['calendar', __('Week page'), route('tournaments.show', $week), 'nav-week-page'],
        ['flag', \App\Support\GameNames::game(\App\Games\TrackmaniaNationsForever::SLUG), route('scores.show', \App\Games\TrackmaniaNationsForever::SLUG), 'nav-game'],
        ['ladder', __('Points ladder'), route('scores.show', \App\Games\TrackmaniaNationsForever::SLUG).'#points', 'nav-points'],
        ['shield-check', __('Rules'), route('rules').'#tmnf', 'nav-rules'],
    ]));
@endphp
<nav aria-label="{{ __('TMNF weeks') }}" data-test="tmnf-nav">
    <ul class="m-0 flex list-none flex-wrap gap-2 p-0">
        @foreach ($links as [$icon, $label, $href, $test])
            <li class="min-w-0">
                <a href="{{ $href }}" class="btn-w inline-flex h-11 max-w-full items-center gap-2 rounded-md border border-line bg-well px-4 text-[13px] text-ink hover:text-ink" data-test="{{ $test }}">
                    <x-icon :name="$icon" :size="16" class="shrink-0 text-ink-2" /><span class="truncate">{{ $label }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
