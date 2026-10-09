{{--
    Proof of Pong's figure picker (plan "Proof of Pong", P3): the cast's portraits as radio buttons named `figure`
    (App\Support\Pong\PongCast::players()), the picked one large with its name and tagline. On the lobby it is part
    of the bot form (a pick is sent without any script); on the start card of a game against a bot and the waiting
    card of a live match it changes the player's paddle (resources/js/pong/picker.js keeps the pick in the browser).
    Styles: resources/css/pong-picker.css.

    $figures   list of ['id', 'name', 'tagline']
    $selected  the id picked by the page (null: the browser's last pick, else the first)
    $compact   the small version inside a card over the field
--}}
@php
    $selected ??= null;
    $compact ??= false;
    $first = collect($figures)->firstWhere('id', $selected) ?? $figures[0];
@endphp
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
