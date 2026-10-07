@props(['game', 'link' => true])

{{--
    The credit line of a game under its name (plan "Blockli", P3): "by
    DerCaddy", small and quiet, from the game's config entry (GameAssets::$credit).
    A link only when `credit_url` is set and `link` is not false (inside another
    link, as on a card that links to the game, it stays text); nothing at all for a game without a
    credit, so every place that shows a game's name can carry this.
--}}
@php
    $assets = app(\App\Games\GameRegistry::class)->find((string) $game)?->assets();
@endphp

@if ($assets?->credit !== null)
    @if ($assets->creditUrl !== null && $link)
        <a href="{{ $assets->creditUrl }}" rel="noopener" {{ $attributes->class('block text-[12px] leading-tight text-ink-3 underline decoration-edge underline-offset-4 hover:text-ink hover:decoration-btc')->merge(['data-test' => 'game-credit', 'data-game-credit' => $game]) }}>{{ $assets->credit }}</a>
    @else
        <span {{ $attributes->class('block text-[12px] leading-tight text-ink-3')->merge(['data-test' => 'game-credit', 'data-game-credit' => $game]) }}>{{ $assets->credit }}</span>
    @endif
@endif
