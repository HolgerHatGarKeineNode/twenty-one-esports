@props(['game', 'active' => 'play', 'mode' => null, 'phoneBar' => false])

{{--
    The game bar of every game page (rule R5, GameLobby.dc.html `.sn`): the game's thumbnail and name, its fixed tabs
    (Spielen, Matches, Ladder, Turniere, Regeln; ShellNavigation::gameTabs(), what a game does not have is left out),
    the primary action on the right (the default slot) and its mode as a quiet label. Sticky under the header. It
    replaces the header's context bar on the pages that render it: the header asks the request whether a page drew
    its own bar (the page renders before its layout), so the old row 2 stays only on pages the revamp has not
    redrawn yet. `active`: the key of the page's own tab.
--}}
@php
    request()->attributes->set('game-subnav', true);
    $nav = \App\Support\Navigation\ShellNavigation::current();
    $tabs = $nav->gameTabs((string) $game);
    $name = \App\Support\GameNames::game((string) $game);
    $colour = app(\App\Games\GameRegistry::class)->find((string) $game)?->assets()->colour;
@endphp

<nav {{ $attributes->class('gs-bar') }} style="--game: {{ $colour }}" aria-label="{{ $name }}" data-test="game-subnav" data-game="{{ $game }}">
    <a href="{{ \App\Support\GameNames::page((string) $game) }}" @navigate(\App\Support\GameNames::page((string) $game)) class="gs-name max-sm:hidden" data-test="game-subnav-name">
        <x-game-cover :game="$game" size="thumb" class="gs-thumb" loading="eager" />
        <span>{{ $name }}</span>
        {{-- A community game's credit stands wherever its name does (plan "Blockli", P3). --}}
        <x-game-credit :game="$game" :link="false" class="font-normal" />
    </a>
    {{-- On a phone the tabs scroll sideways: the current one is scrolled into the bar's visible part on load. --}}
    <div class="gs-tabs" data-test="context-bar" data-game="{{ $game }}"
         x-data x-init="const tab = $el.querySelector('[aria-current]'); if (tab && $el.scrollWidth > $el.clientWidth) { const gap = tab.getBoundingClientRect().right - $el.getBoundingClientRect().right + 24; if (gap > 0) $el.scrollLeft += gap }">
        @foreach ($tabs as $tab)
            <a href="{{ $tab['href'] }}" @navigate($tab['href']) class="gs-tab" @if ($tab['key'] === $active || ($active === 'ladder' && $tab['key'] === 'leaderboard')) aria-current="page" @endif data-test="ctx-{{ $tab['key'] }}">{{ $tab['label'] }}</a>
        @endforeach
    </div>
    @if ($slot->isNotEmpty() || $mode)
        {{-- The page's one primary action. On a phone (`phoneBar`) it moves to x-game.page's bar on the floor beside the
             chat (GamePhone.dc.html, plan "Canvas and live alignment", P4): here it cut the tabs off at "T…". --}}
        <div @class(["gs-act", "max-lg:hidden" => $phoneBar])>
            {{ $slot }}
            @if ($mode)
                <span class="text-[13px] text-ink-3 max-md:hidden" data-test="game-subnav-mode">{{ $mode }}</span>
            @endif
        </div>
    @endif
</nav>
