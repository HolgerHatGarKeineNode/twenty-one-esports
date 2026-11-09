@props(['game', 'active' => 'play', 'mode' => null, 'template' => 'T1', 'title' => null, 'lede' => null, 'stat' => null, 'statLabel' => null, 'statLive' => false, 'stickyRail' => true, 'playLabel' => null, 'phoneAction' => 'bar'])

{{--
    The one lobby template of every game (template T1, rules R1, R5, R6, R7; GameLobby.dc.html, HyperIndex.dc.html,
    GameScore.dc.html, GameSeries.dc.html). Every game page renders through it, so every game has the same slots in
    the same place; what differs is only what a slot holds:

        bar        the game bar (x-game.subnav): name, fixed tabs, `primary` on the right, `mode` beside it
        hero       cover, `title` (the page's h1), `lede` (one data line), `stat` + `statLabel` (one big number);
                   a page may replace the whole card with its own `hero` slot
        lead       the page's primary block under the hero ("Spielarten"); the chat follows it in the reading order
        main       the default slot: the rest of the main column, 8 of 12 columns (your games, the ladder …)
        foot       `next` (the next tournament) | `secondary` (the secondary slot, x-game.secondary), the main
                   column's last row
        rail       `chat`: the right column, 4 of 12, sticky (R6). A page whose column holds more than the chat
                   (Hyperbitcoinization's quick match) passes :sticky-rail="false"
        heroAside  replaces the big number with an action (HyperIndex.dc.html: "Gegen Bots spielen")

    Reading order: hero, lead, chat, the rest. From lg the grid puts the chat into the right column beside all of it
    (revamp-games.css `.gp-grid.has-rail`); below lg the chat is a bar right under the primary block, where a player
    asked for it (2026-10-03, "weiter oben"), not at the end of a long page. Without `lead` the chat closes the page.

    `template`: T1 for a lobby; a game page of another kind keeps the same frame and names itself.

    Phone (below lg, GamePhone.dc.html): the `primary` action leaves the game bar, where it cut the tabs off, for a bar
    on the floor above the tab bar: "Chat" (opens the chat, when the page has one) and `playLabel` ("Spielen: Rapid
    suchen"), which presses the page's own primary action, so the action exists once. The bar owns the floor
    (`data-floor-owner`, app.css) and the page ends above it (app.css reserves the bar's height).
    `phoneAction="hero"`: the hero's own action (heroAside, Hyperbitcoinization's "Gegen Bots spielen") already stands
    in the first screen, and the page's other first-screen action ("Tisch eröffnen") must stay there: no floor bar takes
    72 px of it; the game bar's button still leaves the tabs alone.
--}}
@php
    $colour = app(\App\Games\GameRegistry::class)->find((string) $game)?->assets()->colour;
    $heroAbove = isset($heroAside) && ! isset($hero);
    $hasLead = isset($lead);
    $primaryOn = isset($primary) && $primary->isNotEmpty();
    $playBar = $primaryOn && $phoneAction === 'bar';
@endphp

<div {{ $attributes->class(['flex grow flex-col']) }} style="--game: {{ $colour }}" data-template="{{ $template }}" data-game="{{ $game }}">
    <x-game.subnav :game="$game" :active="$active" :mode="$mode" :phone-bar="$primaryOn">{{ $primary ?? '' }}</x-game.subnav>

    <div class="gp">
        @isset($top)
            <div class="gp-top mb-4 flex flex-col gap-3 lg:mb-6">{{ $top }}</div>
        @endisset
        @if ($heroAbove)
            {{-- With an action instead of a number the hero spans both columns (HyperIndex.dc.html), so a long name keeps one line. --}}
            <div class="mb-6"><x-game.hero-card :game="$game" :title="$title" :lede="$lede" :stat="$stat" :stat-label="$statLabel" :stat-live="$statLive">{{ $heroAside ?? '' }}</x-game.hero-card></div>
        @endif
        <div @class(['gp-grid', 'has-rail' => isset($chat)])>
            <div class="gp-main gp-lead">
                @isset($hero)
                    {{ $hero }}
                @elseif (! $heroAbove)
                    <x-game.hero-card :game="$game" :title="$title" :lede="$lede" :stat="$stat" :stat-label="$statLabel" :stat-live="$statLive">{{ $heroAside ?? '' }}</x-game.hero-card>
                @endisset

                {{ $lead ?? '' }}
            </div>

            @if (isset($chat) && $hasLead)
                <aside @class(['gp-rail', 'is-loose' => ! $stickyRail]) aria-label="{{ __('Chat') }}" data-test="game-rail">{{ $chat }}</aside>
            @endif

            <div class="gp-main gp-rest">
                {{ $slot }}

                @if (isset($next) || isset($secondary))
                    <div class="gp-foot">
                        <div class="flex min-w-0 flex-col gap-3">{{ $next ?? '' }}</div>
                        <div class="flex min-w-0 flex-col gap-3">{{ $secondary ?? '' }}</div>
                    </div>
                @endif
            </div>

            @if (isset($chat) && ! $hasLead)
                <aside @class(['gp-rail', 'is-loose' => ! $stickyRail]) aria-label="{{ __('Chat') }}" data-test="game-rail">{{ $chat }}</aside>
            @endif
        </div>
    </div>

    @if ($playBar)
        {{-- GamePhone.dc.html: "Chat" and the page's one action on the floor above the tab bar (R3, R6). --}}
        <div class="gp-play-bar lg:hidden" data-test="game-play-bar" data-floor-owner>
            @isset($chat)
                <button type="button" class="rv-b2" data-test="game-play-bar-chat"
                        x-data x-on:click="const chat = document.querySelector('[data-test=game-chat]'); if (! chat) return; if (chat.dataset.open !== 'true') chat.querySelector('[data-test=game-chat-toggle]')?.click(); chat.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' })">
                    <x-icon name="chat" :size="16" />{{ __('Chat') }}
                </button>
            @endisset
            <button type="button" class="rv-b grow" data-test="game-play-bar-play"
                    x-data x-on:click="document.querySelector('[data-test=game-subnav] .gs-act')?.querySelector('a, button')?.click()">{{ $playLabel ?? __('Play') }}</button>
        </div>
    @endif
</div>
