@props(['panel', 'framed' => true])

{{--
    What comes next after a tournament game (App\Support\Tournaments\TournamentGameEnd;
    user, 2026-10-03): the result, where the match stands, what the player waits
    for and what the others still play, and "Back to the tournament". It never
    moves the player off the page on its own: they stay on the finished game.
    Shown instead of the casual follow-ups (rematch, next opponent, new game).
    `framed` = its own card; false inside a card that is one already.
--}}
<section aria-labelledby="tournament-end-h" {{ $attributes->class(['flex flex-col', 'gap-3 rounded-lg bg-card px-4 py-4 lg:px-6' => $framed, 'gap-2.5' => ! $framed]) }} data-test="tournament-panel" data-state="{{ $panel['state'] }}">
    <span class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-2">
        <x-icon name="trophy" :size="14" class="text-btc" />
        <span class="min-w-0 [overflow-wrap:anywhere]" data-test="tournament-panel-name">{{ $panel['tournament'] }}</span>
        <span aria-hidden="true">·</span><span data-test="tournament-panel-round">{{ $panel['round'] }}</span>
        @if ($panel['step'])
            <span aria-hidden="true">·</span><span class="font-bold text-btc-hi" data-test="tournament-panel-step">{{ $panel['step'] }}</span>
        @endif
        @if ($panel['score'])
            <span aria-hidden="true">·</span><span data-test="tournament-panel-score">{{ $panel['score'] }}</span>
        @endif
    </span>
    <h2 id="tournament-end-h" @class(['m-0 font-display leading-[1.25] font-bold', 'text-lg' => $framed, 'text-base' => ! $framed]) data-test="tournament-panel-headline">{{ $panel['headline'] }}</h2>
    <span class="flex flex-col gap-1 text-[13px] leading-normal">
        @if ($panel['result'])
            <span class="text-ink" data-test="tournament-panel-result">{{ $panel['result'] }}</span>
        @endif
        @if ($panel['line'])
            <span class="text-ink-2" data-test="tournament-panel-line">{{ $panel['line'] }}</span>
        @endif
        @if ($panel['others'])
            <span class="text-ink-2" data-test="tournament-panel-others">{{ $panel['others'] }}</span>
        @endif
    </span>
    <x-button :href="$panel['url']" icon="trophy" @class(['w-full', 'sm:w-auto sm:self-start' => $framed]) data-test="back-to-tournament">{{ __('Back to the tournament') }}</x-button>
</section>
