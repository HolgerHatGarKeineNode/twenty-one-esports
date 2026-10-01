{{--
    The way in for a score game whose runs the league checks itself (no manual submission, ScoreGame::acceptsManual()
    false; Blockfill): a Play button to the game itself and what counts, in the place of "Submit your value". Nothing for
    a game that takes submissions, or one without a page of its own to play (its link would be the leaderboards again).
    $slug (the game), $class (optional, the wrapper's layout).
--}}
@php
    $autoGame = app(\App\Games\GameRegistry::class)->find($slug);
    $playUrl = \App\Support\GameNames::page($slug);
    $ownPage = ! \Illuminate\Support\Facades\Route::has('scores.show') || $playUrl !== route('scores.show', $slug);
@endphp
@if ($autoGame instanceof \App\Games\ScoreGame && ! $autoGame->acceptsManual() && $ownPage)
    <span @class(['flex flex-wrap items-center gap-x-3 gap-y-1', $class ?? '']) data-test="score-play">
        <x-button :href="$playUrl" icon="play" data-test="score-play-button">{{ __('Play') }}</x-button>
        <span class="text-xs text-ink-2" data-test="score-play-note">{{ __('Your best verified run counts automatically') }}</span>
    </span>
@endif
