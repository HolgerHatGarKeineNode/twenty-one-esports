{{--
    One score game mode on the player page (App\Support\Players\PlayerScores):
    the personal best, the place on the game's points ladder, the leaderboard
    running now (a Blockfill week) with the player's best and place in it,
    and the latest verified attempts. Values only, never a proof link or a
    game account. A card of the ladder grid in partials/stats, so its shape
    follows the ladder card next to it.

    $score: a PlayerScores card.
--}}
<li wire:key="score-{{ $score['key'] }}" class="flex min-w-0 flex-col gap-3 rounded-card bg-card p-4" data-test="player-score" data-game="{{ $score['game'] }}" data-mode="{{ $score['mode'] }}">
    <a href="{{ $score['href'] }}" class="grid min-h-11 grid-cols-[48px_minmax(0,1fr)] items-center gap-3 text-ink hover:text-ink">
        <x-game-cover :game="$score['game']" size="thumb" class="w-12 rounded-tag" />
        <span class="flex min-w-0 flex-col">
            <b class="truncate text-[13px]">{{ $score['name'] }}</b>
            <span class="truncate text-xs text-ink-3">{{ trans_choice(':count verified attempt|:count verified attempts', $score['runs']) }}</span>
        </span>
    </a>

    <span class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
        <b class="font-display text-[30px] leading-none font-bold tabular-nums" data-test="player-score-best">{{ $score['best'] }}</b>
        <span class="text-xs text-ink-3">{{ __('Personal best') }}</span>
        <span class="ml-auto text-xs text-ink-3" data-test="player-score-points">{{ __('Points ladder') }}
            @if ($score['points'] !== null)
                <b class="text-ink-2 tabular-nums">{{ __('#:place of :count', ['place' => $score['points']['place'], 'count' => $score['points']['of']]) }}</b>
            @else
                <b class="text-ink-2">{{ __('no points yet') }}</b>
            @endif
        </span>
    </span>

    @if ($score['board'] !== null)
        <a href="{{ $score['board']['href'] }}" class="flex min-h-11 min-w-0 items-center justify-between gap-3 rounded-md bg-raised px-3 text-xs text-ink hover:text-ink" data-test="player-score-board">
            <span class="min-w-0 truncate text-ink-2">{{ $score['board']['title'] }}</span>
            @if ($score['board']['place'] !== null)
                <span class="shrink-0 tabular-nums" data-test="player-score-board-place"><b>{{ $score['board']['value'] }}</b> · {{ __('#:place of :count', ['place' => $score['board']['place'], 'count' => $score['board']['of']]) }}</span>
            @else
                <span class="shrink-0 text-ink-3" data-test="player-score-board-place">{{ __('no value yet') }}</span>
            @endif
        </a>
    @endif

    @if (($score['replays'] ?? null) !== null)
        {{-- Blockfill: this player's replays the viewer may watch (an ended week's first ten; all of them for the player) --}}
        <a href="{{ $score['replays'] }}" class="flex min-h-11 items-center gap-3 rounded-md bg-raised px-3 text-[13px] font-bold text-ink hover:text-ink" data-test="player-score-replays">
            <span class="flex size-7 shrink-0 items-center justify-center rounded-sm bg-btc text-on-btc" aria-hidden="true"><x-icon name="play" :size="14" /></span>
            {{ __('Watch replays') }}
        </a>
    @endif

    <span class="flex flex-col gap-1.5" data-test="player-score-attempts">
        <span class="text-xs text-ink-3">{{ __('Latest verified attempts') }}</span>
        <span class="flex flex-wrap gap-1.5">
            @foreach ($score['attempts'] as $attempt)
                <span class="inline-flex h-7 items-center gap-1.5 rounded-sm bg-raised px-2 text-xs tabular-nums" data-test="player-score-attempt">
                    <b>{{ $attempt['value'] }}</b><span class="text-ink-3">{{ $attempt['at']?->diffForHumans(['short' => true]) }}</span>
                </span>
            @endforeach
        </span>
    </span>
</li>
