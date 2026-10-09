{{--
    Proof of Pong's figure picker (plan "Proof of Pong", P3): the cast's portraits as radio buttons named `figure`
    (App\Support\Pong\PongCast::players()), the picked one large with its name and tagline. On the lobby it is part
    of the bot form (a pick is sent without any script); on the start card of a game against a bot and the waiting
    card of a live match it changes the player's paddle (resources/js/pong/picker.js keeps the pick in the browser).
    Styles: resources/css/pong-picker.css.

    $figures   list of ['id', 'name', 'tagline']
    $selected  the id picked by the page (null: the browser's last pick, else the first)
    $compact   the small version inside a card over the field
    $lobby     the lobby's bar (P9): the pick in one row, the grid opens over the page and never lengthens it
    $form      the id of the form the radios belong to (the lobby's bot form; the pick stays in the browser for a live match)
--}}
@php
    $selected ??= null;
    $compact ??= false;
    $lobby ??= false;
    $form ??= null;
    $first = collect($figures)->firstWhere('id', $selected) ?? $figures[0];
@endphp
@if ($lobby)
<fieldset class="pp pp-lobby" data-pong-picker @if ($selected !== null) data-fixed @endif data-test="pong-picker">
    <legend class="sr-only">{{ __('Your paddle') }}</legend>
    {{-- The whole bar opens the roster: the figure itself is the way to change it. --}}
    <details class="pp-more" data-pp-more>
        <summary class="pp-sum" data-test="pong-figure-open">
            <span class="pp-pick" aria-live="polite">
                <img class="pp-pick-img" data-pick-img src="/pong/art/por-{{ $first['id'] }}.webp" alt="{{ $first['name'] }}" width="320" height="320">
                <span class="pp-pick-text">
                    <span class="pp-pick-label">{{ __('Your paddle') }}</span>
                    <b class="pp-pick-name" data-pick-name data-test="pong-picked-name">{{ $first['name'] }}</b>
                    <span class="pp-pick-tagline" data-pick-tagline>{{ $first['tagline'] }}</span>
                </span>
            </span>
            <span class="pp-more-btn"><span class="pp-more-long">{{ __('Change figure') }}</span><span class="pp-more-short">{{ __('Change') }}</span><x-icon name="chevron-down" :size="16" /></span>
        </summary>
        <div class="pp-pop">
            <div class="pp-grid" role="radiogroup" aria-label="{{ __('Your paddle') }}">
                @foreach ($figures as $figure)
                    <label class="pp-face" title="{{ $figure['name'] }}" data-test="pong-figure-{{ $figure['id'] }}">
                        <input type="radio" name="figure" value="{{ $figure['id'] }}" class="pp-radio" @checked($figure['id'] === $first['id']) @if ($form !== null) form="{{ $form }}" @endif
                               data-name="{{ $figure['name'] }}" data-tagline="{{ $figure['tagline'] }}" data-img="/pong/art/por-{{ $figure['id'] }}.webp">
                        <img src="/pong/art/por-{{ $figure['id'] }}.webp" alt="{{ $figure['name'] }}" width="96" height="96">
                    </label>
                @endforeach
            </div>
        </div>
    </details>
</fieldset>
@else
<fieldset @class(['pp', 'pp-compact' => $compact]) data-pong-picker @if ($selected !== null) data-fixed @endif data-test="pong-picker">
    <legend class="pp-legend">{{ __('Your paddle') }}</legend>
    <div class="pp-pick" aria-live="polite">
        <img class="pp-pick-img" data-pick-img src="/pong/art/por-{{ $first['id'] }}.webp" alt="{{ $first['name'] }}" width="320" height="320">
        <span class="pp-pick-text">
            <b class="pp-pick-name" data-pick-name data-test="pong-picked-name">{{ $first['name'] }}</b>
            <span class="pp-pick-tagline" data-pick-tagline>{{ $first['tagline'] }}</span>
        </span>
    </div>
    <div class="pp-grid" role="radiogroup" aria-label="{{ __('Your paddle') }}">
        @foreach ($figures as $figure)
            <label class="pp-face" title="{{ $figure['name'] }}" data-test="pong-figure-{{ $figure['id'] }}">
                <input type="radio" name="figure" value="{{ $figure['id'] }}" class="pp-radio" @checked($figure['id'] === $first['id'])
                       data-name="{{ $figure['name'] }}" data-tagline="{{ $figure['tagline'] }}" data-img="/pong/art/por-{{ $figure['id'] }}.webp">
                <img src="/pong/art/por-{{ $figure['id'] }}.webp" alt="{{ $figure['name'] }}" width="96" height="96">
            </label>
        @endforeach
    </div>
</fieldset>
@endif
