@php
    /** @var \App\Models\StackerRun|\App\Models\ScoreRun $run */
    // A highscore attempt (App\Support\Matches\ScoreAttempts), in the grid of a match row: one player (face and
    // name, nothing else of theirs), the checked value or none while it waits, and a link to the game's leaderboard.
    $waiting = \App\Support\Matches\ScoreAttempts::isWaiting($run);
    $slug = \App\Support\Matches\ScoreAttempts::game($run);
    $gameName = \App\Support\GameNames::game($slug);
    $chip = \App\Support\Series\SeriesPresenter::chipFor($waiting ? 'to_confirm' : 'done', $waiting ? __('to confirm') : __('done'));
    $at = \App\Support\Matches\ScoreAttempts::at($run);
@endphp

<a href="{{ $href }}" wire:key="{{ \App\Support\Matches\ScoreAttempts::key($run) }}" data-test="score-row" data-game="{{ $slug }}" data-state="{{ $waiting ? 'waiting' : 'done' }}"
   class="tr grid min-h-11 grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 rounded-sm px-2 py-2 text-[13px] text-ink hover:text-ink lg:h-11 lg:grid-cols-[96px_minmax(0,1fr)_88px_120px_150px_200px_120px] lg:gap-4 lg:py-0">
    {{-- No match number: an attempt is one player against the clock, so it says what it is. --}}
    <span><span class="rounded-xs border border-line px-1 text-[10px] text-ink-2">{{ __('highscore') }}</span></span>
    <span class="flex min-w-0 items-center gap-2 whitespace-nowrap">
        @if ($run->user?->exists)<x-avatar :user="$run->user" :size="20" class="rounded-xs" data-test="score-row-face" />@endif
        <span @class(['truncate', 'font-bold' => ! $waiting]) data-test="score-row-name">{{ $run->user?->displayName() ?? __('Deleted player') }}</span>
    </span>
    <span class="flex flex-col leading-tight"><b data-test="score-row-value">{{ \App\Support\Matches\ScoreAttempts::value($run) ?? '–' }}</b></span>
    <span class="col-span-2 flex items-center gap-1.5 text-ink-2 max-lg:col-start-2 max-lg:text-xs lg:col-span-1"><x-game-cover :game="$slug" size="thumb" class="w-8 rounded-xs" :title="$gameName" data-test="match-row-cover" /><span class="sr-only">{{ $gameName }}</span><span class="truncate">{{ \App\Support\Matches\ScoreAttempts::mode($run) }}</span></span>
    <span class="max-lg:hidden">
        <span class="inline-flex h-[26px] items-center gap-1.5 rounded-sm px-2.5 text-xs font-bold" style="background: {{ $chip['bg'] }}; color: {{ $chip['color'] }}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $chip['icon'] }}"></path></svg>{{ $chip['label'] }}
        </span>
    </span>
    <span class="relative block h-6 overflow-hidden rounded-sm bg-raised max-lg:col-span-3">
        <span class="absolute inset-y-0 left-0" style="width: {{ $waiting ? 50 : 100 }}%; background: {{ $waiting ? '#7A4A0E' : '#1F4D2E' }}"></span>
        <span class="relative flex h-6 items-center justify-center text-xs font-bold">{{ $waiting ? __('unconfirmed') : __('confirmed') }}</span>
    </span>
    <span class="text-right text-ink-2 max-lg:hidden">{{ $at?->diffForHumans() }}</span>
</a>
