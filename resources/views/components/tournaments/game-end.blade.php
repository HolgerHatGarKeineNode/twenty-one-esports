@props(['panel', 'framed' => true, 'reveal' => false])

{{--
    What comes next after a tournament game (App\Support\Tournaments\TournamentGameEnd;
    user, 2026-10-03): the result, where the match stands, what the player waits
    for and what the others still play, "Back to the tournament", and for a player
    of a game that just ended the countdown to the tournament page or the next game
    of the pairing (resources/js/tournamentGameEnd.js), with "Stay here".
    Shown instead of the casual follow-ups (rematch, next opponent, new game).
    `framed` = its own card; false inside a dialog that is one already.
    `reveal` = scroll the countdown into view once it shows (a live board's end:
    on a phone the game-over card reaches under the chat bar, measured at 375 px).
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
    @if ($panel['countdown'])
        <span @class(['flex min-w-0 items-center justify-between gap-3 text-[13px] text-ink-2', 'sm:justify-start' => $framed, 'scroll-mb-40 lg:scroll-mb-6' => $reveal]) role="status" data-test="tournament-countdown"
              x-data="tournamentGameEnd({ seconds: {{ $panel['seconds'] }}, fallback: @js($panel['url']), reveal: @js((bool) $reveal) })">
            {{-- The number keeps its unit on one line (a no-break space; "in 4 / s." wrapped at 375 px). --}}
            @php($countdownText = $panel['next'] ? __('Your next game opens in :s s.') : __('The tournament page opens in :s s.'))
            <span class="min-w-0" x-show="!stopped" x-text="@js($countdownText).replace(':s s', left + ' s')" data-test="tournament-countdown-text">{{ str_replace(':s s', $panel['seconds']."\u{00A0}s", $countdownText) }}</span>
            <span class="min-w-0" x-show="stopped" x-cloak>{{ __('You stay on this page.') }}</span>
            <button type="button" x-show="!stopped" x-on:click="stay()" class="inline-flex min-h-11 shrink-0 cursor-pointer items-center text-ink underline decoration-edge underline-offset-4 hover:decoration-btc" data-test="tournament-stay">{{ __('Stay here') }}</button>
        </span>
    @endif
</section>
