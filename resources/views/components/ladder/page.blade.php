@props(['game' => null, 'title', 'tag' => null, 'leader' => null, 'chips' => [], 'chipsLabel' => null, 'lede' => null, 'mode' => null])

{{--
    The one ladder template (T5; LadderPage.dc.html, LadderHyper.dc.html, LadderPong.dc.html, LadderStrongest.dc.html;
    rules R1, R2, R5, R8). Every ladder renders through it: the chess, board and series ladders (ladder/⚡show),
    Hyperbitcoinization's season ladder, Proof of Pong's Elo ladder and the strongest players across all games.

        bar      a game's ladder carries its game bar (x-game.subnav, tab "Ladder" current); the cross-game list has none
        head     `title` (the h1) with its `tag` and, on the right, the `leader` (['name' => …, 'value' => …]);
                 with a `lede` the head is the game's hero card instead (cover, title, one line, `action` on the right)
        group    the other games of the game's group (x-game.group-switch: Go's sizes), on their ladder of `mode`
        chips    `chips` (x-ui.chips, Priority+): the ladders to switch to, one line
        main     the default slot, 8 of 12 columns: the standings (x-ladder.table) or the empty state
        aside    the right column, 4 of 12: how it counts, the strongest players
--}}
@php
    $colour = $game !== null ? app(\App\Games\GameRegistry::class)->find((string) $game)?->assets()->colour : null;
@endphp

<div {{ $attributes->class('flex grow flex-col') }} @if ($colour) style="--game: {{ $colour }}" @endif data-template="T5" @if ($game) data-game="{{ $game }}" @endif>
    @if ($game !== null)
        <x-game.subnav :game="$game" active="ladder" />
    @endif

    <div class="gp flex flex-col gap-6">
        {{-- A group's other games (Go's sizes), on their ladder of the same `mode` where they have it. --}}
        @if ($game !== null)
            <x-game.group-switch :game="$game" active="ladder" :mode="$mode" />
        @endif
        @if ($lede !== null)
            <section class="gp-hero" data-test="ladder-hero">
                <x-game-cover :game="$game" size="card" class="gp-hero-cover" loading="eager" />
                <div class="min-w-0">
                    <h1 class="gp-title">{{ \App\Support\GameNames::breakable($title) }}</h1>
                    <p class="gp-lede">{{ $lede }}</p>
                </div>
                @isset($action)
                    <div class="flex flex-col items-start gap-2 pr-3 sm:items-end">{{ $action }}</div>
                @endisset
            </section>
        @else
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2" data-test="ladder-head">
                <h1 class="m-0 font-display text-[30px] leading-[1.15] font-bold lg:text-[36px]">{{ $title }}</h1>
                @if ($tag)
                    {{-- Wraps on a phone: "Age of Empires II: Definitive Edition 1v1" ran 5 px past 320. --}}
                    <span class="rv-tag h-auto min-h-5 max-w-full py-0.5 whitespace-normal">{{ $tag }}</span>
                @endif
                @if ($leader !== null)
                    <span class="ms-auto flex items-center gap-2 text-[13px]" data-test="ladder-leader">
                        <x-icon name="trophy" :size="16" class="text-btc" />
                        <span class="rv-m">{{ __('Top') }}</span>
                        <b>{{ $leader['name'] }}</b>
                        <b class="font-display text-2xl text-btc tabular-nums">{{ is_numeric($leader['value']) ? \App\Support\Formats::number($leader['value']) : $leader['value'] }}</b>
                    </span>
                @endif
                @isset($action)
                    <div class="ms-auto flex items-center gap-2">{{ $action }}</div>
                @endisset
            </div>
        @endif

        @if ($chips !== [])
            <x-ui.chips :items="$chips" :label="$chipsLabel ?? __('Ladder')" :caption="__('Ladder game')" test="ladder-chips" size="lg" navigate />
        @endif

        <div @class(['gp-grid', 'lg:!grid-cols-1' => ! isset($aside)])>
            <div class="gp-main">{{ $slot }}</div>
            @isset($aside)
                <aside class="flex min-w-0 flex-col gap-4" data-test="ladder-aside">{{ $aside }}</aside>
            @endisset
        </div>
    </div>
</div>
